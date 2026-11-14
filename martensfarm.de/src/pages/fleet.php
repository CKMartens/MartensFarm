<?php
declare(strict_types=1);
/** @var array $user */

$farms = active_farms();
$sgId = current_savegame_id();
$farmId = isset($_GET['hof']) ? (int) $_GET['hof'] : (int) ($user['farm_id'] ?: array_key_first($farms) ?: 1);

$vehicles = q('SELECT * FROM vehicles WHERE removed_at IS NULL AND farm_id = ? AND savegame_id <=> ? ORDER BY category, name', [$farmId, $sgId])->fetchAll();
$removed  = q('SELECT name, price, removed_at FROM vehicles WHERE removed_at IS NOT NULL AND farm_id = ? AND savegame_id <=> ? ORDER BY removed_at DESC LIMIT 10', [$farmId, $sgId])->fetchAll();
$threshold = setting_int('maintenance_threshold_pct') / 100;

// Kategorien aus dem Spiel grob zu Gruppen zusammenfassen
const FLEET_GROUPS = [
    'Traktoren und Selbstfahrer' => ['TRACTORSS', 'TRACTORSM', 'TRACTORSL', 'HARVESTERS', 'FORAGEHARVESTERS', 'FRONTLOADERVEHICLES', 'WHEELLOADERVEHICLES', 'TELELOADERVEHICLES', 'SKIDSTEERVEHICLES', 'CARS', 'TRUCKS'],
    'Ernte und Transport'        => ['CUTTERS', 'CUTTERTRAILERS', 'TRAILERS', 'AUGERWAGONS', 'SLURRYTANKS', 'LOWLOADERS', 'MISC'],
    'Boden, Saat, Pflege'        => ['CULTIVATORS', 'PLOWS', 'DISCHARROWS', 'POWERHARROWS', 'SUBSOILERS', 'SEEDERS', 'PLANTERS', 'FERTILIZERSPREADERS', 'SPRAYERS', 'ROLLERS', 'WEEDERS', 'MANURESPREADERS', 'LIMESPREADERS'],
    'Grünland und Futter'        => ['MOWERS', 'TEDDERS', 'WINDROWERS', 'LOADERWAGONS', 'BALERSROUND', 'BALERSSQUARE', 'BALEWRAPPERS', 'BALELOADERS', 'FORAGEMIXERS', 'SILOCOMPACTION', 'LEVELER'],
];

$grouped = [];
foreach ($vehicles as $v) {
    $group = 'Sonstiges';
    foreach (FLEET_GROUPS as $g => $cats) {
        if (in_array($v['category'], $cats, true)) {
            $group = $g;
            break;
        }
    }
    $grouped[$group][] = $v;
}
$order = array_merge(array_keys(FLEET_GROUPS), ['Sonstiges']);
uksort($grouped, fn($a, $b) => array_search($a, $order, true) <=> array_search($b, $order, true));

$total = array_sum(array_column($vehicles, 'price'));

render('fleet', compact('user', 'farms', 'farmId', 'grouped', 'removed', 'total', 'vehicles', 'threshold') + ['title' => 'Fuhrpark', 'nav' => 'fuhrpark']);
