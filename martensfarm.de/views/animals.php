<?php
/** @var array $farms @var int $farmId @var array $pens @var int $hidden @var bool $showAll @var int $animals @var int $alertCount @var ?array $mfInfo */
$pct = fn(?array $fill) => fill_pct($fill);
$fillCell = function (?array $fill) use ($pct): string {
    if (!$fill) {
        return '–';
    }
    $p = $pct($fill);
    return e(fmt_num($fill['level'])) . ' l' . ($p !== null ? ' <span class="muted small">(' . e(fmt_num($p)) . ' %)</span>' : '');
};
?>
<header class="page-head">
    <div>
        <h1>Tiere</h1>
        <p class="muted"><?= (int) $animals ?> Tiere in <?= count($pens) ?> Ställen<?= $mfInfo ? ' · Stand: letzter Save, ' . e(fmt_datetime($mfInfo['written_at'])) : '' ?></p>
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

<?php if (!$pens && !$hidden): ?>
    <div class="card"><p class="muted">Keine Stalldaten für diesen Hof. Dafür muss die Export-Mod <code>FS25_MartensFarmExport</code> auf dem Server laufen und einmal gespeichert worden sein.</p></div>
<?php else: ?>
    <section class="tiles">
        <div class="tile"><div class="tile-label">Tiere</div><div class="tile-value"><?= (int) $animals ?></div><div class="tile-sub"><?= count($pens) ?> Ställe</div></div>
        <div class="tile"><div class="tile-label">Hinweise</div><div class="tile-value <?= $alertCount ? 'warn' : 'ok' ?>"><?= (int) $alertCount ?></div><div class="tile-sub"><?= $alertCount ? 'bitte kümmern' : 'alles versorgt' ?></div></div>
    </section>

    <section class="card">
        <div class="table-wrap">
        <table class="table pens">
            <thead>
            <tr><th>Stall</th><th class="num">Tiere</th><th class="num">Produktivität</th><th>Futter</th><th>Wasser</th><th>Stroh</th><th>Produkte</th><th>Hinweise</th></tr>
            </thead>
            <tbody>
            <?php foreach ($pens as $p):
                $storage = $p['storage'];
                $food = (float) $p['food_total'];
                $perDay = $p['food_per_day'] !== null ? (float) $p['food_per_day'] : 0;
                $products = array_filter($storage, fn($t) => !in_array($t, PEN_INPUTS, true), ARRAY_FILTER_USE_KEY);
            ?>
                <tr class="<?= (int) $p['animals'] === 0 ? 'inactive' : '' ?>">
                    <td><strong><?= e($p['name']) ?></strong><br><span class="muted small"><?= e(animal_name($p['animal_type'])) ?></span></td>
                    <td class="num"><?= (int) $p['animals'] ?><?= $p['max_animals'] !== null ? ' / ' . (int) $p['max_animals'] : '' ?></td>
                    <td class="num"><?= $p['productivity'] !== null ? e(fmt_num((float) $p['productivity'] * 100)) . ' %' : '–' ?>
                        <?php if ($p['health'] !== null): ?><br><span class="muted small">Gesundheit <?= e(fmt_num((float) $p['health'])) ?> %</span><?php endif; ?></td>
                    <td><?= e(fmt_num($food)) ?> l
                        <?php if ($perDay > 0): ?><br><span class="muted small">ca. <?= e(fmt_num($food / $perDay, 1)) ?> Tage</span>
                        <?php elseif ($p['food_capacity']): ?><br><span class="muted small"><?= e(fmt_num($food / (float) $p['food_capacity'] * 100)) ?> %</span><?php endif; ?>
                        <?php if (count($p['foods']) > 1): ?><br><span class="small"><?php foreach ($p['foods'] as $t => $f): if ($f['level'] > 0.5): ?><span class="fill"><?= e(filltype_name($t)) ?>: <?= e(fmt_num($f['level'])) ?></span><?php endif; endforeach; ?></span><?php endif; ?>
                    </td>
                    <td><?= (int) ($p['water_auto'] ?? 0) === 1 ? '<span class="badge ok">automatisch</span>' : $fillCell($storage['WATER'] ?? null) ?></td>
                    <td><?= $fillCell($storage['STRAW'] ?? null) ?></td>
                    <td class="small">
                        <?php foreach ($products as $t => $f): ?>
                            <span class="fill"><?= e(filltype_name($t)) ?>: <?= e(fmt_num($f['level'])) ?><?= ($x = $pct($f)) !== null ? ' (' . e(fmt_num($x)) . ' %)' : '' ?></span>
                        <?php endforeach; ?>
                        <?= $products ? '' : '–' ?>
                    </td>
                    <td><?php foreach ($p['alerts'] as $a): ?><span class="badge <?= e($a['level']) ?>"><?= e($a['text']) ?></span> <?php endforeach; ?><?= $p['alerts'] ? '' : '<span class="muted">–</span>' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php if ($hidden): ?>
            <p class="muted small"><?= (int) $hidden ?> <?= $hidden === 1 ? 'leerer Stall' : 'leere Ställe' ?> ausgeblendet · <a href="<?= e(url('tiere?hof=' . $farmId . '&alle=1')) ?>">alle zeigen</a></p>
        <?php elseif ($showAll): ?>
            <p class="muted small"><a href="<?= e(url('tiere?hof=' . $farmId)) ?>">Leere Ställe ausblenden</a></p>
        <?php endif; ?>
    </section>
<?php endif; ?>
