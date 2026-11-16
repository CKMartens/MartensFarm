<?php
/** @var array $user @var array $contracts @var bool $all @var bool $archived @var array $suggestions */
$badge = ['open' => '', 'accepted' => 'warn', 'done' => 'ok', 'cancelled' => 'bad'];
?>
<header class="page-head">
    <div>
        <h1>Lohnaufträge<?= $archived ? ' · Archiv' : '' ?></h1>
        <p class="muted">Arbeit für andere Höfe oder Externe: Auftrag anlegen, Aufgaben daraus machen, nach Abschluss die Einnahme buchen.</p>
    </div>
    <div class="btn-row">
        <?php if ($archived): ?>
            <a class="btn btn-ghost" href="<?= e(url('lohnauftraege')) ?>">← Zurück zur Liste</a>
        <?php else: ?>
            <a class="btn btn-ghost" href="<?= e(url('lohnauftraege' . ($all ? '' : '?alle=1'))) ?>"><?= $all ? 'Nur aktuelle' : 'Alle anzeigen' ?></a>
            <a class="btn btn-ghost" href="<?= e(url('lohnauftraege?archiv=1')) ?>">🗄 Archiv</a>
        <?php endif; ?>
        <?php if (can($user, 'contracts.manage')): ?>
            <a class="btn btn-primary" href="<?= e(url('lohnauftraege/neu')) ?>">+ Neuer Lohnauftrag</a>
        <?php endif; ?>
    </div>
</header>

<section class="card">
    <div class="table-wrap">
        <table class="table">
            <thead>
            <tr><th>Leistung</th><th>Auftraggeber</th><th>Hof</th><th class="num">Preis</th><th>Fällig</th><th>Aufgaben</th><th>Status</th></tr>
            </thead>
            <tbody>
            <?php foreach ($contracts as $c): $s = $suggestions[(int) $c['id']] ?? null; ?>
                <tr class="<?= $c['status'] === 'cancelled' ? 'inactive' : '' ?>">
                    <td><a href="<?= e(url('lohnauftraege/auftrag?id=' . (int) $c['id'])) ?>"><?= e($c['service']) ?></a><?= (int) $c['ai_helper'] === 1 ? ' <span class="badge ai" title="Von KI-Helfern abgearbeitet">🤖 KI</span>' : '' ?>
                        <?php if ($c['farmland_id'] !== null): ?><br><span class="muted small"><?= e(farmland_label((int) $c['farmland_id'])) ?></span><?php endif; ?></td>
                    <td><?= e(contract_client($c)) ?><?= $c['client_farm_id'] === null ? ' <span class="muted small">(extern)</span>' : '' ?></td>
                    <td><?= e(farm_name((int) $c['farm_id'])) ?></td>
                    <td class="num"><?= (int) $c['price'] > 0 ? e(fmt_money((int) $c['price'])) : '<span class="muted">–</span>' ?></td>
                    <td><?= $c['due_day'] !== null && in_array($c['status'], ['open', 'accepted'], true) ? e(game_day_label((int) $c['due_day'])) : '' ?></td>
                    <td><?= (int) $c['tasks_all'] ? ((int) $c['tasks_all'] - (int) $c['tasks_open']) . ' / ' . (int) $c['tasks_all'] . ' erledigt' : '<span class="muted">keine</span>' ?></td>
                    <td><span class="badge <?= e($badge[$c['status']] ?? '') ?>"><?= e(CONTRACT_STATUSES[$c['status']] ?? $c['status']) ?></span>
                        <?php if ($s): ?><br><span class="small <?= $s['status'] === 'booked' ? 'ok' : 'warn' ?>"><?= $s['status'] === 'booked' ? 'gebucht' : ($s['status'] === 'open' ? 'noch nicht gebucht' : 'Vorschlag verworfen') ?></span><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$contracts): ?><tr><td colspan="7" class="muted"><?= $archived ? 'Das Archiv ist leer.' : 'Noch keine Lohnaufträge' . ($all ? '' : ' in den letzten 30 Tagen') . '.' ?></td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
