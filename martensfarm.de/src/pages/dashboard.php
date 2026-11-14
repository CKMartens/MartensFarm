<?php
declare(strict_types=1);
/** @var array $user */

$snap = q('SELECT * FROM server_snapshots ORDER BY id DESC LIMIT 1')->fetch() ?: null;
$lastOk = q('SELECT * FROM server_snapshots WHERE ok = 1 ORDER BY id DESC LIMIT 1')->fetch() ?: null;
$players = $lastOk ? q('SELECT * FROM snapshot_players WHERE snapshot_id = ? ORDER BY name', [$lastOk['id']])->fetchAll() : [];

$farms = q('SELECT * FROM farms ORDER BY farm_id')->fetchAll();

// Geldverlauf des eigenen Hofs (bzw. Hof 1) für die letzten 30 Tage
$farmId = $user['farm_id'] ?: ($farms[0]['farm_id'] ?? 1);
$moneyHistory = q('SELECT fetched_at, money FROM farm_money_history WHERE farm_id = ? AND fetched_at > NOW() - INTERVAL 30 DAY ORDER BY fetched_at', [$farmId])->fetchAll();

$fleet = q('SELECT COUNT(*) AS n, COALESCE(SUM(price),0) AS value FROM vehicles WHERE removed_at IS NULL AND farm_id = ?', [$farmId])->fetch();
$repairs = q('SELECT name, damage FROM vehicles WHERE removed_at IS NULL AND farm_id = ? AND damage >= 0.15 ORDER BY damage DESC', [$farmId])->fetchAll();

// Verkaufstipps: aktuelle Periode vs. Bestpreis
$period = current_period_key();
$tips = [];
foreach (['MILK', 'WHEAT', 'BARLEY', 'OAT', 'CANOLA', 'MAIZE', 'WOODCHIPS'] as $ft) {
    $rows = q('SELECT period, price FROM prices WHERE fill_type = ?', [$ft])->fetchAll(PDO::FETCH_KEY_PAIR);
    if (!$rows) {
        continue;
    }
    $best = array_search(max($rows), $rows, true);
    $tips[] = ['type' => $ft, 'now' => $period ? ($rows[$period] ?? null) : null, 'best' => max($rows), 'best_period' => $best];
}

render('dashboard', compact('user', 'snap', 'lastOk', 'players', 'farms', 'farmId', 'moneyHistory', 'fleet', 'repairs', 'tips', 'period') + ['title' => 'Dashboard', 'nav' => 'dashboard']);
