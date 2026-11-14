<?php
/** @var array $user @var ?array $snap @var ?array $lastOk @var array $players @var array $farms @var array $moneyHistory @var array $fleet @var array $repairs @var int $threshold @var array $pens @var int $animalCount @var array $penAlerts @var ?array $mfInfo @var array $tips */
$online = $snap && (int) $snap['ok'] === 1 && strtotime($snap['fetched_at']) > time() - 900;
$myFarm = null;
foreach ($farms as $f) {
    if ((int) $f['farm_id'] === (int) $farmId) {
        $myFarm = $f;
    }
}

/** Einfache Linie als SVG – ohne JavaScript, CSP-freundlich */
$spark = '';
if (count($moneyHistory) >= 2) {
    $vals = array_map(fn($r) => (int) $r['money'], $moneyHistory);
    $min = min($vals); $max = max($vals); $range = max(1, $max - $min);
    $w = 600; $h = 120; $n = count($vals);
    $pts = [];
    foreach ($vals as $i => $v) {
        $pts[] = round($i / ($n - 1) * $w, 1) . ',' . round($h - 8 - ($v - $min) / $range * ($h - 16), 1);
    }
    $spark = '<svg class="spark" viewBox="0 0 ' . $w . ' ' . $h . '" preserveAspectRatio="none" role="img" aria-label="Kontostand der letzten 30 Tage"><polyline points="' . implode(' ', $pts) . '"/></svg>';
}
?>
<header class="page-head">
    <div>
        <h1>Moin, <?= e($user['nickname']) ?>! 👋</h1>
        <p class="muted"><?= e($lastOk['server_name'] ?? 'Server') ?> · <?= e($lastOk['map_name'] ?? '') ?></p>
    </div>
    <?php if (has_role($user, 'admin')): ?>
        <form method="post" action="<?= e(url('admin/import')) ?>">
            <?= csrf_field() ?>
            <button class="btn btn-ghost" type="submit">Jetzt abrufen</button>
        </form>
    <?php endif; ?>
</header>

<div class="alert alert-info small">
    Kontostände, Fahrzeuge, Tiere und Lager kommen aus dem Spielstand und werden nur aktualisiert, wenn der Server speichert.
    Sie können deshalb bis zu 60 Minuten hinterherhinken.<?php if ($mfInfo): ?> Letzter Save: <strong><?= e(fmt_datetime($mfInfo['written_at'])) ?></strong> (<?= e(fmt_ago($mfInfo['written_at'])) ?>).<?php endif; ?>
    Live sind nur Server-Status, Spieler und Uhrzeit.
</div>

<section class="tiles">
    <div class="tile">
        <div class="tile-label">Server</div>
        <div class="tile-value <?= $online ? 'ok' : 'bad' ?>"><?= $online ? 'Online' : 'Keine Daten' ?></div>
        <div class="tile-sub">Abruf <?= e(fmt_ago($snap['fetched_at'] ?? null)) ?><?= $snap && !$snap['ok'] ? ' · ' . e($snap['error']) : '' ?></div>
    </div>
    <div class="tile">
        <div class="tile-label">Spieler online</div>
        <div class="tile-value"><?= (int) ($lastOk['players_online'] ?? 0) ?> / <?= (int) ($lastOk['players_capacity'] ?? 0) ?></div>
        <div class="tile-sub">Uhrzeit im Spiel: <?= e(fmt_daytime(isset($lastOk['day_time_ms']) ? (int) $lastOk['day_time_ms'] : null)) ?></div>
    </div>
    <div class="tile">
        <div class="tile-label">Kontostand <?= e($myFarm['name'] ?? '') ?></div>
        <div class="tile-value money"><?= e(fmt_money($myFarm['money'] ?? null)) ?></div>
        <div class="tile-sub"><?= ($myFarm['loan'] ?? 0) > 0 ? 'Kredit: ' . e(fmt_money($myFarm['loan'])) : 'Kein Kredit' ?></div>
    </div>
    <div class="tile">
        <div class="tile-label">Fuhrpark</div>
        <div class="tile-value"><?= (int) $fleet['n'] ?> <small>Fahrzeuge</small></div>
        <div class="tile-sub">Kaufwert <?= e(fmt_money((int) $fleet['value'])) ?></div>
    </div>
