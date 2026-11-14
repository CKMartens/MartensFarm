<?php
declare(strict_types=1);
/** @var array $user */

$farms = active_farms();
$farmId = isset($_GET['hof']) ? (int) $_GET['hof'] : (int) ($user['farm_id'] ?: array_key_first($farms) ?: 1);

$rows = q("SELECT * FROM storages WHERE savegame_id <=> ? AND farm_id = ? AND removed_at IS NULL AND kind <> 'husbandry' ORDER BY kind, name",
    [current_savegame_id(), $farmId])->fetchAll();

// Nach Art gruppieren und Gesamtbestand je Ware bilden
$grouped = [];
$totals = [];
foreach ($rows as $r) {
    $r['fills'] = json_decode((string) $r['fills'], true) ?: [];
    uasort($r['fills'], fn($a, $b) => $b['level'] <=> $a['level']);
    $grouped[$r['kind']][] = $r;
    foreach ($r['fills'] as $type => $f) {
        $totals[$type] = ($totals[$type] ?? 0) + $f['level'];
    }
}
arsort($totals);
$rank = array_flip(array_keys(STORAGE_KINDS));
uksort($grouped, fn($a, $b) => ($rank[$a] ?? 99) <=> ($rank[$b] ?? 99));
$mfInfo = mf_info();

render('storage', compact('user', 'farms', 'farmId', 'grouped', 'totals', 'mfInfo') + ['title' => 'Lager', 'nav' => 'lager']);
