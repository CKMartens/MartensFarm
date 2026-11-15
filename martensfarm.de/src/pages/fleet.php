<?php
declare(strict_types=1);
/** @var array $user */

$farms = active_farms();
$sgId = current_savegame_id();
$farmId = isset($_GET['hof']) ? (int) $_GET['hof'] : (int) ($user['farm_id'] ?: array_key_first($farms) ?: 1);
$threshold = setting_int('maintenance_threshold_pct') / 100;

// Filter (alles per GET, damit man Links teilen kann)
$filter = [
    'q'      => mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 50),
    'gruppe' => (string) ($_GET['gruppe'] ?? ''),
    'besitz' => (string) ($_GET['besitz'] ?? ''),
    'schaden' => isset($_GET['schaden']) && $_GET['schaden'] === '1',
];
if ($filter['gruppe'] !== '' && !isset(FLEET_GROUPS[$filter['gruppe']]) && $filter['gruppe'] !== FLEET_OTHER) {
    $filter['gruppe'] = '';
}
if ($filter['besitz'] !== '' && !isset(PROPERTY_STATES[$filter['besitz']])) {
    $filter['besitz'] = '';
}

$all = q('SELECT * FROM vehicles WHERE removed_at IS NULL AND farm_id = ? AND savegame_id <=> ? ORDER BY category, name', [$farmId, $sgId])->fetchAll();
$vehicles = array_values(array_filter($all, function (array $v) use ($filter, $threshold): bool {
    if ($filter['q'] !== '' && mb_stripos($v['name'], $filter['q']) === false) {
        return false;
    }
    if ($filter['gruppe'] !== '' && fleet_group($v['category']) !== $filter['gruppe']) {
        return false;
    }
    if ($filter['besitz'] !== '' && $v['property_state'] !== $filter['besitz']) {
        return false;
    }
    if ($filter['schaden'] && ($v['damage'] === null || (float) $v['damage'] < $threshold)) {
        return false;
    }
    return true;
}));
$filtered = count($vehicles) !== count($all);

$grouped = [];
foreach ($vehicles as $v) {
    $grouped[fleet_group($v['category'])][] = $v;
}
$order = array_merge(array_keys(FLEET_GROUPS), [FLEET_OTHER]);
uksort($grouped, fn($a, $b) => array_search($a, $order, true) <=> array_search($b, $order, true));

$removed = q('SELECT unique_id, name, price, removed_at FROM vehicles WHERE removed_at IS NOT NULL AND farm_id = ? AND savegame_id <=> ? ORDER BY removed_at DESC LIMIT 10', [$farmId, $sgId])->fetchAll();
$total = array_sum(array_column($vehicles, 'price'));
$costs = $sgId !== null && finance_can_view($user, $farmId) ? vehicle_costs($user, $sgId, array_column($all, 'unique_id')) : null;
$openTasks = q('SELECT ref_id, COUNT(*) FROM tasks WHERE savegame_id <=> ? AND ref_type = \'vehicle\' AND status NOT IN (\'done\', \'dropped\') GROUP BY ref_id', [$sgId])->fetchAll(PDO::FETCH_KEY_PAIR);
$hasMap = gs_base_url() !== '' && gs_code() !== '';   // Kartenbild kommt über /karte.jpg

render('fleet', compact('user', 'farms', 'farmId', 'grouped', 'removed', 'total', 'vehicles', 'all', 'threshold', 'filter', 'filtered', 'costs', 'openTasks', 'hasMap')
    + ['title' => 'Fuhrpark', 'nav' => 'fuhrpark']);
