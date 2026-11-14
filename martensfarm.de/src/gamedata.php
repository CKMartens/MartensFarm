<?php
declare(strict_types=1);

/**
 * Abruf und Auswertung der Feeds vom LS25 Dedicated Server.
 * Nur lesend. Ein Abruf = 1 Stats-Feed + wenige Savegame-Dateien, mit Timeout und Mindestabstand.
 */

/**
 * Zugangsdaten zum Gameserver. Was unter Admin → Einstellungen steht, gilt vorrangig,
 * sonst die Werte aus config/config.php. So klappt ein Server- oder Anbieterwechsel ohne Dateizugriff.
 */
function gs_base_url(): string
{
    $v = trim((string) setting_get('gs_base_url', ''));
    return rtrim($v !== '' ? $v : (string) config('gameserver.base_url', ''), '/');
}

function gs_code(): string
{
    $v = (string) setting_get('gs_code', '');
    return $v !== '' ? $v : (string) config('gameserver.code', '');
}

/** Woher die aktiven Zugangsdaten kommen: 'settings' oder 'config' */
function gs_source(): string
{
    return trim((string) setting_get('gs_base_url', '')) !== '' || (string) setting_get('gs_code', '') !== '' ? 'settings' : 'config';
}

/** Adresse prüfen und normalisieren: nur http(s)://host[:port], ohne Pfad. Null = ungültig. */
function gs_normalize_base_url(string $url): ?string
{
    $url = rtrim(trim($url), '/');
    $p = parse_url($url);
    if (!$p || !in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true) || empty($p['host'])
        || isset($p['user']) || isset($p['pass']) || isset($p['query']) || isset($p['fragment']) || (($p['path'] ?? '') !== '')) {
        return null;
    }
    return strtolower($p['scheme']) . '://' . $p['host'] . (isset($p['port']) ? ':' . (int) $p['port'] : '');
}

/** Code für die Anzeige: nur die letzten drei Zeichen */
function gs_mask(string $code): string
{
    return $code === '' ? '' : '••••' . mb_substr($code, -3);
}

function gs_fetch(string $path, ?string $baseUrl = null): string
{
    $base = $baseUrl ?? gs_base_url();
    if ($base === '' || str_contains($base, 'IP-DES-GAMESERVERS')) {
        throw new RuntimeException('Keine Gameserver-Adresse eingetragen');
    }
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => (int) config('gameserver.timeout_s', 15),
        CURLOPT_USERAGENT      => 'MartensFarm-Importer/1.0',
        CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_MAXFILESIZE    => 20 * 1024 * 1024,
    ]);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    unset($ch); // curl_close() ist ab PHP 8.5 veraltet
    if ($body === false || $status !== 200) {
        // Fehlermeldungen landen auch auf dem Dashboard: Host/IP nicht mit ausgeben
        $host = (string) parse_url($base, PHP_URL_HOST);
        $err = $host !== '' ? str_replace($host, 'Gameserver', $err) : $err;
        throw new RuntimeException(trim("Abruf fehlgeschlagen ({$status}) {$err}"));
    }
    return (string) $body;
}

/** XML sicher laden (keine externen Entities, kein Netzwerkzugriff) */
function gs_xml(string $body): SimpleXMLElement
{
    // Manche Feeds kommen als HTML-Seite mit XML-Inhalt – ab dem ersten "<?xml" bzw. Wurzelelement schneiden
    $pos = strpos($body, '<?xml');
    if ($pos !== false && $pos > 0) {
        $body = substr($body, $pos);
    }
    $prev = libxml_use_internal_errors(true);
    $xml = simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
    libxml_use_internal_errors($prev);
    if ($xml === false) {
        throw new RuntimeException('Antwort ist kein gültiges XML');
    }
    return $xml;
}

function gs_stats(?string $baseUrl = null, ?string $code = null): SimpleXMLElement
{
    return gs_xml(gs_fetch('/feed/dedicated-server-stats.xml?code=' . rawurlencode($code ?? gs_code()), $baseUrl));
}

function gs_savegame(string $file): SimpleXMLElement
{
    return gs_xml(gs_fetch('/feed/dedicated-server-savegame.html?code=' . rawurlencode(gs_code()) . '&file=' . rawurlencode($file)));
}

