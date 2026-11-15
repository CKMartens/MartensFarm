<?php
declare(strict_types=1);

/**
 * Finanzen: Kassenbuch, Abgleich mit dem Kontostand und Buchungsvorschläge.
 *
 * Grundidee:
 *  - Der Kontostand kommt nur beim Server-Save (Export-Mod). Jede Änderung landet mit Spieltag in farm_money_history.
 *  - Buchungen hängen an Savegame, Hof und Spieltag. Abgleich je Spieltag:
 *      Änderung Kontostand − Änderung Kredit − gebucht = nicht zugeordnet
 *  - Die Export-Mod (ab 0.3) zählt jede Geldbewegung im Spiel je Hof, Tag und Art mit (game_finances).
 *    Über finance_stat_map wird daraus ein Vorschlag je Buchungssatz.
 *  - Ereignisse (Fahrzeug neu/weg, Farmland-Besitzer gewechselt) erzeugen Vorschläge in booking_suggestions.
 *
 * Die App bewegt kein Geld im Spiel, sie führt nur Buch.
 */

/** Deutsche Namen der Finanz-Arten, wie LS25 sie unter Finanzen anzeigt */
const FINANCE_STAT_NAMES = [
    'newVehiclesCost'     => 'Neue Fahrzeuge',
    'soldVehicles'        => 'Verkaufte Fahrzeuge',
    'newHandtoolsCost'    => 'Neue Werkzeuge',
    // Echttest 14.11.: Werkzeug verkaufen bringt Geld, kaufen zieht auf dem Dedi nichts ab
    'soldHandtools'       => 'Verkaufte Werkzeuge',
    'newAnimalsCost'      => 'Neue Tiere',
    'soldAnimals'         => 'Verkaufte Tiere',
    'constructionCost'    => 'Baukosten',
    'soldBuildings'       => 'Verkaufte Gebäude',
    'fieldPurchase'       => 'Landkauf',
    'fieldSelling'        => 'Landverkauf',
    'vehicleRunningCost'  => 'Betriebskosten Fahrzeuge',
    'vehicleLeasingCost'  => 'Mietkosten Fahrzeuge',
    'propertyMaintenance' => 'Betriebskosten Gebäude',
    'propertyIncome'      => 'Einnahmen Gebäude',
    'productionCosts'     => 'Produktionskosten',
    'soldWood'            => 'Verkauftes Holz',
    'soldBales'           => 'Verkaufte Ballen',
    'soldWool'            => 'Verkaufte Wolle',
    'soldMilk'            => 'Verkaufte Milch',
    'soldProducts'        => 'Verkaufte Erzeugnisse',
    'purchaseFuel'        => 'Kosten Kraftstoff',
    'purchaseSeeds'       => 'Kosten Saatgut',
    'purchaseFertilizer'  => 'Kosten Dünger',
    'purchaseSaplings'    => 'Kosten Setzlinge',
    'purchaseWater'       => 'Kosten Wasser',
    'harvestIncome'       => 'Einnahmen Ernte',
    'incomeBga'           => 'Einnahmen Biogasanlage',
    'missionIncome'       => 'Auftragseinnahmen',
    'wagePayment'         => 'Lohnzahlungen',
    'other'               => 'Sonstiges',
    'loanInterest'        => 'Bankzinsen',
    'loan'                => 'Darlehenskapital',
    'irrigationUpkeep'    => 'Bewässerung',
    // Kein Posten im Spiel: z. B. Diesel an der Tankstelle kaufen. Taucht in LS25 unter Finanzen nirgends auf.
    'expenses'            => 'Einkauf, ohne Angabe',
];

const SUGGESTION_KINDS = [
    'vehicle_buy'  => ['Fahrzeug gekauft', 'vehicle_buy'],
    'vehicle_sale' => ['Fahrzeug verkauft', 'vehicle_sale'],
    'land_buy'     => ['Land gekauft', 'land_buy'],
    'land_sale'    => ['Land verkauft', 'field_sale'],
    'contract_income' => ['Lohnauftrag erledigt', 'contract'],
    'contract_cost'   => ['Lohnarbeit bezahlen', 'contract_cost'],
];

function stat_name(string $stat): string
{
    return FINANCE_STAT_NAMES[$stat] ?? $stat;
}

function current_game_day(): ?int
{
    $d = setting_get('current_game_day');
    return $d !== null && $d !== '' ? (int) $d : null;
}

