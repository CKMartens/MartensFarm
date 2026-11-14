<?php
/** @var array $farms @var int $farmId @var array $grouped @var array $totals @var ?array $mfInfo */
?>
<header class="page-head">
    <div>
        <h1>Lager</h1>
        <p class="muted">Silos, Lager und Produktionen<?= $mfInfo ? ' · Stand: letzter Save, ' . e(fmt_datetime($mfInfo['written_at'])) : '' ?></p>
    </div>
    <?php if (count($farms) > 1): ?>
        <form method="get" class="inline">
            <select name="hof" aria-label="Hof">
                <?php foreach ($farms as $id => $name): ?>
                    <option value="<?= (int) $id ?>"<?= (int) $id === $farmId ? ' selected' : '' ?>><?= e($name) ?></option>
                <?php endforeach; ?>
            </select>
            <button class="btn btn-ghost" type="submit">Anzeigen</button>
        </form>
    <?php endif; ?>
</header>

<?php if (!$grouped): ?>
    <div class="card"><p class="muted">Keine Lagerdaten für diesen Hof. Dafür braucht es die Export-Mod ab Version 0.2 auf dem Server und einen Save.</p></div>
<?php else: ?>
    <div class="grid-2">
        <section class="card">
            <h2>Gesamtbestand</h2>
            <table class="table compact">
                <thead><tr><th>Ware</th><th class="num">Menge</th></tr></thead>
                <tbody>
                <?php foreach ($totals as $type => $level): if ($level < 1) continue; ?>
                    <tr><td><?= e(filltype_name($type)) ?></td><td class="num"><?= e(fmt_num($level)) ?> l</td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </section>

        <?php foreach ($grouped as $kind => $list): ?>
            <section class="card">
                <h2><?= e(STORAGE_KINDS[$kind] ?? $kind) ?> <span class="muted small">(<?= count($list) ?>)</span></h2>
                <ul class="plain">
                    <?php foreach ($list as $s): ?>
                        <li><strong><?= e($s['name']) ?></strong><br>
                            <?php foreach ($s['fills'] as $type => $f): if ($f['level'] < 1) continue; $p = fill_pct($f); ?>
                                <span class="fill small"><?= e(filltype_name($type)) ?>: <?= e(fmt_num($f['level'])) ?> l<?= $p !== null ? ' (' . e(fmt_num($p)) . ' %)' : '' ?></span>
                            <?php endforeach; ?>
                            <?= array_filter($s['fills'], fn($f) => $f['level'] >= 1) ? '' : '<span class="muted small">leer</span>' ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
