<?php
declare(strict_types=1);

/**
 * Daten aus der Export-Mod (Block <martensFarm> in careerSavegame.xml), Savegame-Erkennung,
 * Einstellungen mit Standardwerten und die Hinweise für Tiere und Werkstatt.
 */

/** Einstellungen, die der Admin unter Administration ändern kann, mit Standardwerten */
const SETTING_DEFAULTS = [
    'active_farm_ids'           => '1,2,3,4,5,6,7,8',
    'maintenance_threshold_pct' => '20',
    'food_min_days'             => '2',
    'food_min_pct'              => '10',
    'water_min_pct'             => '20',
    'straw_min_pct'             => '10',
    'product_full_pct'          => '90',
    'hide_empty_pens'           => '1',
];

function setting(string $name): string
{
    return (string) setting_get($name, SETTING_DEFAULTS[$name] ?? null);
}

function setting_int(string $name): int
{
    return (int) setting($name);
}

/** Höfe, die in der App zählen (Admin → Einstellungen). Leer = alle. */
function active_farm_ids(): array
{
    static $ids = null;
    if ($ids === null) {
        $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', setting('active_farm_ids'))), fn($i) => $i > 0 && $i < 256)));
    }
    return $ids;
}

function farm_is_active(int $farmId): bool
{
    $ids = active_farm_ids();
    return !$ids || in_array($farmId, $ids, true);
}

/** Aktive Höfe als [farm_id => name] */
function active_farms(): array
{
    $farms = q('SELECT farm_id, name FROM farms ORDER BY farm_id')->fetchAll(PDO::FETCH_KEY_PAIR);
    return array_filter($farms, fn($id) => farm_is_active((int) $id), ARRAY_FILTER_USE_KEY);
}

function current_savegame_id(): ?int
{
    $id = setting_get('current_savegame_id');
    return $id !== null && $id !== '' ? (int) $id : null;
}

/**
 * Savegame anhand Map + Savegame-Name erkennen. Beim Wechsel werden die Kontostände archiviert
 * und savegame-abhängige Werte zurückgesetzt. Fahrzeuge, Ställe, Lager und der Geldverlauf
 * hängen an savegame_id und bleiben erhalten.
 */