// ---------------------------------------------------------------- Rechte

/** Höfe, deren Finanzen der Nutzer sehen darf (hofbezogen, außer mit finance.all_farms) */
function finance_farm_ids(?array $user): array
{
    if (!can($user, 'finance.view')) {
        return [];
    }
    $active = array_map('intval', array_keys(active_farms()));
    if (can($user, 'finance.all_farms')) {
        return $active;
    }
    $own = (int) ($user['farm_id'] ?? 0);
    return $own > 0 && in_array($own, $active, true) ? [$own] : [];
}

function finance_can_view(?array $user, int $farmId): bool
{
    return in_array($farmId, finance_farm_ids($user), true);
}

function finance_can_book(?array $user, int $farmId): bool
{
    return can($user, 'finance.book') && finance_can_view($user, $farmId);
}

/**
 * Eigene Buchungen darf jeder mit finance.book stornieren, fremde nur mit finance.manage.
 * Nicht in abgeschlossenen Monaten und nicht die Korrektur selbst (die geht nur über „Monat wieder öffnen“).
 */
function finance_can_cancel(?array $user, array $booking): bool
{
    if ($booking['cancelled_at'] !== null || (int) $booking['savegame_id'] !== (int) current_savegame_id()
        || !finance_can_view($user, (int) $booking['farm_id']) || $booking['source'] === 'correction'
        || finance_day_locked((int) $booking['savegame_id'], (int) $booking['farm_id'], (int) $booking['game_day'])) {
        return false;
    }
    if (can($user, 'finance.manage')) {
        return true;
    }
    return can($user, 'finance.book') && (int) $booking['created_by'] === (int) ($user['id'] ?? 0);
}

/** Echtes Löschen: nur mit finance.delete, nur im laufenden Savegame, nicht in abgeschlossenen Monaten, keine Korrekturen */
function finance_can_delete(?array $user, array $booking): bool
{
    return can($user, 'finance.delete')
        && (int) $booking['savegame_id'] === (int) current_savegame_id()
        && finance_can_view($user, (int) $booking['farm_id'])
        && $booking['source'] !== 'correction'
        && !finance_day_locked((int) $booking['savegame_id'], (int) $booking['farm_id'], (int) $booking['game_day']);
}

// ---------------------------------------------------------------- Kategorien

/** Alle Buchungssätze: id => Zeile */
function finance_categories(bool $refresh = false): array
{
    static $cats = null;
    if ($cats === null || $refresh) {
        $cats = [];
        foreach (q('SELECT * FROM finance_categories ORDER BY kind, sort_order, name')->fetchAll() as $c) {
            $cats[(int) $c['id']] = $c;
        }
    }
    return $cats;
}

function finance_category_id(string $code): ?int
{
    foreach (finance_categories() as $id => $c) {
        if ($c['code'] === $code) {
            return $id;
        }
    }
    return null;
}

/** Buchungssätze der Monatskorrektur (je Richtung einer). Beim normalen Buchen nicht auswählbar. */
const CORRECTION_CODES = ['income' => 'correction_income', 'expense' => 'correction_expense'];

function finance_is_correction_category(int $categoryId): bool
{
    $code = finance_categories()[$categoryId]['code'] ?? null;
    return $code !== null && in_array($code, CORRECTION_CODES, true);
}

/** Buchungssätze fürs normale Buchen: aktiv und keine Korrektur */
function finance_bookable_categories(): array
{
    return array_filter(finance_categories(), fn($c, $id) => (int) $c['is_active'] === 1 && !finance_is_correction_category((int) $id), ARRAY_FILTER_USE_BOTH);
}

function category_label(?array $cat): string
{
    if (!$cat) {
        return '–';
    }
    return ($cat['kind'] === 'income' ? 'Einnahme' : 'Ausgabe') . ': ' . $cat['name'];
}

/** Zuordnung Spiel-Statistik → Buchungssatz: stat => ['category_id' => ?int, 'is_ignored' => bool] */
function finance_stat_map(bool $refresh = false): array
{
    static $map = null;
    if ($map === null || $refresh) {
        $map = [];
        foreach (q('SELECT * FROM finance_stat_map')->fetchAll() as $r) {
            $map[$r['stat']] = ['category_id' => $r['category_id'] !== null ? (int) $r['category_id'] : null, 'is_ignored' => (int) $r['is_ignored'] === 1];
        }
    }
    return $map;
}

