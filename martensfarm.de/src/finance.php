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

/** Eigene Buchungen darf jeder mit finance.book stornieren, fremde nur mit finance.manage */
function finance_can_cancel(?array $user, array $booking): bool
{
    if ($booking['cancelled_at'] !== null || (int) $booking['savegame_id'] !== (int) current_savegame_id()
        || !finance_can_view($user, (int) $booking['farm_id'])) {
        return false;
    }
    if (can($user, 'finance.manage')) {
        return true;
    }
    return can($user, 'finance.book') && (int) $booking['created_by'] === (int) ($user['id'] ?? 0);
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
        if ($afterDay !== null && $day <= $afterDay) {
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