function savegame_detect(string $mapId, string $mapTitle, string $savegameName, string $now, array &$log): int
{
    $mapId = mb_substr($mapId !== '' ? $mapId : 'unbekannt', 0, 150);
    $savegameName = mb_substr($savegameName !== '' ? $savegameName : 'unbekannt', 0, 150);
    $row = q('SELECT id FROM savegames WHERE map_id = ? AND savegame_name = ?', [$mapId, $savegameName])->fetch();
    $known = (bool) $row;
    if ($row) {
        $id = (int) $row['id'];
        q('UPDATE savegames SET last_seen_at = ?, map_title = COALESCE(NULLIF(?, \'\'), map_title) WHERE id = ?', [$now, mb_substr($mapTitle, 0, 150), $id]);
    } else {
        q('INSERT INTO savegames (map_id, map_title, savegame_name, first_seen_at, last_seen_at) VALUES (?, ?, ?, ?, ?)',
            [$mapId, $mapTitle !== '' ? mb_substr($mapTitle, 0, 150) : null, $savegameName, $now, $now]);
        $id = (int) db()->lastInsertId();
    }

    $current = current_savegame_id();
    if ($current === $id) {
        return $id;
    }

    if ($current === null) {
        // Erste Erkennung: bisherige Daten gehören zu diesem Savegame
        q('UPDATE vehicles SET savegame_id = ? WHERE savegame_id IS NULL', [$id]);
        q('UPDATE farm_money_history SET savegame_id = ? WHERE savegame_id IS NULL', [$id]);
        $log[] = "Savegame erkannt: {$savegameName} ({$mapId}).";
    } else {
        q('INSERT INTO farms_archive (savegame_id, farm_id, name, money, loan, archived_at)
           SELECT ?, farm_id, name, money, loan, ? FROM farms
           ON DUPLICATE KEY UPDATE name = VALUES(name), money = VALUES(money), loan = VALUES(loan), archived_at = VALUES(archived_at)', [$current, $now]);
        q('UPDATE farms SET money = NULL, loan = NULL, updated_at = NULL, name = IF(name_locked = 1, name, CONCAT(\'Hof \', farm_id))');
        q('DELETE FROM prices');
        foreach (['current_period', 'current_day_in_period', 'last_day_time', 'mf_written_at', 'current_game_day'] as $s) {
            setting_set($s, null);
        }
        $log[] = ($known ? 'Savegame gewechselt' : 'Neues Savegame erkannt') . ": {$savegameName} ({$mapId}). Die Kontostände des alten Spielstands sind archiviert.";
    }
    setting_set('current_savegame_id', (string) $id);
    return $id;
}

/** Füllstände <fill fillType level capacity> als [FILLTYPE => ['level' => .., 'capacity' => ..]] */
function mf_fills(?SimpleXMLElement $node): array
{
    $out = [];
    foreach ($node->fill ?? [] as $f) {
        $type = (string) $f['fillType'];
        if ($type === '') {
            continue;
        }
        $out[$type] = [
            'level'    => round((float) $f['level'], 2),
            'capacity' => isset($f['capacity']) ? round((float) $f['capacity'], 2) : null,
        ];
    }
    return $out;
}

function mf_float(SimpleXMLElement $n, string $attr): ?float
{
    return isset($n[$attr]) && (string) $n[$attr] !== '' ? (float) $n[$attr] : null;
}

/** Block <martensFarm> in ein Array übersetzen (Schema 1 und 2) */
function parse_mf_export(SimpleXMLElement $mf): array
{
    $out = [
        'schema'      => (int) $mf['schema'],
        'mod_version' => (string) $mf['modVersion'],
        'written_at'  => (string) $mf['writtenAt'],
        'game'        => null,
        'environment' => null,
        'farms'       => [],
        'husbandries' => [],
        'storages'    => [],
        'finances'    => null,
        'missing'     => isset($mf->diag) ? array_filter(explode(',', (string) $mf->diag['missing'])) : [],
    ];
    if (isset($mf->game)) {
        $out['game'] = [
            'map_id'        => (string) $mf->game['mapId'],
            'map_title'     => (string) $mf->game['mapTitle'],
            'savegame_name' => (string) $mf->game['savegameName'],
        ];
    }
    if (isset($mf->environment)) {
        $e = $mf->environment;
        $out['environment'] = [
            'day'             => isset($e['day']) ? (int) $e['day'] : null,
            'period'          => isset($e['period']) ? (int) $e['period'] : null,
            'day_in_period'   => isset($e['dayInPeriod']) ? (int) $e['dayInPeriod'] : null,
            'days_per_period' => isset($e['daysPerPeriod']) ? (int) $e['daysPerPeriod'] : null,
        ];
    }
    foreach ($mf->farms->farm ?? [] as $f) {
        $out['farms'][] = [
            'farm_id' => (int) $f['id'],
            'name'    => trim((string) $f['name']),
            'color'   => isset($f['color']) ? (int) $f['color'] : null,
            'money'   => (int) round((float) $f['money']),
            'loan'    => (int) round((float) $f['loan']),
        ];
    }
    $i = 0;
    foreach ($mf->husbandries->husbandry ?? [] as $h) {
        $foods = mf_fills($h->food ?? null);
        $foodTotal = array_sum(array_column($foods, 'level'));
        if (!$foods && isset($h['food'])) {
            $foodTotal = (float) $h['food']; // Schema 1
        }
        $storage = mf_fills($h->storage ?? null);
        foreach (['water' => 'WATER', 'straw' => 'STRAW'] as $attr => $type) {
            if (!isset($storage[$type]) && isset($h[$attr])) {
                $storage[$type] = ['level' => (float) $h[$attr], 'capacity' => null]; // Schema 1
            }
        }
        $out['husbandries'][] = [
            'pen_key'       => mb_substr((string) ($h['key'] ?? '') ?: 'pen' . $i, 0, 80),
            'farm_id'       => (int) $h['farmId'],
            'name'          => (string) $h['name'],
            'animal_type'   => (string) $h['animalType'],
            'animals'       => (int) $h['animals'],
            'max_animals'   => isset($h['maxAnimals']) ? (int) $h['maxAnimals'] : null,
            'productivity'  => mf_float($h, 'productivity'),
            'health'        => mf_float($h, 'health'),
            'food_total'    => round($foodTotal, 2),
            'food_capacity' => mf_float($h, 'foodCapacity'),
            'food_per_day'  => mf_float($h, 'foodPerDay'),
            'water_auto'    => isset($h['waterAuto']) ? ((string) $h['waterAuto'] === 'true') : null,
            'water_per_day' => mf_float($h, 'waterPerDay'),
            'straw_per_day' => mf_float($h, 'strawPerDay'),
            'foods'         => $foods,
            'storage'       => $storage,
        ];
        $i++;
    }
    foreach ($mf->storages->storage ?? [] as $s) {
        $out['storages'][] = [
            'storage_key' => mb_substr((string) $s['key'], 0, 80),
            'farm_id'     => (int) $s['farmId'],
            'name'        => (string) $s['name'],
            'kind'        => (string) $s['kind'] ?: 'other',
            'fills'       => mf_fills($s),
        ];
    }
    // Ab Schema 3: Geldbewegungen je Hof, Spieltag und Art (seit Serverstart, die letzten Tage)
    if (isset($mf->finances)) {
        $days = [];
        foreach ($mf->finances->day ?? [] as $d) {
            $stats = [];
            foreach ($d->stat ?? [] as $st) {
                $name = mb_substr((string) $st['name'], 0, 50);
                if ($name !== '') {
                    $stats[$name] = ['amount' => (float) $st['amount'], 'count' => (int) $st['count']];
                }
            }
            $days[] = ['farm_id' => (int) $d['farmId'], 'day' => (int) $d['day'], 'stats' => $stats];
        }
        $gameStats = [];
        foreach ($mf->finances->gameStats ?? [] as $g) {
            $row = [];
            foreach ($g->attributes() as $k => $v) {
                if ($k !== 'farmId') {
                    $row[(string) $k] = (float) $v;
                }
            }
            $gameStats[(int) $g['farmId']] = $row;
        }
        $out['finances'] = [
            'session'    => mb_substr((string) $mf->finances['session'], 0, 20),
            'days'       => $days,
            'game_stats' => $gameStats,
        ];
    }
    return $out;
}

/**
 * Export-Block in die Datenbank übernehmen. Nur wenn der Block neu ist (anderes writtenAt),
 * denn der Feed ändert sich ohnehin nur beim Speichern.
 * Gibt true zurück, wenn Höfe übernommen wurden.
 */
function import_mf_export(array $mf, int $savegameId, string $now, array &$log): bool
{
    $stamp = $savegameId . '|' . $mf['written_at'];
    if (setting_get('mf_written_at') === $stamp) {
        return $mf['farms'] !== [];
    }

    // Spieltag zuerst merken: Geldverlauf, Buchungen und Vorschläge hängen daran
    if (($mf['environment']['day'] ?? null) !== null) {
        setting_set('current_game_day', (string) $mf['environment']['day']);
    }

    foreach ($mf['farms'] as $f) {
        if ($f['farm_id'] >= 1 && $f['farm_id'] <= 255) {
            upsert_farm($f['farm_id'], $f['name'], $f['color'], $f['money'], $f['loan'], $now);
        }
    }

    foreach ($mf['husbandries'] as $h) {
        if ($h['farm_id'] < 0 || $h['farm_id'] > 255) {
            continue;
        }
        q('INSERT INTO husbandries (savegame_id, pen_key, farm_id, name, animal_type, animals, max_animals, productivity, health,
             food_total, food_capacity, food_per_day, water_auto, water_per_day, straw_per_day, foods, storage, updated_at, removed_at)
           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL)
           ON DUPLICATE KEY UPDATE farm_id = VALUES(farm_id), name = VALUES(name), animal_type = VALUES(animal_type), animals = VALUES(animals),
             max_animals = VALUES(max_animals), productivity = VALUES(productivity), health = VALUES(health), food_total = VALUES(food_total),
             food_capacity = VALUES(food_capacity), food_per_day = VALUES(food_per_day), water_auto = VALUES(water_auto),
             water_per_day = VALUES(water_per_day), straw_per_day = VALUES(straw_per_day), foods = VALUES(foods), storage = VALUES(storage),
             updated_at = VALUES(updated_at), removed_at = NULL', [
            $savegameId, $h['pen_key'], $h['farm_id'], mb_substr($h['name'] ?: 'Stall', 0, 150), mb_substr($h['animal_type'], 0, 50) ?: null,
            max(0, $h['animals']), $h['max_animals'], $h['productivity'], $h['health'], $h['food_total'], $h['food_capacity'],
            $h['food_per_day'], $h['water_auto'] === null ? null : (int) $h['water_auto'], $h['water_per_day'], $h['straw_per_day'],
            json_encode($h['foods']), json_encode($h['storage']), $now,
        ]);
    }
    mark_missing_removed('husbandries', 'pen_key', array_column($mf['husbandries'], 'pen_key'), $savegameId, $now);

    foreach ($mf['storages'] as $s) {
        if ($s['storage_key'] === '' || $s['farm_id'] < 0 || $s['farm_id'] > 255) {
            continue;
        }
        q('INSERT INTO storages (savegame_id, storage_key, farm_id, name, kind, fills, updated_at, removed_at)
           VALUES (?, ?, ?, ?, ?, ?, ?, NULL)
           ON DUPLICATE KEY UPDATE farm_id = VALUES(farm_id), name = VALUES(name), kind = VALUES(kind), fills = VALUES(fills),
             updated_at = VALUES(updated_at), removed_at = NULL', [
            $savegameId, $s['storage_key'], $s['farm_id'], mb_substr($s['name'] ?: 'Lager', 0, 150), mb_substr($s['kind'], 0, 20), json_encode($s['fills']), $now,
        ]);
    }
    if ($mf['schema'] >= 2) {
        mark_missing_removed('storages', 'storage_key', array_column($mf['storages'], 'storage_key'), $savegameId, $now);
    }

    if ($mf['finances'] !== null) {
        import_game_finances($mf['finances'], $savegameId, $now);
    }

    // Spielkalender vom Server übernehmen (ersetzt das manuelle Setzen auf der Preisseite)
    $env = $mf['environment'];
    if ($env && $env['period'] !== null && $env['period'] >= 1 && $env['period'] <= 12) {
        setting_set('current_period', PERIOD_ORDER[$env['period'] - 1]);
        if ($env['days_per_period']) {
            setting_set('days_per_period', (string) $env['days_per_period']);
        }
        if ($env['day_in_period']) {
            setting_set('current_day_in_period', (string) $env['day_in_period']);
        }
    }

    setting_set('mf_written_at', $stamp);
    setting_set('mf_info', json_encode([
        'schema' => $mf['schema'], 'mod_version' => $mf['mod_version'], 'written_at' => $mf['written_at'],
        'imported_at' => $now, 'missing' => array_values($mf['missing']),
    ]));
    $log[] = sprintf('Export-Mod %s: %d Höfe, %d Ställe, %d Lager, %d Finanz-Tage (Stand %s).',
        $mf['mod_version'], count($mf['farms']), count($mf['husbandries']), count($mf['storages']),
        count($mf['finances']['days'] ?? []), $mf['written_at']);
    return $mf['farms'] !== [];
}