/**
 * Buchungssatz für eine Spiel-Statistik mit Betrag. null = ignorieren.
 * Passt das Vorzeichen nicht zum Buchungssatz (oder keiner ist hinterlegt), wird es „Sonstiges“.
 */
function finance_stat_category(string $stat, float $amount): ?int
{
    $m = finance_stat_map()[$stat] ?? null;
    if ($m !== null && $m['is_ignored']) {
        return null;
    }
    $kind = $amount >= 0 ? 'income' : 'expense';
    $cats = finance_categories();
    if ($m !== null && $m['category_id'] !== null && isset($cats[$m['category_id']]) && $cats[$m['category_id']]['kind'] === $kind) {
        return $m['category_id'];
    }
    return finance_category_id($kind === 'income' ? 'income_other' : 'expense_other');
}

// ---------------------------------------------------------------- Import

/** Was das Spiel zu einer Art gemeldet hat: stat => ['type_id', 'title', 'text'] */
function finance_stat_info(): array
{
    $raw = setting_get('mf_stat_info');
    $info = $raw ? json_decode($raw, true) : null;
    return is_array($info) ? $info : [];
}

/** Geldbewegungen aus der Export-Mod übernehmen (Summen je Sitzung, Hof, Tag und Art) */
function import_game_finances(array $fin, int $savegameId, string $now): void
{
    $session = $fin['session'] !== '' ? $fin['session'] : 'unbekannt';
    foreach ($fin['days'] as $d) {
        if ($d['farm_id'] < 1 || $d['farm_id'] > 255) {
            continue;
        }
        foreach ($d['stats'] as $stat => $v) {
            q('INSERT INTO game_finances (savegame_id, session, farm_id, game_day, stat, amount, tx_count, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
               ON DUPLICATE KEY UPDATE amount = VALUES(amount), tx_count = VALUES(tx_count), updated_at = VALUES(updated_at)',
                [$savegameId, $session, $d['farm_id'], $d['day'], $stat, round($v['amount'], 2), max(0, $v['count']), $now]);
        }
    }
    // Kennung und Titel je Art merken (ab Mod 0.3.1), für die Seite Buchungssätze und die Prüfung
    $info = finance_stat_info();
    foreach ($fin['days'] as $d) {
        foreach ($d['stats'] as $stat => $v) {
            if (($v['type_id'] ?? null) !== null || ($v['title'] ?? null) !== null) {
                $info[$stat] = ['type_id' => $v['type_id'], 'title' => $v['title'], 'text' => $v['text']];
            }
        }
    }
    setting_set('mf_stat_info', json_encode($info));
}

/** Farmland-Besitzer abgleichen. Wechsel → Vorschlag Landkauf (neuer Besitzer) bzw. Landverkauf (alter). */
function import_farmlands(array $farmlands, int $savegameId, string $now, array &$log): void
{
    $known = [];
    foreach (q('SELECT farmland_id, owner_farm_id FROM farmlands WHERE savegame_id = ?', [$savegameId])->fetchAll() as $r) {
        $known[(int) $r['farmland_id']] = (int) $r['owner_farm_id'];
    }
    $first = $known === [];
    $changes = 0;
    $day = current_game_day();
    foreach ($farmlands as $id => $f) {
        $owner = max(0, min(255, $f['owner']));
        q('INSERT INTO farmlands (savegame_id, farmland_id, name, owner_farm_id, area_ha, updated_at) VALUES (?, ?, ?, ?, ?, ?)
           ON DUPLICATE KEY UPDATE name = VALUES(name), owner_farm_id = VALUES(owner_farm_id), area_ha = VALUES(area_ha), updated_at = VALUES(updated_at)',
            [$savegameId, $id, mb_substr($f['name'], 0, 50) ?: null, $owner, $f['area'], $now]);
        if ($first || !isset($known[$id]) || $known[$id] === $owner) {
            continue;
        }
        $area = $f['area'] !== null ? ' (' . fmt_num($f['area'], 2) . ' ha)' : '';
        $title = 'Farmland ' . ($f['name'] !== '' ? $f['name'] : $id) . $area;
        if ($owner > 0) {
            add_suggestion($savegameId, $owner, 'land_buy', 'farmland', (string) $id, $title, null, $day, $now);
        }
        if ($known[$id] > 0) {
            add_suggestion($savegameId, $known[$id], 'land_sale', 'farmland', (string) $id, $title, null, $day, $now);
        }
        $changes++;
    }
    if ($changes) {
        $log[] = "Farmland: {$changes} Besitzerwechsel erkannt.";
    }
}

