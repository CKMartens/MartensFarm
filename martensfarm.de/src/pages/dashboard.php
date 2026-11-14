<?php
declare(strict_types=1);
/** @var array $user */

$snap = q('SELECT * FROM server_snapshots ORDER BY id DESC LIMIT 1')->fetch() ?: null;
$lastOk = q('SELECT * FROM server_snapshots WHERE ok = 1 ORDER BY id DESC LIMIT 1')->fetch() ?: null;
$players = $lastOk ? q('SELECT * FROM snapshot_players WHERE snapshot_id = ? ORDER BY name', [$lastOk['id']])->fetchAll() : [];

$farms = array_values(array_filter(q('SELECT * FROM farms ORDER BY farm_id')->fetchAll(), fn($f) => farm_is_active((int) $f['farm_id'])));
$sgId = current_savegame_id();

// Geldverlauf des eigenen Hofs (bzw. Hof 1) für die letzten 30 Tage
$farmId = $user['farm_id'] ?: ($farms[0]['farm_id'] ?? 1);
$moneyHistory = q('SELECT fetched_at, money FROM farm_money_history WHERE farm_id = ? AND savegame_id <=> ? AND fetched_at > NOW() - INTERVAL 30 DAY ORDER BY fetched_at', [$farmId, $sgId])->fetchAll();

$fleet = q('SELECT COUNT(*) AS n, COALESCE(SUM(price),0) AS value FROM vehicles WHERE removed_at IS NULL AND farm_id = ? AND savegame_id <=> ?', [$farmId, $sgId])->fetch();
$repairs = farm_repairs((int) $farmId);
$threshold = setting_int('maintenance_threshold_pct');

// Tiere: Ställe mit Hinweisen
$pens = farm_pens((int) $farmId);
$animalCount = array_sum(array_column($pens, 'animals'));
$penAlerts = array_values(array_filter($pens, fn($p) => $p['alerts']));
$mfInfo = mf_info();

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

render('dashboard', compact('user', 'snap', 'lastOk', 'players', 'farms', 'farmId', 'moneyHistory', 'fleet', 'repairs', 'threshold', 'pens', 'animalCount', 'penAlerts', 'mfInfo', 'tips', 'period') + ['title' => 'Dashboard', 'nav' => 'dashboard']);