/** Alles, was im aktuellen Export fehlt, als entfernt markieren (gezielt über die Schlüssel) */
function mark_missing_removed(string $table, string $keyColumn, array $keys, int $savegameId, string $now): void
{
    $keys = array_values(array_unique(array_map('strval', $keys)));
    $sql = "UPDATE {$table} SET removed_at = ? WHERE savegame_id = ? AND removed_at IS NULL";
    if ($keys) {
        $sql .= " AND {$keyColumn} NOT IN (" . implode(',', array_fill(0, count($keys), '?')) . ')';
    }
    q($sql, array_merge([$now, $savegameId], $keys));
}

/** Infos zum letzten Export (Version, Zeitpunkt, fehlende Teile) */
function mf_info(): ?array
{
    $raw = setting_get('mf_info');
    return $raw ? (json_decode($raw, true) ?: null) : null;
}

// ---------------------------------------------------------------------------------------------
// Hinweise
// ---------------------------------------------------------------------------------------------

/** Was in einem Stall-Lager als Produkt zählt (alles andere ist Futter/Einstreu/Wasser) */
const PEN_INPUTS = ['WATER', 'STRAW'];

function fill_pct(?array $fill): ?float
{
    if (!$fill || empty($fill['capacity']) || $fill['capacity'] <= 0) {
        return null;
    }
    return max(0.0, min(100.0, $fill['level'] / $fill['capacity'] * 100));
}