/** Vorschlag für Kauf/Verkauf eines Fahrzeugs. Geleaste und Auftragsfahrzeuge laufen über die Spiel-Statistik. */
function vehicle_suggestion(string $kind, int $savegameId, int $farmId, string $uniqueId, string $name, ?int $price, string $propertyState, string $now): void
{
    if (in_array(strtoupper($propertyState), ['LEASED', 'MISSION', 'NONE'], true)) {
        return;
    }
    add_suggestion($savegameId, $farmId, $kind, 'vehicle', $uniqueId, $name, $kind === 'vehicle_buy' && $price ? $price : null, current_game_day(), $now);
}

function add_suggestion(int $savegameId, int $farmId, string $kind, string $refType, string $refId, string $title, ?int $amount, ?int $day, string $now): void
{
    if (!farm_is_active($farmId) || !isset(SUGGESTION_KINDS[$kind])) {
        return;
    }
    q('INSERT IGNORE INTO booking_suggestions (savegame_id, farm_id, kind, ref_type, ref_id, title, amount, game_day, detected_at)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)', [$savegameId, $farmId, $kind, $refType, mb_substr($refId, 0, 64), mb_substr($title, 0, 190), $amount, $day, $now]);
}

// ---------------------------------------------------------------- Buchen

/**
 * Buchung anlegen. $amountAbs ist immer positiv, das Vorzeichen kommt aus dem Buchungssatz.
 * Gibt die neue ID zurück.
 */
function finance_book(array $user, int $savegameId, int $farmId, int $categoryId, int $amountAbs, int $gameDay, string $text,
                      string $source = 'manual', ?string $refType = null, ?string $refId = null, ?int $suggestionId = null): int
{
    $cat = finance_categories()[$categoryId] ?? null;
    if (!$cat) {
        throw new InvalidArgumentException('Unbekannter Buchungssatz.');
    }
    $amount = $cat['kind'] === 'income' ? abs($amountAbs) : -abs($amountAbs);
    q('INSERT INTO bookings (savegame_id, farm_id, category_id, amount, game_day, text, source, ref_type, ref_id, suggestion_id, created_by, created_at)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
        $savegameId, $farmId, $categoryId, $amount, $gameDay, mb_substr($text, 0, 255), $source, $refType, $refId, $suggestionId, (int) $user['id'], now(),
    ]);
    $id = (int) db()->lastInsertId();
    if ($suggestionId !== null) {
        q('UPDATE booking_suggestions SET status = \'booked\', booking_id = ? WHERE id = ?', [$id, $suggestionId]);
    }
    return $id;
}

function finance_cancel(array $user, int $bookingId): bool
{
    $b = q('SELECT * FROM bookings WHERE id = ?', [$bookingId])->fetch();
    if (!$b || !finance_can_cancel($user, $b)) {
        return false;
    }
    q('UPDATE bookings SET cancelled_at = ?, cancelled_by = ? WHERE id = ? AND cancelled_at IS NULL', [now(), (int) $user['id'], $bookingId]);
    if ($b['suggestion_id'] !== null) {
        // Vorschlag wieder öffnen, damit er nicht verloren geht
        q('UPDATE booking_suggestions SET status = \'open\', booking_id = NULL WHERE id = ? AND booking_id = ?', [$b['suggestion_id'], $bookingId]);
    }
    return true;
}

/**
 * Buchung endgültig löschen. Im Löschprotokoll bleibt stehen, was es war, wer sie angelegt und wer sie gelöscht hat.
 * Ein verknüpfter Vorschlag wird wieder geöffnet.
 */