</section>

<div class="grid-2">
    <section class="card">
        <h2>Kontostand – letzte 30 Tage</h2>
        <?= $spark ?: '<p class="muted">Noch zu wenig Daten. Der Verlauf füllt sich mit jedem stündlichen Abruf.</p>' ?>
    </section>

    <section class="card">
        <h2>Gerade auf dem Hof</h2>
        <?php if ($players): ?>
            <ul class="plain">
                <?php foreach ($players as $p): ?>
                    <li><span class="dot ok"></span><?= e($p['name']) ?><?= $p['is_admin'] ? ' <span class="badge">Admin</span>' : '' ?><span class="muted small"> · seit <?= (int) $p['uptime_min'] ?> Min.</span></li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <p class="muted">Niemand online.</p>
        <?php endif; ?>
    </section>

    <section class="card">
        <h2>Werkstatt fällig</h2>
        <?php if ($repairs): ?>
            <ul class="plain">
                <?php foreach ($repairs as $r): $d = (float) $r['damage']; ?>
                    <li><span class="badge <?= $d >= min(1, $threshold / 100 + 0.1) ? 'bad' : 'warn' ?>"><?= e(fmt_num($d * 100)) ?> %</span> <?= e($r['name']) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <p class="muted">Alles im grünen Bereich – kein Fahrzeug über <?= (int) $threshold ?> % Schaden.</p>
        <?php endif; ?>
        <p><a href="<?= e(url('fuhrpark')) ?>">Zum Fuhrpark →</a></p>
    </section>

    <section class="card">
        <h2>Tiere</h2>
        <?php if (!$pens): ?>
            <p class="muted">Keine Stalldaten. Dafür muss die Export-Mod auf dem Server laufen und einmal gespeichert worden sein.</p>
        <?php elseif ($penAlerts): ?>
            <ul class="plain">
                <?php foreach ($penAlerts as $p): ?>
                    <li><strong><?= e($p['name']) ?></strong> <span class="muted small">(<?= e(animal_name($p['animal_type'])) ?>)</span><br>
                        <?php foreach ($p['alerts'] as $a): ?><span class="badge <?= e($a['level']) ?>"><?= e($a['text']) ?></span> <?php endforeach; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <p class="muted">Alles versorgt: <?= (int) $animalCount ?> Tiere in <?= count(array_filter($pens, fn($p) => $p['animals'] > 0)) ?> Ställen.</p>
        <?php endif; ?>
        <?php if ($mfInfo): ?><p class="muted small">Stand: letzter Save, <?= e(fmt_datetime($mfInfo['written_at'])) ?></p><?php endif; ?>
        <p><a href="<?= e(url('tiere')) ?>">Zu den Tieren →</a></p>
    </section>

    <section class="card">
        <h2>Verkaufstipps</h2>
        <?php if ($tips): ?>
            <table class="table compact">
                <thead><tr><th>Ware</th><th class="num">Jetzt</th><th class="num">Bestpreis</th><th>Wann</th></tr></thead>
                <tbody>
                <?php foreach ($tips as $t): $good = $t['now'] !== null && $t['now'] >= $t['best'] * 0.95; ?>
                    <tr>
                        <td><?= e(filltype_name($t['type'])) ?></td>
                        <td class="num<?= $good ? ' ok' : '' ?>"><?= $t['now'] !== null ? e(fmt_num($t['now'])) . ' €' : '–' ?></td>
                        <td class="num"><?= e(fmt_num($t['best'])) ?> €</td>
                        <td><?= e(period_name((string) $t['best_period'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p class="muted small">Preise pro 1.000 Liter. Grün = gerade nah am Jahreshoch.</p>
        <?php else: ?>
            <p class="muted">Noch keine Preisdaten.</p>
        <?php endif; ?>
    </section>
</div>