/**
 * Hinweise für einen Stall. Gibt eine Liste von ['level' => 'warn'|'bad', 'text' => ..] zurück.
 * Leere Ställe haben keine Hinweise.
 */
function pen_alerts(array $pen): array
{
    $alerts = [];
    if ((int) $pen['animals'] <= 0) {
        return $alerts;
    }
    $storage = is_array($pen['storage']) ? $pen['storage'] : (json_decode((string) $pen['storage'], true) ?: []);

    // Futter
    $food = (float) $pen['food_total'];
    $perDay = $pen['food_per_day'] !== null ? (float) $pen['food_per_day'] : 0.0;
    if ($food <= 0.5) {
        $alerts[] = ['level' => 'bad', 'text' => 'Kein Futter mehr'];
    } elseif ($perDay > 0) {
        $days = $food / $perDay;
        if ($days < setting_int('food_min_days')) {
            $alerts[] = ['level' => 'warn', 'text' => 'Futter reicht nur noch ca. ' . fmt_num($days, 1) . ' Tage'];
        }
    } elseif ($pen['food_capacity'] !== null && (float) $pen['food_capacity'] > 0) {
        $pct = $food / (float) $pen['food_capacity'] * 100;
        if ($pct < setting_int('food_min_pct')) {
            $alerts[] = ['level' => 'warn', 'text' => 'Futter bei ' . fmt_num($pct) . ' %'];
        }
    }

    // Wasser (nicht bei automatischer Versorgung)
    if ((int) ($pen['water_auto'] ?? 0) !== 1 && isset($storage['WATER'])) {
        $pct = fill_pct($storage['WATER']);
        if ($storage['WATER']['level'] <= 0.5) {
            $alerts[] = ['level' => 'bad', 'text' => 'Kein Wasser mehr'];
        } elseif ($pct !== null && $pct < setting_int('water_min_pct')) {
            $alerts[] = ['level' => 'warn', 'text' => 'Wasser bei ' . fmt_num($pct) . ' %'];
        }
    }

    // Stroh
    if (isset($storage['STRAW'])) {
        $pct = fill_pct($storage['STRAW']);
        if ($pct !== null && $pct < setting_int('straw_min_pct')) {
            $alerts[] = ['level' => $storage['STRAW']['level'] <= 0.5 ? 'bad' : 'warn', 'text' => 'Stroh bei ' . fmt_num($pct) . ' %'];
        }
    }

    // Produkte fast voll (Milch, Gülle, Mist …)
    foreach ($storage as $type => $fill) {
        if (in_array($type, PEN_INPUTS, true)) {
            continue;
        }
        $pct = fill_pct($fill);
        if ($pct !== null && $pct >= setting_int('product_full_pct')) {
            $alerts[] = ['level' => 'warn', 'text' => filltype_name($type) . ' fast voll (' . fmt_num($pct) . ' %)'];
        }
    }
    return $alerts;
}