function finance_delete(array $user, int $bookingId, string $reason = ''): bool
{
    $b = q('SELECT * FROM bookings WHERE id = ?', [$bookingId])->fetch();
    if (!$b || !finance_can_delete($user, $b)) {
        return false;
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        q('INSERT INTO booking_deletions (booking_id, savegame_id, farm_id, category_id, amount, game_day, text, source, was_cancelled,
                                          created_by, created_at, deleted_by, deleted_at, reason)
           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
            $b['id'], $b['savegame_id'], $b['farm_id'], $b['category_id'], $b['amount'], $b['game_day'], $b['text'], $b['source'],
            $b['cancelled_at'] !== null ? 1 : 0, $b['created_by'], $b['created_at'], (int) $user['id'], now(), mb_substr($reason, 0, 255),
        ]);
        q('UPDATE booking_suggestions SET status = \'open\', booking_id = NULL WHERE booking_id = ?', [$bookingId]);
        q('DELETE FROM bookings WHERE id = ?', [$bookingId]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return true;
}

/** Alle stornierten Buchungen eines Hofs löschen, die der Nutzer löschen darf. Gibt die Anzahl zurück. */
function finance_delete_cancelled(array $user, int $savegameId, int $farmId, string $reason = ''): int
{
    $n = 0;
    foreach (finance_cancelled_deletable($user, $savegameId, $farmId) as $b) {
        if (finance_delete($user, (int) $b['id'], $reason)) {
            $n++;
        }
    }
    return $n;
}

/** Stornierte Buchungen, die der Nutzer löschen darf (nicht in abgeschlossenen Monaten, keine Korrekturen) */
function finance_cancelled_deletable(?array $user, int $savegameId, int $farmId): array
{
    if (!can($user, 'finance.delete')) {
        return [];
    }
    $rows = q('SELECT * FROM bookings WHERE savegame_id = ? AND farm_id = ? AND cancelled_at IS NOT NULL', [$savegameId, $farmId])->fetchAll();
    return array_values(array_filter($rows, fn($b) => finance_can_delete($user, $b)));
}

/** Die letzten Einträge im Löschprotokoll eines Hofs */
function finance_deletions(int $savegameId, int $farmId, int $limit = 20): array
{
    return q('SELECT d.*, cu.nickname AS created_nick, du.nickname AS deleted_nick FROM booking_deletions d
              LEFT JOIN users cu ON cu.id = d.created_by LEFT JOIN users du ON du.id = d.deleted_by
              WHERE d.savegame_id = ? AND d.farm_id = ? ORDER BY d.deleted_at DESC, d.id DESC LIMIT ' . max(1, $limit),
        [$savegameId, $farmId])->fetchAll();
}

// ---------------------------------------------------------------- Monatsabschluss

/**
 * Spielmonat, in dem ein Spieltag liegt: ['start', 'end', 'period', 'label'].
 * Rechnet vom aktuellen Spieltag und Monat aus (Export-Mod), deshalb nur fürs laufende Savegame. null = Kalender unbekannt.
 */
function game_month_of(int $day): ?array
{
    $today = current_game_day();
    $period = current_period_key();
    if ($today === null || $period === null) {
        return null;
    }
    $n = max(1, (int) setting_get('days_per_period', '1'));
    $d = max(1, min($n, (int) setting_get('current_day_in_period', '1')));
    $curStart = $today - ($d - 1);
    $k = (int) floor(($day - $curStart) / $n);
    $start = $curStart + $k * $n;
    $p = PERIOD_ORDER[(((array_search($period, PERIOD_ORDER, true) + $k) % 12) + 12) % 12];
    return ['start' => $start, 'end' => $start + $n - 1, 'period' => $p, 'label' => game_month_label($p, $start, $start + $n - 1)];
}

/** z. B. „Oktober (Tag 31–40)“: mit Spieltagen, weil der Monatsname sich jedes Spieljahr wiederholt */
function game_month_label(string $period, int $start, int $end): string
{
    return period_name($period) . ' (Tag ' . $start . ($end > $start ? '–' . $end : '') . ')';
}

