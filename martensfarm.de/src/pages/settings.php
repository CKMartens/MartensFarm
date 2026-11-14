<?php
declare(strict_types=1);
/** @var array $user */

/** Zahlen-Einstellungen: Name => [Label, min, max, Einheit] */
const NUMBER_SETTINGS = [
    'maintenance_threshold_pct' => ['Werkstatt ab Schaden', 1, 100, '%'],
    'food_min_days'             => ['Hinweis, wenn Futter nur noch reicht für', 0, 30, 'Tage'],
    'food_min_pct'              => ['… oder (wenn der Verbrauch unbekannt ist) Futter unter', 0, 100, '%'],
    'water_min_pct'             => ['Wasser unter', 0, 100, '%'],
    'straw_min_pct'             => ['Stroh unter', 0, 100, '%'],
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

$savegames = q('SELECT s.*, (SELECT SUM(money) FROM farms_archive a WHERE a.savegame_id = s.id) AS archived_money
                FROM savegames s ORDER BY s.last_seen_at DESC')->fetchAll();
$mfInfo = mf_info();

render('settings', compact('user', 'byId', 'activeIds', 'counts', 'savegames', 'sgId', 'mfInfo') + ['title' => 'Einstellungen', 'nav' => 'einstellungen']);
