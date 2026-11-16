<?php
declare(strict_types=1);
/** @var array $user */

/** Zahlen-Einstellungen: Name => [Label, min, max, Einheit] */
const NUMBER_SETTINGS = [
    'maintenance_threshold_pct' => ['Werkstatt ab Schaden', 1, 100, '%'],
    'food_min_pct'              => ['Futter unter (alle Sorten zusammen: TMR, Heu, Gras …)', 0, 100, '%'],
    'food_min_days'             => ['… oder Futter reicht nur noch für', 0, 30, 'Tage'],
    'water_min_pct'             => ['Wasser unter', 0, 100, '%'],
    'straw_min_pct'             => ['Stroh unter', 0, 100, '%'],
    'health_min_pct'            => ['Gesundheit der Tiere unter', 0, 100, '%'],
    'product_full_pct'          => ['Milch, Gülle, Mist usw. fast voll ab', 1, 100, '%'],
];

$sgId = current_savegame_id();

// Alle Hof-IDs, die es gibt: aus der Höfe-Tabelle und aus den Ställen/Lagern des aktuellen Savegames
$known = q('SELECT farm_id, name, name_locked, money FROM farms ORDER BY farm_id')->fetchAll();
$byId = [];
foreach ($known as $f) {
    $byId[(int) $f['farm_id']] = $f;
}
foreach (['husbandries', 'storages', 'vehicles'] as $table) {
    foreach (q("SELECT DISTINCT farm_id FROM {$table} WHERE savegame_id <=> ? AND removed_at IS NULL", [$sgId])->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $byId[(int) $id] ??= ['farm_id' => (int) $id, 'name' => 'Hof ' . (int) $id, 'name_locked' => 0, 'money' => null, 'virtual' => true];
    }
}
unset($byId[0]);
ksort($byId);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['section'] ?? '') === 'gameserver') {
    csrf_check();
    $do = (string) ($_POST['do'] ?? 'test');

    if ($do === 'reset') {
        setting_set('gs_base_url', null);
        setting_set('gs_code', null);
        flash('success', 'Gameserver-Werte aus den Einstellungen gelöscht, es gilt wieder config.php.');
        redirect('admin/einstellungen');
    }

    $rawUrl = trim((string) ($_POST['gs_base_url'] ?? ''));
    $base = gs_normalize_base_url($rawUrl);
    $code = trim((string) ($_POST['gs_code'] ?? ''));
    $code = $code !== '' ? $code : gs_code();   // leer = gespeicherten Code behalten
    if ($base === null) {
        flash('error', 'Die Adresse muss so aussehen: http://IP-oder-Name:Port (ohne /feed/… dahinter).');
        redirect('admin/einstellungen');
    }
    if ($code === '' || mb_strlen($code) > 100 || !preg_match('/^[A-Za-z0-9_-]+$/', $code)) {
        flash('error', 'Der Code fehlt oder enthält ungültige Zeichen.');
        redirect('admin/einstellungen');
    }

    // In beiden Fällen einmal testen
    try {
        $st = parse_stats(gs_stats($base, $code));
        $test = ['ok', 'Verbindung klappt: ' . $st['server_name'] . ' · ' . $st['map_name'] . ' · LS25 ' . $st['version'] . '.'];
    } catch (Throwable $e) {
        $test = ['error', 'Verbindung klappt nicht: ' . $e->getMessage()];
    }

    if ($do === 'save') {
        setting_set('gs_base_url', $base);
        setting_set('gs_code', $code);
        flash($test[0] === 'ok' ? 'success' : 'warning', 'Gameserver gespeichert. ' . $test[1]);
    } else {
        flash($test[0] === 'ok' ? 'success' : 'error', 'Test (nicht gespeichert): ' . $test[1]);
    }
    redirect('admin/einstellungen');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $active = array_values(array_filter(array_map('intval', (array) ($_POST['active'] ?? [])), fn($i) => isset($byId[$i])));
    sort($active);
    setting_set('active_farm_ids', implode(',', $active));

    foreach ($byId as $id => $f) {
        $name = trim((string) ($_POST['name'][$id] ?? ''));
        if (mb_strlen($name) > 100) {
            continue;
        }
        if (in_array($id, $active, true)) {
            ensure_farm_exists($id);
        }
        if ($name === '') {
            // Leer = wieder den Namen aus dem Spiel nehmen (kommt mit dem nächsten Save)
            q('UPDATE farms SET name_locked = 0 WHERE farm_id = ?', [$id]);
        } elseif ($name !== (string) $f['name']) {
            q('UPDATE farms SET name = ?, name_locked = 1 WHERE farm_id = ?', [$name, $id]);
        }
    }

    foreach (NUMBER_SETTINGS as $key => [, $min, $max]) {
        if (isset($_POST[$key]) && is_numeric($_POST[$key])) {
            setting_set($key, (string) max($min, min($max, (int) $_POST[$key])));
        }
    }
    setting_set('hide_empty_pens', isset($_POST['hide_empty_pens']) ? '1' : '0');
    // Ställe ohne Gesundheits-Hinweis: nur Schlüssel, die es im aktuellen Savegame gibt, plus die aus anderen Savegames behalten
    $penKeys = q('SELECT pen_key FROM husbandries WHERE savegame_id <=> ?', [$sgId])->fetchAll(PDO::FETCH_COLUMN);
    $keep = array_values(array_diff(pens_no_health(), $penKeys));
    $chosen = array_values(array_intersect($penKeys, array_map('strval', (array) ($_POST['no_health'] ?? []))));
    setting_set('pens_no_health', json_encode(array_values(array_unique(array_merge($keep, $chosen)))));
    foreach (['tasks_auto_repair', 'tasks_auto_animals', 'tasks_review_required', 'tasks_cleanup_done'] as $key) {
        setting_set($key, isset($_POST[$key]) ? '1' : '0');
    }

    flash('success', 'Einstellungen gespeichert.');
    redirect('admin/einstellungen');
}

$activeIds = active_farm_ids();
$counts = [];
foreach (['husbandries' => 'pens', 'vehicles' => 'vehicles'] as $table => $label) {
    foreach (q("SELECT farm_id, COUNT(*) FROM {$table} WHERE savegame_id <=> ? AND removed_at IS NULL GROUP BY farm_id", [$sgId])->fetchAll(PDO::FETCH_KEY_PAIR) as $id => $n) {
        $counts[(int) $id][$label] = (int) $n;
    }
}

$pens = q('SELECT pen_key, farm_id, name, animal_type, animals, health FROM husbandries WHERE savegame_id <=> ? AND removed_at IS NULL ORDER BY farm_id, name', [$sgId])->fetchAll();
$pens = array_values(array_filter($pens, fn($p) => farm_is_active((int) $p['farm_id'])));
$noHealth = pens_no_health();

$savegames = q('SELECT s.*, (SELECT SUM(money) FROM farms_archive a WHERE a.savegame_id = s.id) AS archived_money
                FROM savegames s ORDER BY s.last_seen_at DESC')->fetchAll();
$mfInfo = mf_info();
$gs = ['source' => gs_source(), 'base_url' => gs_base_url(), 'masked' => gs_mask(gs_code())];
$mapName = q('SELECT map_name FROM server_snapshots WHERE ok = 1 ORDER BY id DESC LIMIT 1')->fetchColumn() ?: null;

render('settings', compact('user', 'byId', 'activeIds', 'counts', 'savegames', 'sgId', 'mfInfo', 'gs', 'mapName', 'pens', 'noHealth') + ['title' => 'Einstellungen', 'nav' => 'einstellungen']);