/** Abgeschlossene Monate eines Hofs: month_start => Zeile */
function finance_closes(int $savegameId, int $farmId): array
{
    static $cache = [];
    $key = $savegameId . ':' . $farmId;
    if (!isset($cache[$key])) {
        $cache[$key] = [];
        foreach (q('SELECT c.*, u.nickname AS closed_nick FROM finance_month_closes c LEFT JOIN users u ON u.id = c.closed_by
                    WHERE c.savegame_id = ? AND c.farm_id = ? ORDER BY c.month_start', [$savegameId, $farmId])->fetchAll() as $c) {
            $cache[$key][(int) $c['month_start']] = $c;
        }
    }
    return $cache[$key];   // nach jeder Änderung folgt ein Redirect, der Cache gilt also nur für eine Anfrage
}

/** Liegt der Spieltag in einem abgeschlossenen Monat? Gibt den Abschluss zurück oder null. */
function finance_day_locked(int $savegameId, int $farmId, int $day): ?array
{
    foreach (finance_closes($savegameId, $farmId) as $c) {
        if ($day >= (int) $c['lock_from'] && $day <= (int) $c['lock_to']) {
            return $c;
        }
    }
    return null;
}

/**
 * Monate für den Abschluss, neueste zuerst. Ein Abgleich-Zeitraum zählt zu dem Monat, in dem sein letzter Tag liegt.
 * Status: closed (abgeschlossen), ready (vorbei und ein Save danach da), waiting (vorbei, aber noch kein Save am/nach Monatsende),
 * running (läuft noch).
 */
function finance_months(int $savegameId, int $farmId, array $rec): array
{
    $closes = finance_closes($savegameId, $farmId);
    $months = [];
    $isCurrent = $savegameId === (int) current_savegame_id();
    if ($isCurrent) {
        foreach ($rec['rows'] as $r) {
            $m = game_month_of((int) $r['day']);
            if ($m === null) {
                break;
            }
            $k = $m['start'];
            $months[$k] ??= $m + ['open' => 0, 'rows' => 0, 'lock_from' => (int) $r['from_day'], 'lock_to' => (int) $r['day'], 'close' => null];
            $months[$k]['open'] += (int) $r['open'];
            $months[$k]['rows']++;
            $months[$k]['lock_from'] = min($months[$k]['lock_from'], (int) $r['from_day']);
            $months[$k]['lock_to'] = max($months[$k]['lock_to'], (int) $r['day']);
        }
    }
    foreach ($closes as $start => $c) {
        $months[$start] = [
            'start' => (int) $c['month_start'], 'end' => (int) $c['month_end'], 'period' => $c['period'],
            'label' => game_month_label($c['period'], (int) $c['month_start'], (int) $c['month_end']),
            'open' => (int) ($months[$start]['open'] ?? 0), 'rows' => (int) ($months[$start]['rows'] ?? 0),
            'lock_from' => (int) $c['lock_from'], 'lock_to' => (int) $c['lock_to'], 'close' => $c,
        ];
    }
    $today = $isCurrent ? current_game_day() : null;
    $lastDay = $rec['last_day'] ?? null;
    foreach ($months as &$m) {
        $m['status'] = match (true) {
            $m['close'] !== null                          => 'closed',
            $today !== null && $m['end'] >= $today        => 'running',
            $lastDay === null || $lastDay < $m['end']     => 'waiting',
            default                                       => 'ready',
        };
    }
    unset($m);
    krsort($months);
    return array_values($months);
}

/**
 * Monat abschließen: Bleibt ein Rest, der sich nicht zuordnen lässt, wird er mit einer Korrekturbuchung
 * am letzten Abgleich-Tag des Monats ausgeglichen. Danach sind Buchungen für diese Tage gesperrt.
 * Gibt [ok, Meldung] zurück.
 */
function finance_close_month(array $user, int $savegameId, int $farmId, int $monthStart): array
{
    if (!can($user, 'finance.close') || !finance_can_view($user, $farmId) || $savegameId !== (int) current_savegame_id()) {
        return [false, 'Diesen Monat darfst du nicht abschließen.'];
    }
    $month = null;
    foreach (finance_months($savegameId, $farmId, finance_reconcile($savegameId, $farmId)) as $m) {
        if ($m['start'] === $monthStart) {
            $month = $m;
        }
    }
    if ($month === null || $month['status'] !== 'ready') {
        return [false, 'Der Monat lässt sich (noch) nicht abschließen. Er muss vorbei sein, und danach muss der Server einmal gespeichert haben.'];
    }
    $open = (int) $month['open'];
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $bookingId = null;
        if (abs($open) >= 1) {
            $catId = finance_category_id(CORRECTION_CODES[$open > 0 ? 'income' : 'expense']);
            if ($catId === null) {
                throw new RuntimeException('Buchungssatz „Korrektur“ fehlt, bitte bin/update-db.php ausführen.');
            }
            $bookingId = finance_book($user, $savegameId, $farmId, $catId, abs($open), $month['lock_to'],
                'Monatsabschluss ' . $month['label'] . ': nicht zuordenbarer Rest', 'correction');
        }
        q('INSERT INTO finance_month_closes (savegame_id, farm_id, month_start, month_end, period, lock_from, lock_to, open_amount, booking_id, closed_by, closed_at)
           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
            $savegameId, $farmId, $month['start'], $month['end'], $month['period'], $month['lock_from'], $month['lock_to'], $open, $bookingId, (int) $user['id'], now(),
        ]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return [true, $bookingId !== null
        ? 'Monat ' . $month['label'] . ' abgeschlossen, Korrektur über ' . fmt_money($open) . ' gebucht.'
        : 'Monat ' . $month['label'] . ' abgeschlossen, es war nichts zu korrigieren.'];
}

/** Abschluss zurücknehmen (finance.manage): Korrektur wird storniert, die Tage sind wieder buchbar */
function finance_reopen_month(array $user, int $savegameId, int $farmId, int $closeId): bool
{
    if (!can($user, 'finance.manage') || !finance_can_view($user, $farmId) || $savegameId !== (int) current_savegame_id()) {
        return false;
    }
    $c = q('SELECT * FROM finance_month_closes WHERE id = ? AND savegame_id = ? AND farm_id = ?', [$closeId, $savegameId, $farmId])->fetch();
    if (!$c) {
        return false;
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        if ($c['booking_id'] !== null) {
            q('UPDATE bookings SET cancelled_at = ?, cancelled_by = ? WHERE id = ? AND cancelled_at IS NULL', [now(), (int) $user['id'], $c['booking_id']]);
        }
        q('DELETE FROM finance_month_closes WHERE id = ?', [$closeId]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return true;
}

// ---------------------------------------------------------------- Auswertung

/** Gültige Buchungen je Spieltag: day => Summe */
function bookings_by_day(int $savegameId, int $farmId): array
{
    return array_map('intval', q('SELECT game_day, SUM(amount) FROM bookings WHERE savegame_id = ? AND farm_id = ? AND cancelled_at IS NULL GROUP BY game_day',
        [$savegameId, $farmId])->fetchAll(PDO::FETCH_KEY_PAIR));
}

/** Spiel-Statistik je Tag und Buchungssatz: [day][category_id] => Betrag (ignorierte Arten fehlen) */
function game_finances_by_day(int $savegameId, int $farmId): array
{
    $out = [];
    foreach (q('SELECT game_day, stat, SUM(amount) AS amount FROM game_finances WHERE savegame_id = ? AND farm_id = ? GROUP BY game_day, stat',
        [$savegameId, $farmId])->fetchAll() as $r) {
        $amount = (float) $r['amount'];
        $cat = finance_stat_category((string) $r['stat'], $amount);
        if ($cat !== null) {
            $out[(int) $r['game_day']][$cat] = ($out[(int) $r['game_day']][$cat] ?? 0) + $amount;
        }
    }
    return $out;
}

/** Arten der Spiel-Statistik je Tag und Buchungssatz, für den Buchungstext: [day][category_id] => [stat, …] */
function game_stats_by_day(int $savegameId, int $farmId): array
{
    $out = [];
    foreach (q('SELECT game_day, stat, SUM(amount) AS amount FROM game_finances WHERE savegame_id = ? AND farm_id = ? GROUP BY game_day, stat',
        [$savegameId, $farmId])->fetchAll() as $r) {
        $cat = finance_stat_category((string) $r['stat'], (float) $r['amount']);
        if ($cat !== null) {
            $out[(int) $r['game_day']][$cat][] = stat_name((string) $r['stat']);
        }
    }
    return $out;
}

/**
 * Abgleich je Spieltag. Pro Tag zählt der letzte Kontostand (letzter Save des Tages).
 * Der erste Tag mit Spieltag ist der Startpunkt: Buchungen bis einschließlich dieses Tages zählen nicht mit.
 *
 * Gibt ['start' => ?array, 'rows' => [...neueste zuerst], 'open' => int, 'booked_after' => int] zurück.
 */
function finance_reconcile(int $savegameId, int $farmId): array
{
    $points = q('SELECT game_day, money, loan FROM farm_money_history WHERE savegame_id = ? AND farm_id = ? AND game_day IS NOT NULL ORDER BY fetched_at, id',
        [$savegameId, $farmId])->fetchAll();
    $byDay = [];
    foreach ($points as $p) {
        $byDay[(int) $p['game_day']] = $p;   // letzter Punkt des Tages gewinnt
    }
    ksort($byDay);
    $result = ['start' => null, 'rows' => [], 'open' => 0, 'booked_after' => 0];
    if (!$byDay) {
        return $result;
    }

    $booked = bookings_by_day($savegameId, $farmId);
    $game = game_finances_by_day($savegameId, $farmId);
    $prev = null;
    $prevDay = null;
    foreach ($byDay as $day => $p) {
        if ($prev === null) {
            $result['start'] = ['day' => $day, 'money' => (int) $p['money'], 'loan' => (int) ($p['loan'] ?? 0)];
            $prev = $p;
            $prevDay = $day;
            continue;
        }
        $sumBooked = 0;
        $sumGame = 0.0;
        $hasGame = false;
        for ($d = $prevDay + 1; $d <= $day; $d++) {
            $sumBooked += $booked[$d] ?? 0;
            if (isset($game[$d])) {
                $sumGame += array_sum($game[$d]);
                $hasGame = true;
            }
        }
        $delta = (int) $p['money'] - (int) $prev['money'];
        $loanDelta = (int) ($p['loan'] ?? 0) - (int) ($prev['loan'] ?? 0);
        $open = $delta - $loanDelta - $sumBooked;
        $result['rows'][] = [
            'day' => $day, 'from_day' => $prevDay + 1, 'money' => (int) $p['money'], 'delta' => $delta, 'loan_delta' => $loanDelta,
            'booked' => $sumBooked, 'game' => $hasGame ? (int) round($sumGame) : null, 'open' => $open,
        ];
        $result['open'] += $open;
        $prev = $p;
        $prevDay = $day;
    }
    foreach ($booked as $d => $sum) {
        if ($d > $prevDay) {
            $result['booked_after'] += $sum;   // schon gebucht, aber noch kein Save danach
        }
    }
    $result['last_day'] = $prevDay;
    $result['rows'] = array_reverse($result['rows']);
    return $result;
}

/**
 * Offene Vorschläge aus der Spiel-Statistik: je Tag und Buchungssatz, was laut Spiel passiert ist,
 * abzüglich der Buchungen in diesem Satz an diesem Tag. Nur Tage nach dem Startpunkt.
 */
function finance_game_open(int $savegameId, int $farmId, ?int $afterDay): array
{
    $game = game_finances_by_day($savegameId, $farmId);
    if (!$game) {
        return [];
    }
    $stats = game_stats_by_day($savegameId, $farmId);
    $booked = [];
    foreach (q('SELECT game_day, category_id, SUM(amount) FROM bookings WHERE savegame_id = ? AND farm_id = ? AND cancelled_at IS NULL GROUP BY game_day, category_id',
        [$savegameId, $farmId])->fetchAll(PDO::FETCH_NUM) as [$d, $c, $sum]) {
        $booked[(int) $d][(int) $c] = (int) $sum;
    }
    $cats = finance_categories();
    $out = [];
    foreach ($game as $day => $byCat) {
        if (($afterDay !== null && $day <= $afterDay) || finance_day_locked($savegameId, $farmId, $day)) {
            continue;
        }
        foreach ($byCat as $catId => $amount) {
            $amount = (int) round($amount);
            $b = $booked[$day][$catId] ?? 0;
            $open = $amount - $b;
            if (abs($open) < 1 || !isset($cats[$catId])) {
                continue;
            }
            // Nur anbieten, wenn das Vorzeichen zum Buchungssatz passt (sonst wurde schon zu viel gebucht)
            $fits = ($cats[$catId]['kind'] === 'income') === ($open > 0);
            $out[] = [
                'day' => $day, 'category_id' => $catId, 'game' => $amount, 'booked' => $b, 'open' => $open, 'fits' => $fits,
                'stats' => array_values(array_unique($stats[$day][$catId] ?? [])),
            ];
        }
    }
    usort($out, fn($a, $b) => [$b['day'], $a['category_id']] <=> [$a['day'], $b['category_id']]);
    return $out;
}

/** Offener Betrag für einen Hof (für Dashboard und Übersicht) */
function finance_open_amount(int $savegameId, int $farmId): int
{
    return finance_reconcile($savegameId, $farmId)['open'];
}
