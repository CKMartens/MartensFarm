<?php
declare(strict_types=1);
/** @var array $user */

$farms = active_farms();
$farmId = isset($_GET['hof']) ? (int) $_GET['hof'] : (int) ($user['farm_id'] ?: array_key_first($farms) ?: 1);
$showAll = isset($_GET['alle']);

$pens = farm_pens($farmId);
$hidden = 0;
if (!$showAll && setting_int('hide_empty_pens') === 1) {
    $before = count($pens);
    $pens = array_values(array_filter($pens, fn($p) => (int) $p['animals'] > 0));
    $hidden = $before - count($pens);
}
$animals = array_sum(array_column($pens, 'animals'));
$alertCount = array_sum(array_map(fn($p) => count($p['alerts']), $pens));
$mfInfo = mf_info();

render('animals', compact('user', 'farms', 'farmId', 'pens', 'hidden', 'showAll', 'animals', 'alertCount', 'mfInfo') + ['title' => 'Tiere', 'nav' => 'tiere']);
