<?php
/** @var array $user @var array $farms @var int $farmId @var array $grouped @var array $removed @var int $total @var array $vehicles @var array $all @var float $threshold
 *  @var array $filter @var bool $filtered @var ?array $costs @var array $openTasks @var bool $hasMap */
$damageClass = fn(?float $d) => $d === null ? '' : ($d >= min(1, $threshold + 0.1) ? 'bad' : ($d >= $threshold ? 'warn' : 'ok'));
$vurl = fn(array $v): string => url('fuhrpark/fahrzeug?id=' . rawurlencode((string) $v['unique_id']));
$points = [];
foreach ($vehicles as $v) {
    if ($p = map_point($v['pos_x'] !== null ? (float) $v['pos_x'] : null, $v['pos_z'] !== null ? (float) $v['pos_z'] : null)) {
        $points[] = [$v, $p];
    }
}
?>
<header class="page-head">
    <div>
        <h1>Fuhrpark</h1>
        <p class="muted"><?= count($vehicles) ?><?= $filtered ? ' von ' . count($all) : '' ?> Fahrzeuge und Geräte · Kaufwert <?= e(fmt_money($total)) ?></p>
    </div>
</header>

<form method="get" action="<?= e(url('fuhrpark')) ?>" class="card filter-bar">
    <?php if (count($farms) > 1): ?>
        <select name="hof" aria-label="Hof">
            <?php foreach ($farms as $id => $name): ?>
                <option value="<?= (int) $id ?>"<?= (int) $id === $farmId ? ' selected' : '' ?>><?= e($name) ?></option>
            <?php endforeach; ?>
        </select>
    <?php else: ?>
        <input type="hidden" name="hof" value="<?= (int) $farmId ?>">
    <?php endif; ?>
    <input type="search" name="q" value="<?= e($filter['q']) ?>" placeholder="Name suchen" aria-label="Name suchen">
    <select name="gruppe" aria-label="Gruppe">
        <option value="">Alle Gruppen</option>
        <?php foreach (array_merge(array_keys(FLEET_GROUPS), [FLEET_OTHER]) as $g): ?>
            <option value="<?= e($g) ?>"<?= $filter['gruppe'] === $g ? ' selected' : '' ?>><?= e($g) ?></option>
        <?php endforeach; ?>
    </select>
    <select name="besitz" aria-label="Besitz">
        <option value="">Eigen, geleast, Auftrag</option>
        <?php foreach (PROPERTY_STATES as $k => $label): ?>
            <option value="<?= e($k) ?>"<?= $filter['besitz'] === $k ? ' selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
    </select>
    <label class="check-label"><input class="check" type="checkbox" name="schaden" value="1"<?= $filter['schaden'] ? ' checked' : '' ?>> nur Werkstatt fällig</label>
    <button class="btn btn-ghost btn-sm" type="submit">Filtern</button>
    <?php if ($filtered): ?><a class="btn btn-ghost btn-sm" href="<?= e(url('fuhrpark?hof=' . $farmId)) ?>">Zurücksetzen</a><?php endif; ?>
</form>

<?php if (!$all): ?>
    <div class="card"><p class="muted">Noch keine Fahrzeuge importiert.</p></div>
<?php elseif (!$vehicles): ?>
    <div class="card"><p class="muted">Kein Fahrzeug passt zum Filter.</p></div>
<?php endif; ?>

<?php if ($hasMap && $points): ?>
    <section class="card">
        <details>
            <summary><h2>Karte <span class="muted small">(<?= count($points) ?> Fahrzeuge, Stand letzter Save)</span></h2></summary>
            <svg class="fleet-map" viewBox="0 0 1000 1000" role="img" aria-label="Fahrzeuge auf der Karte">
                <image href="<?= e(url('karte.jpg')) ?>" x="0" y="0" width="1000" height="1000"/>
                <?php foreach ($points as [$v, [$px, $py]]): $d = $v['damage'] !== null ? (float) $v['damage'] : null; ?>
                    <a href="<?= e($vurl($v)) ?>">
                        <circle class="map-dot <?= $damageClass($d) ?>" cx="<?= $px ?>" cy="<?= $py ?>" r="9"><title><?= e($v['name']) ?></title></circle>
                    </a>
                <?php endforeach; ?>
            </svg>
            <p class="muted small">Punkt = Fahrzeug, Farbe = Schaden. Antippen öffnet das Fahrzeug. Die Position kommt mit dem Save, unterwegs ist sie also nicht live.</p>
        </details>
    </section>
<?php endif; ?>

<?php foreach ($grouped as $group => $list): ?>
    <section class="card">
        <h2><?= e($group) ?> <span class="muted small">(<?= count($list) ?>)</span></h2>
        <div class="table-wrap">
        <table class="table fleet">
            <thead>
            <tr><th>Fahrzeug</th><th class="num">Kaufpreis</th><?= $costs !== null ? '<th class="num">Gebucht</th>' : '' ?><th class="num">Stunden</th><th class="num">Alter</th><th>Schaden</th><th>Füllstände</th></tr>
            </thead>
            <tbody>
            <?php foreach ($list as $v):
                $d = $v['damage'] !== null ? (float) $v['damage'] : null;
                $fills = json_decode((string) $v['fills'], true) ?: [];
                $c = $costs[$v['unique_id']] ?? null;
                $nt = (int) ($openTasks[$v['unique_id']] ?? 0);
            ?>
                <tr>
                    <td>
                        <a href="<?= e($vurl($v)) ?>"><strong><?= e($v['name']) ?></strong></a><?= $v['is_ai_active'] ? ' <span class="badge ok">Helfer aktiv</span>' : '' ?>
                        <?= $v['property_state'] !== 'OWNED' ? ' <span class="badge">' . e(property_label($v['property_state'])) . '</span>' : '' ?>
                        <?= $nt ? ' <span class="badge warn" title="Offene Aufgaben">☑ ' . $nt . '</span>' : '' ?>
                    </td>
                    <td class="num"><?= e(fmt_money((int) $v['price'])) ?></td>
                    <?php if ($costs !== null): ?>
                        <td class="num"><?= $c ? '<span class="' . ($c['income'] + $c['expense'] < 0 ? 'bad' : 'ok') . '">' . e(fmt_money($c['income'] + $c['expense'])) . '</span>' : '<span class="muted">–</span>' ?></td>
                    <?php endif; ?>
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

<?php if ($costs !== null && $vehicles): ?>
    <p class="muted small">Gebucht = Summe der Buchungen mit Bezug auf das Fahrzeug (Kauf, Verkauf, Reparatur …). Den Bezug setzt man beim Buchen unter „Fahrzeug“.</p>
<?php endif; ?>

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