/** Ställe eines Hofs im aktuellen Savegame */
function farm_pens(int $farmId): array
{
    $rows = q('SELECT * FROM husbandries WHERE savegame_id <=> ? AND farm_id = ? AND removed_at IS NULL ORDER BY animal_type, name', [current_savegame_id(), $farmId])->fetchAll();
    foreach ($rows as &$r) {
        $r['foods'] = json_decode((string) $r['foods'], true) ?: [];
        $r['storage'] = json_decode((string) $r['storage'], true) ?: [];
        $r['alerts'] = pen_alerts($r);
    }
    return $rows;
}

/** Fahrzeuge über der Wartungsschwelle */
function farm_repairs(int $farmId): array
{
    return q('SELECT name, damage FROM vehicles WHERE removed_at IS NULL AND farm_id = ? AND savegame_id <=> ? AND damage >= ? ORDER BY damage DESC',
        [$farmId, current_savegame_id(), setting_int('maintenance_threshold_pct') / 100])->fetchAll();
}

const ANIMAL_NAMES = [
    'COW' => 'Kühe', 'PIG' => 'Schweine', 'SHEEP' => 'Schafe', 'GOAT' => 'Ziegen', 'HORSE' => 'Pferde',
    'CHICKEN' => 'Hühner', 'BUFFALO' => 'Wasserbüffel', 'WATERBUFFALO' => 'Wasserbüffel', 'DUCK' => 'Enten', 'GEESE' => 'Gänse',
];

function animal_name(?string $type): string
{
    if (!$type) {
        return 'Tiere';
    }
    return ANIMAL_NAMES[$type] ?? ucfirst(strtolower(str_replace('_', ' ', $type)));
}

const STORAGE_KINDS = ['silo' => 'Silos und Lager', 'production' => 'Produktionen', 'manure' => 'Mist und Gülle', 'other' => 'Sonstige Lager', 'husbandry' => 'Stall-Lager'];