/**
 * Kartenbild aus dem Stats-Feed, zwischengespeichert unter var/cache/.
 * Der Dateiname hängt an Server, Code und Savegame: Nach einem Server- oder Mapwechsel
 * wird also automatisch neu geladen. Ist der Server weg, kommt das letzte Bild.
 * Gibt den Pfad zur JPG-Datei zurück oder null, wenn es (noch) keins gibt.
 */
const MAP_CACHE_SECONDS = 6 * 3600;

function gs_map_file(bool $refresh = false): ?string
{
    $dir = APP_ROOT . '/var/cache';
    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
        $dir = sys_get_temp_dir();
    }
    $base = gs_base_url();
    $code = gs_code();
    if ($base === '' || $code === '') {
        return null;
    }
    $file = $dir . '/map-' . substr(hash('sha256', $base . '|' . $code . '|' . (current_savegame_id() ?? 0)), 0, 16) . '.jpg';
    $fresh = is_file($file) && time() - (int) filemtime($file) < MAP_CACHE_SECONDS;
    if ($fresh && !$refresh) {
        return $file;
    }
    try {
        $img = gs_fetch('/feed/dedicated-server-stats-map.jpg?code=' . rawurlencode($code)
            . '&quality=' . (int) config('gameserver.map_quality', 60) . '&size=' . (int) config('gameserver.map_size', 512));
        if (!str_starts_with($img, "\xFF\xD8")) {
            throw new RuntimeException('Antwort ist kein JPG');
        }
        $tmp = $file . '.part';
        if (@file_put_contents($tmp, $img) !== false && @rename($tmp, $file)) {
            foreach (glob($dir . '/map-*.jpg') ?: [] as $old) {
                if ($old !== $file) {
                    @unlink($old);
                }
            }
        }
    } catch (Throwable $e) {
        // Server nicht erreichbar: altes Bild weiter zeigen (ggf. auch das vom alten Savegame)
        if (!is_file($file)) {
            $old = glob($dir . '/map-*.jpg') ?: [];
            return $old ? $old[0] : null;
        }
        @touch($file, time() - MAP_CACHE_SECONDS + 900); // in 15 Min. erneut versuchen
    }
    return is_file($file) ? $file : null;
}

