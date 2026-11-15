<?php
/** @var array $user @var array $vehicle @var int $farmId @var bool $canFinance @var array $bookings @var ?array $costs @var array $openSuggestions
 *  @var array $tasks @var float $threshold @var ?array $point @var bool $hasMap @var array $cats @var array $names */
$v = $vehicle;
$d = $v['damage'] !== null ? (float) $v['damage'] : null;
$w = $v['wear'] !== null ? (float) $v['wear'] : null;
$damageClass = fn(?float $x) => $x === null ? '' : ($x >= min(1, $threshold + 0.1) ? 'bad' : ($x >= $threshold ? 'warn' : 'ok'));
$sign = fn(int $x): string => ($x > 0 ? '+' : '') . fmt_money($x);
$fills = json_decode((string) $v['fills'], true) ?: [];
$uidParam = rawurlencode((string) $v['unique_id']);
$canBook = $canFinance && $v['removed_at'] === null && finance_can_book($user, $farmId);
?>
<header class="page-head">
    <div>
        <p class="muted small"><a href="<?= e(url('fuhrpark?hof=' . $farmId)) ?>">← Fuhrpark</a></p>
        <h1><?= e($v['name']) ?></h1>
        <p class="muted">
            <?= e(farm_name($farmId)) ?> · <?= e(fleet_group($v['category'])) ?>
            <?= $v['property_state'] !== 'OWNED' ? ' <span class="badge">' . e(property_label($v['property_state'])) . '</span>' : '' ?>
            <?= $v['is_ai_active'] ? ' <span class="badge ok">Helfer aktiv</span>' : '' ?>
            <?= $v['removed_at'] !== null ? ' <span class="badge bad">verkauft/entfernt ' . e(fmt_datetime($v['removed_at'])) . '</span>' : '' ?>
        </p>
    </div>
    <div class="btn-row">
        <?php if ($canBook): ?><a class="btn btn-ghost" href="<?= e(url('finanzen/buchen?fahrzeug=' . $uidParam)) ?>">+ Kosten buchen</a><?php endif; ?>
        <?php if (can($user, 'tasks.create') && $v['removed_at'] === null): ?><a class="btn btn-ghost" href="<?= e(url('aufgaben/neu?fahrzeug=' . $uidParam)) ?>">+ Aufgabe</a><?php endif; ?>
    </div>
</header>

<section class="tiles">
    <div class="tile">
        <div class="tile-label">Schaden</div>
        <div class="tile-value <?= $damageClass($d) ?>"><?= $d !== null ? e(fmt_num($d * 100)) . ' %' : '–' ?></div>
        <div class="tile-sub">Werkstatt ab <?= (int) round($threshold * 100) ?> %<?= $w !== null ? ' · Abnutzung (Lack) ' . e(fmt_num($w * 100)) . ' %' : '' ?></div>
    </div>
    <div class="tile">
        <div class="tile-label">Betriebsstunden</div>
        <div class="tile-value"><?= e(fmt_num((float) $v['operating_hours'], 1)) ?> h</div>
        <div class="tile-sub">Alter <?= (int) $v['age_months'] ?> Monate</div>
    </div>
    <div class="tile">
        <div class="tile-label">Kaufpreis</div>
        <div class="tile-value money"><?= e(fmt_money((int) $v['price'])) ?></div>
        <div class="tile-sub">laut Spiel (Listenpreis inkl. Konfiguration)</div>
    </div>
    <?php if ($costs !== null): ?>
        <div class="tile">
            <div class="tile-label">Gebucht für das Fahrzeug</div>
            <div class="tile-value <?= $costs['income'] + $costs['expense'] < 0 ? 'bad' : 'ok' ?>"><?= e($sign($costs['income'] + $costs['expense'])) ?></div>
            <div class="tile-sub"><?= (int) $costs['n'] ?> Buchung(en) · Ausgaben <?= e(fmt_money(abs($costs['expense']))) ?> · Einnahmen <?= e(fmt_money($costs['income'])) ?></div>
        </div>
    <?php endif; ?>
</section>

