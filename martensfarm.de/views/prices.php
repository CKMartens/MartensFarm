<?php
/** @var array $list @var ?string $period */
?>
<header class="page-head">
    <div>
        <h1>Preise auf dem Server</h1>
        <p class="muted">Pro 1.000 Liter, aus dem Spielstand<?= $period ? ' · aktueller Spielmonat: ' . e(period_name($period)) : '' ?></p>
    </div>
    <?php if (has_role($user, 'hofleiter')): ?>
        <form method="post" action="<?= e(url('preise')) ?>" class="inline">
            <?= csrf_field() ?>
            <select name="period" aria-label="Aktueller Spielmonat">
                <option value="">Spielmonat wählen …</option>
                <?php foreach (PERIOD_ORDER as $p): ?>
                    <option value="<?= e($p) ?>"<?= $p === $period ? ' selected' : '' ?>><?= e(period_name($p)) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="day" aria-label="Tag im Monat">
                <?php $dpp = max(1, (int) setting_get('days_per_period', '1')); $cur = (int) setting_get('current_day_in_period', '1');
                for ($d = 1; $d <= $dpp; $d++): ?>
                    <option value="<?= $d ?>"<?= $d === $cur ? ' selected' : '' ?>>Tag <?= $d ?></option>
                <?php endfor; ?>
            </select>
            <button class="btn btn-ghost" type="submit">Setzen</button>
        </form>
    <?php endif; ?>
</header>
<?php if (!$period): ?>
    <div class="alert alert-info">Der Server verrät den aktuellen Spielmonat nicht. Stell Monat und Tag oben rechts einmal ein – danach zählt die Seite selbst mit (jede Mitternacht im Spiel ein Tag weiter).</div>
<?php endif; ?>

<section class="card">
    <div class="table-wrap">
    <table class="table">
        <thead>
        <tr><th>Ware</th><th class="num">Jetzt</th><th class="num">Tiefster</th><th class="num">Höchster</th><th>Bester Monat</th><th>Schlechtester Monat</th></tr>
        </thead>
        <tbody>
        <?php foreach ($list as $r):
            $good = $r['now'] !== null && $r['now'] >= $r['max'] * 0.95;
            $badNow = $r['now'] !== null && $r['now'] <= $r['min'] * 1.05;
        ?>
            <tr>
                <td><strong><?= e(filltype_name($r['type'])) ?></strong></td>
                <td class="num<?= $good ? ' ok' : ($badNow ? ' bad' : '') ?>"><?= $r['now'] !== null ? e(fmt_num($r['now'])) . ' €' : '–' ?></td>
                <td class="num"><?= e(fmt_num($r['min'])) ?> €</td>
                <td class="num"><?= e(fmt_num($r['max'])) ?> €</td>
                <td><?= e(period_name((string) $r['max_period'])) ?></td>
                <td class="muted"><?= e(period_name((string) $r['min_period'])) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <p class="muted small">Grün = gerade nah am Jahreshoch, also guter Verkaufszeitpunkt. Rot = nah am Jahrestief, lieber warten.</p>
</section>