/** Stats-Feed: Serverinfos, Spieler, Fahrzeugnamen */
function parse_stats(SimpleXMLElement $x): array
{
    $players = [];
    foreach ($x->Slots->Player ?? [] as $p) {
        if ((string) $p['isUsed'] === 'true') {
            $players[] = [
                'name'     => trim((string) $p),
                'is_admin' => (string) $p['isAdmin'] === 'true',
                'uptime'   => isset($p['uptime']) ? (int) $p['uptime'] : null,
            ];
        }
    }
    $vehicles = [];
    foreach ($x->Vehicles->Vehicle ?? [] as $v) {
        $vehicles[] = [
            'name'     => (string) $v['name'],
            'category' => (string) $v['category'],
            'x'        => (float) $v['x'],
            'z'        => (float) $v['z'],
            'ai'       => (string) $v['isAIActive'] === 'true',
        ];
    }
    // Farmlands mit Besitzer (owner = farmId, 0 = niemand). Für Landkauf/-verkauf-Vorschläge.
    $farmlands = [];
    foreach ($x->Farmlands->Farmland ?? [] as $f) {
        if (!isset($f['id'])) {
            continue;
        }
        $farmlands[(int) $f['id']] = [
            'name'  => (string) $f['name'],
            'owner' => (int) $f['owner'],
            'area'  => isset($f['area']) ? (float) $f['area'] : null,
        ];
    }
    // Mods, die auf dem Server laufen: <Mods><Mod name="FS25_x" author="…" version="…" hash="…">Titel</Mod></Mods>
    $mods = [];
    foreach ($x->Mods->Mod ?? [] as $m) {
        $name = trim((string) $m['name']);
        if ($name === '' || !mod_name_valid($name)) {
            continue;
        }
        $mods[$name] = [
            'title'   => trim(html_entity_decode((string) $m, ENT_QUOTES | ENT_HTML5, 'UTF-8')),
            'version' => (string) $m['version'],
            'author'  => html_entity_decode((string) $m['author'], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'hash'    => (string) $m['hash'],
        ];
    }
    return [
        'mods'        => $mods,
        'farmlands'   => $farmlands,
        'server_name' => html_entity_decode((string) $x['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        'map_name'    => (string) $x['mapName'],
        'version'     => (string) $x['version'],
        'day_time'    => isset($x['dayTime']) ? (int) $x['dayTime'] : null,
        'capacity'    => isset($x->Slots['capacity']) ? (int) $x->Slots['capacity'] : null,
        'players'     => $players,
        'vehicles'    => $vehicles,
    ];
}

/** vehicles.xml aus dem Savegame */
function parse_vehicles(SimpleXMLElement $x): array
{
    $out = [];
    foreach ($x->vehicle as $v) {
        $comp = $v->component[0] ?? null;
        $pos = $comp ? array_map('floatval', explode(' ', (string) $comp['position'])) : [null, null, null];
        $fills = [];
        foreach ($v->fillUnit->unit ?? [] as $u) {
            $type = (string) $u['fillType'];
            $level = (float) $u['fillLevel'];
            if ($type !== '' && $type !== 'UNKNOWN' && $type !== 'AIR' && $level > 0.5) {
                $fills[$type] = round($level);
            }
        }
        $wearNode = $v->wearable->wearNode[0] ?? null;
        $out[] = [
            'unique_id'      => (string) $v['uniqueId'],
            'filename'       => (string) $v['filename'],
            'farm_id'        => (int) $v['farmId'],
            'property_state' => (string) $v['propertyState'],
            'age'            => (int) round((float) $v['age']),
            'hours'          => round((float) $v['operatingTime'] / 3600, 1),
            'price'          => (int) round((float) $v['price']),
            'damage'         => isset($v->wearable['damage']) ? (float) $v->wearable['damage'] : null,
            'wear'           => $wearNode ? (float) $wearNode['amount'] : null,
            'fills'          => $fills,
            'x'              => $pos[0] ?? null,
            'z'              => $pos[2] ?? null,
        ];
    }
    return $out;
}

/** Fahrzeugname aus dem Dateinamen raten, falls der Stats-Feed keinen liefert */
function vehicle_name_from_file(string $filename): string
{
    $base = basename($filename, '.xml');
    return ucfirst(preg_replace('/(?<!^)([A-Z])/', ' $1', $base));
}

/** economy.xml: Preisverlauf je Ware und Periode */
function parse_economy(SimpleXMLElement $x): array
{
    $out = [];
    foreach ($x->fillTypes->fillType ?? [] as $f) {
        $type = (string) $f['fillType'];
        foreach ($f->history->period ?? [] as $p) {
            $out[$type][(string) $p['period']] = (int) $p;
        }
    }
    return $out;
}

/** farms.xml: Höfe mit Geld */
function parse_farms(SimpleXMLElement $x): array
{
    $out = [];
    foreach ($x->farm as $f) {
        $out[] = [
            'farm_id' => (int) $f['farmId'],
            'name'    => (string) $f['name'],
            'color'   => isset($f['color']) ? (int) $f['color'] : null,
            'money'   => isset($f['money']) ? (int) round((float) $f['money']) : null,
            'loan'    => isset($f['loan']) ? (int) round((float) $f['loan']) : null,
        ];
    }
    return $out;
}

/** environment.xml: aktuelle Spielperiode, Tag */
function parse_environment(SimpleXMLElement $x): array
{
    return [
        'current_day'    => isset($x->currentDay) ? (int) $x->currentDay : null,
        'current_period' => isset($x->currentPeriod) ? (int) $x->currentPeriod : null,
        'days_per_period'=> isset($x->daysPerPeriod) ? (int) $x->daysPerPeriod : null,
    ];
}

const PERIOD_ORDER = ['EARLY_SPRING','MID_SPRING','LATE_SPRING','EARLY_SUMMER','MID_SUMMER','LATE_SUMMER',
                      'EARLY_AUTUMN','MID_AUTUMN','LATE_AUTUMN','EARLY_WINTER','MID_WINTER','LATE_WINTER'];

/**
 * Kompletter Import. Gibt eine Liste von Meldungen zurück.
 * Einzelne Savegame-Dateien dürfen fehlschlagen, ohne den Rest zu blockieren.
 */
function run_import(bool $force = false): array
{
    $log = [];
    $last = setting_get('last_import_at');
    $minGap = (int) config('gameserver.min_interval_s', 240);
    if (!$force && $last && time() - strtotime($last) < $minGap) {
        return ['Übersprungen: letzter Abruf ist jünger als ' . $minGap . ' s.'];
    }
    setting_set('last_import_at', now());
    $now = now();

    // 1) Stats-Feed
    try {
        $stats = parse_stats(gs_stats());
    } catch (Throwable $e) {
        q('INSERT INTO server_snapshots (fetched_at, ok, error) VALUES (?, 0, ?)', [$now, mb_substr($e->getMessage(), 0, 250)]);
        setting_set('last_import_ok', '0');
        return ['Server nicht erreichbar: ' . $e->getMessage()];
    }

    q('INSERT INTO server_snapshots (fetched_at, ok, server_name, map_name, game_version, day_time_ms, players_online, players_capacity)
       VALUES (?, 1, ?, ?, ?, ?, ?, ?)', [
        $now, $stats['server_name'], $stats['map_name'], $stats['version'], $stats['day_time'],
        count($stats['players']), $stats['capacity'],
    ]);
    $snapId = (int) db()->lastInsertId();
    foreach ($stats['players'] as $p) {
        q('INSERT INTO snapshot_players (snapshot_id, name, is_admin, uptime_min) VALUES (?, ?, ?, ?)', [$snapId, $p['name'], (int) $p['is_admin'], $p['uptime']]);
    }
    $log[] = 'Server: ' . $stats['server_name'] . ', ' . count($stats['players']) . ' Spieler online.';
    advance_calendar($stats['day_time']);
    if ($stats['mods']) {
        sync_server_mods($stats['mods'], $now);
    }

    $files = (array) config('gameserver.files', []);

    // 2) Höfe
    $farmsSeen = false;
    if (in_array('farms', $files, true)) {
        try {
            foreach (parse_farms(gs_savegame('farms')) as $f) {
                if ($f['farm_id'] < 1) {
                    continue;
                }
                upsert_farm($f['farm_id'], $f['name'], $f['color'], $f['money'], $f['loan'], $now);
                $farmsSeen = true;
            }
            $log[] = 'Höfe aktualisiert.';
        } catch (Throwable $e) {
            $log[] = 'farms: ' . $e->getMessage();
        }
    }

    // 3) careerSavegame: Savegame erkennen, Block der Export-Mod einlesen, sonst Geld von Hof 1 als Notbehelf
    if (in_array('careerSavegame', $files, true)) {
        try {
            $career = gs_savegame('careerSavegame');
            setting_set('savegame_name', (string) $career->settings->savegameName);
            setting_set('days_per_period', (string) $career->settings->plannedDaysPerPeriod);
            $mf = isset($career->martensFarm) ? parse_mf_export($career->martensFarm) : null;
            $sgId = savegame_detect(
                (string) ($mf['game']['map_id'] ?? '') ?: (string) $career->settings->mapId ?: (string) $stats['map_name'],
                (string) ($mf['game']['map_title'] ?? '') ?: (string) $career->settings->mapTitle,
                (string) $career->settings->savegameName,
                $now,
                $log
            );
            if ($mf !== null) {
                $farmsSeen = import_mf_export($mf, $sgId, $now, $log) || $farmsSeen;
            }
            if (!$farmsSeen && isset($career->statistics->money)) {
                $existing = q('SELECT name FROM farms WHERE farm_id = 1')->fetchColumn();
                upsert_farm(1, $existing ?: (string) $career->settings->savegameName, null, (int) round((float) $career->statistics->money), null, $now);
            }
        } catch (Throwable $e) {
            $log[] = 'careerSavegame: ' . $e->getMessage();
        }
    }
    $sgId = current_savegame_id();

    // Farmland-Besitzer abgleichen (Stats-Feed ist live, Vorschläge erst ab dem zweiten Abruf)
    if ($sgId !== null && $stats['farmlands']) {
        try {
            import_farmlands($stats['farmlands'], $sgId, $now, $log);
        } catch (Throwable $e) {
            $log[] = 'farmlands: ' . $e->getMessage();
        }
    }

    // 4) Fahrzeuge – Namen aus dem Stats-Feed über die Position zuordnen
    if (in_array('vehicles', $files, true)) {
        try {
            $vehicles = parse_vehicles(gs_savegame('vehicles'));
            $names = [];
            foreach ($stats['vehicles'] as $sv) {
                $names[sprintf('%.1f|%.1f', $sv['x'], $sv['z'])] = $sv;
            }
            $count = 0;
            // Für Kauf-/Verkaufsvorschläge: was kannten wir schon? Beim allerersten Import gibt es keine Vorschläge.
            $knownIds = array_flip(q('SELECT unique_id FROM vehicles WHERE savegame_id <=> ?', [$sgId])->fetchAll(PDO::FETCH_COLUMN));
            $firstImport = $knownIds === [];
            foreach ($vehicles as $v) {
                // Nur Spieler-Höfe (1–8). Kartenfahrzeuge (Zug, farmId 0), NPC-Höfe und Paletten überspringen
                if ($v['farm_id'] < 1 || $v['farm_id'] > 8 || $v['unique_id'] === '' || $v['property_state'] === 'NONE'
                    || str_contains($v['filename'], '/objects/')) {
                    continue;
                }
                $key = sprintf('%.1f|%.1f', $v['x'], $v['z']);
                $sv = $names[$key] ?? null;
                q('INSERT INTO vehicles (unique_id, savegame_id, farm_id, name, category, filename, property_state, age_months, operating_hours, price, damage, wear, fills, pos_x, pos_z, is_ai_active, first_seen_at, last_seen_at, removed_at)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL)
                   ON DUPLICATE KEY UPDATE savegame_id = VALUES(savegame_id), farm_id = VALUES(farm_id), name = VALUES(name), category = COALESCE(VALUES(category), category),
                     property_state = VALUES(property_state), age_months = VALUES(age_months), operating_hours = VALUES(operating_hours),
                     price = VALUES(price), damage = VALUES(damage), wear = VALUES(wear), fills = VALUES(fills), pos_x = VALUES(pos_x),
                     pos_z = VALUES(pos_z), is_ai_active = VALUES(is_ai_active), last_seen_at = VALUES(last_seen_at), removed_at = NULL', [
                    $v['unique_id'], $sgId, $v['farm_id'], $sv['name'] ?? vehicle_name_from_file($v['filename']), $sv['category'] ?? null,
                    mb_substr($v['filename'], 0, 255), $v['property_state'], $v['age'], $v['hours'], $v['price'],
                    $v['damage'], $v['wear'], json_encode($v['fills']), $v['x'], $v['z'], (int) ($sv['ai'] ?? false), $now, $now,
                ]);
                ensure_farm_exists($v['farm_id']);
                if (!$firstImport && $sgId !== null && !isset($knownIds[$v['unique_id']])) {
                    vehicle_suggestion('vehicle_buy', $sgId, $v['farm_id'], $v['unique_id'], $sv['name'] ?? vehicle_name_from_file($v['filename']), $v['price'], $v['property_state'], $now);
                }
                $count++;
            }
            // Nicht mehr vorhandene Fahrzeuge → Verkaufsvorschlag (nur wenn die Datei überhaupt Fahrzeuge hatte)
            if ($count > 0 && $sgId !== null) {
                foreach (q('SELECT unique_id, farm_id, name, price, property_state FROM vehicles WHERE savegame_id = ? AND last_seen_at < ? AND removed_at IS NULL', [$sgId, $now])->fetchAll() as $old) {
                    vehicle_suggestion('vehicle_sale', $sgId, (int) $old['farm_id'], $old['unique_id'], $old['name'], null, (string) $old['property_state'], $now);
                }
            }
            // Nicht mehr vorhandene Fahrzeuge als verkauft/entfernt markieren
            q('UPDATE vehicles SET removed_at = ? WHERE savegame_id <=> ? AND last_seen_at < ? AND removed_at IS NULL', [$now, $sgId, $now]);
            $log[] = "Fuhrpark: {$count} Fahrzeuge.";
        } catch (Throwable $e) {
            $log[] = 'vehicles: ' . $e->getMessage();
        }
    }

    // 5) Preise
    if (in_array('economy', $files, true)) {
        try {
            $n = 0;
            foreach (parse_economy(gs_savegame('economy')) as $type => $periods) {
                foreach ($periods as $period => $price) {
                    q('INSERT INTO prices (fill_type, period, price, updated_at) VALUES (?, ?, ?, ?)
                       ON DUPLICATE KEY UPDATE price = VALUES(price), updated_at = VALUES(updated_at)', [$type, $period, $price, $now]);
                    $n++;
                }
            }
            $log[] = "Preise: {$n} Werte.";
        } catch (Throwable $e) {
            $log[] = 'economy: ' . $e->getMessage();
        }
    }

    // 6) Aktuelle Spielperiode
    if (in_array('environment', $files, true)) {
        try {
            $env = parse_environment(gs_savegame('environment'));
            if ($env['current_period'] >= 1 && $env['current_period'] <= 12) {
                setting_set('current_period', PERIOD_ORDER[$env['current_period'] - 1]);
            }
            if ($env['current_day']) {
                setting_set('current_day', (string) $env['current_day']);
            }
        } catch (Throwable $e) {
            $log[] = 'environment: ' . $e->getMessage();
        }
    }

    // Verlauf schlank halten: Snapshots älter als 90 Tage löschen
    q('DELETE FROM server_snapshots WHERE fetched_at < NOW() - INTERVAL 90 DAY');
    setting_set('last_import_ok', '1');
    return $log;
}

function upsert_farm(int $farmId, string $name, ?int $color, ?int $money, ?int $loan, string $now): void
{
    // Vom Admin vergebene Namen (name_locked) bleiben stehen
    q('INSERT INTO farms (farm_id, name, color, money, loan, updated_at) VALUES (?, ?, ?, ?, ?, ?)
       ON DUPLICATE KEY UPDATE name = IF(name_locked = 1, name, VALUES(name)), color = COALESCE(VALUES(color), color), money = COALESCE(VALUES(money), money),
         loan = COALESCE(VALUES(loan), loan), updated_at = VALUES(updated_at)', [$farmId, $name !== '' ? $name : "Hof {$farmId}", $color, $money, $loan, $now]);
    if ($money !== null) {
        // Verlauf: ein Punkt pro Änderung von Geld oder Kredit (passiert nur beim Speichern), mit Spieltag.
        // Daraus rechnet der Finanz-Abgleich, wie viel sich zwischen zwei Saves bewegt hat.
        $sgId = current_savegame_id();
        $day = setting_get('current_game_day');
        $day = $day !== null && $day !== '' ? (int) $day : null;
        $last = q('SELECT money, loan, game_day FROM farm_money_history WHERE farm_id = ? AND savegame_id <=> ? ORDER BY fetched_at DESC, id DESC LIMIT 1', [$farmId, $sgId])->fetch();
        $changed = !$last || (int) $last['money'] !== $money
            || ($loan !== null && (int) ($last['loan'] ?? -1) !== $loan)
            || ($last['game_day'] === null && $day !== null);
        if ($changed) {
            q('INSERT INTO farm_money_history (farm_id, savegame_id, fetched_at, game_day, money, loan) VALUES (?, ?, ?, ?, ?, ?)', [$farmId, $sgId, $now, $day, $money, $loan]);
        }
    }
}

function ensure_farm_exists(int $farmId): void
{
    q('INSERT IGNORE INTO farms (farm_id, name) VALUES (?, ?)', [$farmId, "Hof {$farmId}"]);
}

/**
 * Spielkalender mitzählen: Wenn die Uhrzeit im Spiel kleiner ist als beim letzten Abruf,
 * war Mitternacht → ein Tag weiter. Nach "Tage pro Monat" Tagen kommt der nächste Monat.
 * Startwert setzt ein Hofleiter einmal auf der Preisseite.
 */
function advance_calendar(?int $dayTime): void
{
    if ($dayTime === null) {
        return;
    }
    $last = setting_get('last_day_time');
    setting_set('last_day_time', (string) $dayTime);
    $period = current_period_key();
    if ($last === null || $period === null || $dayTime >= (int) $last) {
        return;
    }
    $daysPerPeriod = max(1, (int) setting_get('days_per_period', '1'));
    $day = (int) setting_get('current_day_in_period', '1') + 1;
    if ($day > $daysPerPeriod) {
        $day = 1;
        $idx = array_search($period, PERIOD_ORDER, true);
        setting_set('current_period', PERIOD_ORDER[($idx + 1) % 12]);
    }
    setting_set('current_day_in_period', (string) $day);
}

/**
 * Aktueller Spielmonat als Schlüssel (EARLY_AUTUMN …).
 * Der Server gibt environment.xml nicht frei, deshalb stellt ein Admin den Monat auf der Preisseite ein.
 */
function current_period_key(): ?string
{
    $p = (string) setting_get('current_period', '');
    return in_array($p, PERIOD_ORDER, true) ? $p : null;
}