<div class="grid-2">
    <section class="card">
        <h2>Aufgaben</h2>
        <?php if ($tasks): ?>
            <ul class="plain">
                <?php foreach ($tasks as $t): ?>
                    <li class="row">
                        <span><a href="<?= e(url('aufgaben/aufgabe?id=' . (int) $t['id'])) ?>"><?= e($t['title']) ?></a>
                            <?= $t['auto_key'] !== null ? '<span class="badge">🤖</span>' : '' ?></span>
                        <span class="muted small"><span class="badge<?= $t['status'] === 'done' ? ' ok' : '' ?>"><?= e(TASK_STATUSES[$t['status']] ?? $t['status']) ?></span>
                            <?= $t['assignee_id'] !== null ? e($names[(int) $t['assignee_id']] ?? '?') : '' ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <p class="muted">Keine Aufgaben zu diesem Fahrzeug. Werkstatt-Aufgaben legt die App ab <?= (int) round($threshold * 100) ?> % Schaden selbst an.</p>
        <?php endif; ?>

        <?php if ($fills): ?>
            <h2 class="spaced">Füllstände</h2>
            <p class="small">
                <?php foreach ($fills as $type => $amount): ?>
                    <span class="fill"><?= e(filltype_name($type)) ?>: <?= e(fmt_num($amount)) ?><?= $type === 'ELECTRICCHARGE' ? ' %' : ' l' ?></span>
                <?php endforeach; ?>
            </p>
        <?php endif; ?>
        <p class="muted small spaced">Zuerst gesehen <?= e(fmt_datetime($v['first_seen_at'])) ?> · zuletzt <?= e(fmt_datetime($v['last_seen_at'])) ?></p>
    </section>

    <section class="card">
        <h2>Position</h2>
        <?php if ($hasMap && $point): ?>
            <svg class="fleet-map" viewBox="0 0 1000 1000" role="img" aria-label="Position von <?= e($v['name']) ?>">
                <image href="<?= e(url('karte.jpg')) ?>" x="0" y="0" width="1000" height="1000"/>
                <circle class="map-ring" cx="<?= $point[0] ?>" cy="<?= $point[1] ?>" r="28"/>
                <circle class="map-dot <?= $damageClass($d) ?>" cx="<?= $point[0] ?>" cy="<?= $point[1] ?>" r="11"><title><?= e($v['name']) ?></title></circle>
            </svg>
            <p class="muted small">Stand letzter Save.</p>
        <?php else: ?>
            <p class="muted">Keine Position bekannt.</p>
        <?php endif; ?>
    </section>
</div>

<?php if ($canFinance): ?>
    <section class="card">
        <h2>Buchungen</h2>
        <?php if ($openSuggestions): ?>
            <p class="alert alert-info small">Offener Vorschlag:
                <?php foreach ($openSuggestions as $s): ?>
                    <a href="<?= e(url('finanzen/buchen?vorschlag=' . (int) $s['id'])) ?>"><?= e(SUGGESTION_KINDS[$s['kind']][0] ?? $s['kind']) ?><?= $s['amount'] !== null ? ' · ' . e(fmt_money((int) $s['amount'])) : '' ?></a>
                <?php endforeach; ?>
            </p>
        <?php endif; ?>
        <?php if ($bookings): ?>
            <div class="table-wrap">
            <table class="table compact">
                <thead><tr><th>Tag</th><th>Buchungssatz</th><th>Text</th><th class="num">Betrag</th><th>Von</th></tr></thead>
                <tbody>
                <?php foreach ($bookings as $b): $cancelled = $b['cancelled_at'] !== null; ?>
                    <tr<?= $cancelled ? ' class="inactive"' : '' ?>>
                        <td><?= (int) $b['game_day'] ?></td>
                        <td><?= e($cats[(int) $b['category_id']]['name'] ?? '?') ?><?= $cancelled ? ' <span class="badge">storniert</span>' : '' ?></td>
                        <td><?= e($b['text']) ?></td>
                        <td class="num <?= $cancelled ? '' : ((int) $b['amount'] < 0 ? 'bad' : 'ok') ?>"><?= e($sign((int) $b['amount'])) ?></td>
                        <td><?= e($b['nickname'] ?? '–') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        <?php else: ?>
            <p class="muted">Noch nichts auf dieses Fahrzeug gebucht. Kauf und Verkauf kommen als Vorschlag, Reparaturen o. Ä. über „Kosten buchen“.</p>
        <?php endif; ?>
    </section>
<?php endif; ?>

<?php $boardRefType = 'vehicle'; $boardRefId = (string) $vehicle['unique_id']; require __DIR__ . '/board_refs.php'; ?>
