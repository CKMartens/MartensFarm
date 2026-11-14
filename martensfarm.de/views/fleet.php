<?php
/** @var array $farms @var int $farmId @var array $grouped @var array $removed @var int $total @var array $vehicles */
$damageClass = fn(?float $d) => $d === null ? '' : ($d >= 0.30 ? 'bad' : ($d >= 0.15 ? 'warn' : 'ok'));
?>
<header class="page-head">
    <div>
        <h1>Fuhrpark</h1>
        <p class="muted"><?= count($vehicles) ?> Fahrzeuge und Geräte · Kaufwert <?= e(fmt_money($total)) ?></p>
    </div>
    <?php if (count($farms) > 1): ?>
        <form method="get" class="inline">
            <select name="hof">
                <?php foreach ($farms as $id => $name): ?>
                    <option value="<?= (int) $id ?>"<?= (int) $id === $farmId ? ' selected' : '' ?>><?= e($name) ?></option>
                <?php endforeach; ?>
            </select>
            <button class="btn btn-ghost" type="submit">Anzeigen</button>
        </form>
    <?php endif; ?>
</header>

<?php if (!$vehicles): ?>
    <div class="card"><p class="muted">Noch keine Fahrzeuge importiert.</p></div>
<?php endif; ?>

<?php foreach ($grouped as $group => $list): ?>
    <section class="card">
        <h2><?= e($group) ?> <span class="muted small">(<?= count($list) ?>)</span></h2>
        <div class="table-wrap">
        <table class="table fleet">
            <thead>
            <tr><th>Fahrzeug</th><th class="num">Kaufpreis</th><th class="num">Stunden</th><th class="num">Alter</th><th>Schaden</th><th>Füllstände</th></tr>
            </thead>
            <tbody>
            <?php foreach ($list as $v):
                $d = $v['damage'] !== null ? (float) $v['damage'] : null;
                $fills = json_decode((string) $v['fills'], true) ?: [];
            ?>
                <tr>
                    <td>
                        <strong><?= e($v['name']) ?></strong><?= $v['is_ai_active'] ? ' <span class="badge ok">Helfer aktiv</span>' : '' ?>
                        <?= $v['property_state'] !== 'OWNED' ? ' <span class="badge">' . e($v['property_state'] === 'LEASED' ? 'Geleast' : ($v['property_state'] === 'MISSION' ? 'Auftrag' : $v['property_state'])) . '</span>' : '' ?>
                    </td>
                    <td class="num"><?= e(fmt_money((int) $v['price'])) ?></td>
                    <td class="num"><?= e(fmt_num((float) $v['operating_hours'], 1)) ?> h</td>
                    <td class="num"><?= (int) $v['age_months'] ?> Mon.</td>
                    <td>
                        <?php if ($d !== null): ?>
                            <progress class="bar <?= $damageClass($d) ?>" max="100" value="<?= max(2, min(100, round($d * 100))) ?>"></progress>
                            <span class="small <?= $damageClass($d) ?>"><?= e(fmt_num($d * 100)) ?> %</span>
                        <?php else: ?>–<?php endif; ?>
                    </td>
                    <td class="small">
                        <?php foreach ($fills as $type => $amount): ?>
                            <span class="fill"><?= e(filltype_name($type)) ?>: <?= e(fmt_num($amount)) ?><?= $type === 'ELECTRICCHARGE' ? ' %' : ' l' ?></span>
                        <?php endforeach; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </section>
<?php endforeach; ?>

<?php if ($removed): ?>
    <section class="card">
        <h2>Zuletzt verkauft oder entfernt</h2>
        <ul class="plain">
            <?php foreach ($removed as $r): ?>
                <li><?= e($r['name']) ?> <span class="muted small">· <?= e(fmt_money((int) $r['price'])) ?> · <?= e(fmt_datetime($r['removed_at'])) ?></span></li>
            <?php endforeach; ?>
        </ul>
    </section>
<?php endif; ?>
