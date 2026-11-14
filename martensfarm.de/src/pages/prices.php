<?php
declare(strict_types=1);
/** @var array $user */

$period = current_period_key();
$rows = q('SELECT fill_type, period, price FROM prices')->fetchAll();
$table = [];
foreach ($rows as $r) {
    $table[$r['fill_type']][$r['period']] = (int) $r['price'];
}

// Nur Waren mit echtem Preis zeigen, Wichtiges zuerst
$priority = ['MILK', 'WHEAT', 'BARLEY', 'OAT', 'CANOLA', 'MAIZE', 'SORGHUM', 'SOYBEAN', 'SUNFLOWER', 'POTATO', 'SUGARBEET', 'WOODCHIPS', 'SILAGE', 'DRYGRASS_WINDROW', 'STRAW', 'GRASS_WINDROW', 'CHAFF', 'MANURE', 'LIQUIDMANURE'];
$list = [];
foreach ($table as $type => $periods) {
    if (max($periods) <= 0) {
        continue;
    }
    $min = min($periods);
    $max = max($periods);
    $list[] = [
        'type'        => $type,
        'now'         => $period ? ($periods[$period] ?? null) : null,
        'min'         => $min,
        'max'         => $max,
        'min_period'  => array_search($min, $periods, true),
        'max_period'  => array_search($max, $periods, true),
        'periods'     => $periods,
        'prio'        => ($i = array_search($type, $priority, true)) === false ? 999 : $i,
    ];
}
usort($list, fn($a, $b) => [$a['prio'], filltype_name($a['type'])] <=> [$b['prio'], filltype_name($b['type'])]);

render('prices', compact('user', 'list', 'period') + ['title' => 'Preise', 'nav' => 'preise']);
