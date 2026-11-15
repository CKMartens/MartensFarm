<?php
/** @var array $user @var ?array $contract @var array $farms @var array $farmlands @var array $errors @var array $form @var array $tasks
 *  @var array $suggestions @var bool $canManage @var array $names @var array $dayOptions @var bool $openEdit */
$badge = ['open' => '', 'accepted' => 'warn', 'done' => 'ok', 'cancelled' => 'bad'];
$active = $contract && in_array($contract['status'], ['open', 'accepted'], true);
$locked = $contract && $contract['status'] === 'done';

$formHtml = function () use ($contract, $farms, $farmlands, $form, $dayOptions, $locked): void { ?>
    <form method="post" action="<?= e(url($contract ? 'lohnauftraege/auftrag' : 'lohnauftraege/neu')) ?>" class="stack">
        <?= csrf_field() ?>
        <?php if ($contract): ?><input type="hidden" name="id" value="<?= (int) $contract['id'] ?>"><input type="hidden" name="action" value="edit"><?php endif; ?>
        <?php if ($locked): ?><p class="muted small">Der Auftrag ist erledigt: Preis und Höfe stecken schon im Buchungsvorschlag, hier lassen sich nur noch Leistung und Notiz ändern.</p><?php endif; ?>
        <label>Leistung
            <input name="service" required maxlength="150" value="<?= e($form['service']) ?>" placeholder="z. B. Weizen dreschen, Gras mähen und schwaden">
        </label>
        <div class="grid-2 tight">
            <label>Auftraggeber
                <select name="client" required<?= $locked ? ' disabled' : '' ?>>
                    <option value="">– bitte wählen –</option>
                    <?php foreach ($farms as $id => $name): ?>
                        <option value="<?= (int) $id ?>"<?= (string) $id === (string) $form['client'] ? ' selected' : '' ?>><?= e($name) ?></option>
                    <?php endforeach; ?>
                    <option value="extern"<?= $form['client'] === 'extern' ? ' selected' : '' ?>>Extern (Name unten)</option>
                </select>
            </label>
            <label>Name, wenn extern
                <input name="client_name" maxlength="100" value="<?= e($form['client_name']) ?>" placeholder="z. B. Lohnunternehmen Petersen"<?= $locked ? ' disabled' : '' ?>>
            </label>
            <label>Unser Hof (macht die Arbeit, bekommt das Geld)
                <select name="farm_id"<?= $locked ? ' disabled' : '' ?>>
                    <?php foreach ($farms as $id => $name): ?>
                        <option value="<?= (int) $id ?>"<?= (int) $id === (int) $form['farm_id'] ? ' selected' : '' ?>><?= e($name) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Feld
                <select name="farmland_id"<?= $locked ? ' disabled' : '' ?>>
                    <option value="">– kein bestimmtes –</option>
                    <?php foreach ($farmlands as $id => $label): ?>
                        <option value="<?= (int) $id ?>"<?= $form['farmland_id'] !== null && (int) $id === (int) $form['farmland_id'] ? ' selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Vereinbarter Preis in €
                <input name="price" inputmode="decimal" maxlength="20" value="<?= e((string) $form['price']) ?>" placeholder="z. B. 8500"<?= $locked ? ' disabled' : '' ?>>
            </label>
            <label>Fällig im Spiel
                <?php if ($dayOptions): ?>
                    <select name="due_day"<?= $locked ? ' disabled' : '' ?>>
                        <option value="">– ohne Termin –</option>
                        <?php foreach ($dayOptions as $day => $label): ?>
                            <option value="<?= (int) $day ?>"<?= $form['due_day'] !== null && (int) $day === (int) $form['due_day'] ? ' selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php else: ?>
                    <input type="number" name="due_day" min="0" value="<?= $form['due_day'] !== null ? (int) $form['due_day'] : '' ?>" placeholder="Spieltag"<?= $locked ? ' disabled' : '' ?>>
                <?php endif; ?>
            </label>
        </div>
        <?php if ($locked): // gesperrte Felder schickt der Browser nicht mit, damit die Prüfung trotzdem passt ?>
            <input type="hidden" name="client" value="<?= e($form['client']) ?>">
            <input type="hidden" name="client_name" value="<?= e($form['client_name']) ?>">
            <input type="hidden" name="farm_id" value="<?= (int) $form['farm_id'] ?>">
            <input type="hidden" name="price" value="<?= e((string) $form['price']) ?>">
        <?php endif; ?>
        <label>Notiz
            <textarea name="note" rows="3" maxlength="5000" placeholder="z. B. Stroh bleibt liegen, Abfuhr macht der Auftraggeber"><?= e($form['note']) ?></textarea>
        </label>
        <?php if (!$contract): ?>
            <label class="check-label"><input class="check" type="checkbox" name="create_task" value="1" checked> Gleich eine Aufgabe dafür aufs Board stellen</label>
        <?php endif; ?>
        <div class="row">
            <button class="btn btn-primary" type="submit"><?= $contract ? 'Speichern' : 'Anlegen' ?></button>
            <a class="btn btn-ghost" href="<?= e(url($contract ? 'lohnauftraege/auftrag?id=' . (int) $contract['id'] : 'lohnauftraege')) ?>">Abbrechen</a>
        </div>
    </form>
<?php };
?>
<header class="page-head">
    <div>
        <p class="muted small"><a href="<?= e(url('lohnauftraege')) ?>">← Lohnaufträge</a></p>
        <h1><?= $contract ? e($contract['service']) : 'Neuer Lohnauftrag' ?></h1>
        <?php if ($contract): ?>
            <p class="muted"><span class="badge <?= e($badge[$contract['status']] ?? '') ?>"><?= e(CONTRACT_STATUSES[$contract['status']] ?? '') ?></span>
                für <?= e(contract_client($contract)) ?> · <?= e(farm_name((int) $contract['farm_id'])) ?></p>
        <?php endif; ?>
    </div>
</header>

<?php if ($errors): ?>
    <div class="alert alert-error"><?= implode('<br>', array_map('e', $errors)) ?></div>
<?php endif; ?>

<?php if (!$contract): ?>
    <section class="card"><?php $formHtml(); ?></section>
<?php else: ?>
    <div class="grid-2">
        <section class="card">
            <h2>Auftrag</h2>
            <table class="table compact">
                <tr><th>Auftraggeber</th><td><?= e(contract_client($contract)) ?><?= $contract['client_farm_id'] === null ? ' <span class="muted small">(extern)</span>' : '' ?></td></tr>
                <tr><th>Feld</th><td><?= $contract['farmland_id'] !== null ? e(farmland_label((int) $contract['farmland_id'])) : '<span class="muted">–</span>' ?></td></tr>
                <tr><th>Preis</th><td><?= (int) $contract['price'] > 0 ? e(fmt_money((int) $contract['price'])) : '<span class="muted">noch offen</span>' ?></td></tr>
                <tr><th>Fällig</th><td><?= $contract['due_day'] !== null ? e($active ? game_day_label((int) $contract['due_day']) : (game_day_info((int) $contract['due_day'])['date'] ?? 'Tag ' . (int) $contract['due_day'])) : '<span class="muted">ohne Termin</span>' ?></td></tr>
                <tr><th>Angelegt</th><td><?= e($names[(int) $contract['created_by']] ?? '?') ?>, <?= e(fmt_datetime($contract['created_at'])) ?></td></tr>
                <?php if ($contract['done_at']): ?><tr><th>Erledigt</th><td><?= e(fmt_datetime($contract['done_at'])) ?><?= $contract['done_day'] !== null ? ' (Spieltag ' . (int) $contract['done_day'] . ')' : '' ?></td></tr><?php endif; ?>
            </table>
            <?php if ($contract['note']): ?><p class="pre-line"><?= e($contract['note']) ?></p><?php endif; ?>

            <?php if ($canManage): ?>
                <div class="btn-row">
                    <?php foreach (array_filter([
                        'accept' => $contract['status'] === 'open' ? ['Annehmen', 'btn-ghost'] : null,
                        'finish' => $active ? ['Als erledigt abschließen', 'btn-primary'] : null,
                        'cancel' => $active ? ['Stornieren', 'btn-ghost'] : null,
                        'reopen' => $contract['status'] === 'cancelled' ? ['Wieder öffnen', 'btn-ghost'] : null,
                    ]) as $act => [$label, $cls]): ?>
                        <form method="post" action="<?= e(url('lohnauftraege/auftrag')) ?>">
                            <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $contract['id'] ?>"><input type="hidden" name="action" value="<?= e($act) ?>">
                            <button class="btn <?= e($cls) ?> btn-sm" type="submit"><?= e($label) ?></button>
                        </form>
                    <?php endforeach; ?>
                </div>
                <?php if ($active): ?><p class="muted small">Sind alle Aufgaben des Auftrags erledigt, schließt er sich von selbst.</p><?php endif; ?>
            <?php endif; ?>
        </section>

        <section class="card">
            <h2>Aufgaben</h2>
            <?php if ($tasks): ?>
                <ul class="plain">
                    <?php foreach ($tasks as $t): ?>
                        <li><span class="badge<?= $t['status'] === 'done' ? ' ok' : '' ?>"><?= e(TASK_STATUSES[$t['status']] ?? '') ?></span>
                            <a href="<?= e(url('aufgaben/aufgabe?id=' . (int) $t['id'])) ?>"><?= e($t['title']) ?></a>
                            <span class="muted small"><?= $t['assignee_id'] !== null ? '· ' . e($names[(int) $t['assignee_id']] ?? '?') : '· offen' ?></span></li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p class="muted">Noch keine Aufgaben.</p>
            <?php endif; ?>
            <?php if ($active && can($user, 'tasks.create')): ?>
                <p><a class="btn btn-ghost btn-sm" href="<?= e(url('aufgaben/neu?auftrag=' . (int) $contract['id'])) ?>">+ Aufgabe dazu</a></p>
            <?php endif; ?>

            <?php if ($suggestions): ?>
                <h2>Buchung</h2>
                <ul class="plain">
                    <?php foreach ($suggestions as $s): ?>
                        <li><?= e(SUGGESTION_KINDS[$s['kind']][0] ?? $s['kind']) ?> · <?= e(farm_name((int) $s['farm_id'])) ?> · <?= $s['amount'] !== null ? e(fmt_money((int) $s['amount'])) : 'Betrag offen' ?>
                            <?php if ($s['status'] === 'booked'): ?><span class="badge ok">gebucht</span>
                            <?php elseif ($s['status'] === 'dismissed'): ?><span class="badge">verworfen</span>
                            <?php elseif (finance_can_book($user, (int) $s['farm_id'])): ?><a class="btn btn-primary btn-sm" href="<?= e(url('finanzen/buchen?vorschlag=' . (int) $s['id'])) ?>">Jetzt buchen</a>
                            <?php else: ?><span class="badge warn">noch nicht gebucht</span><?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    </div>

    <?php if ($canManage && $contract['status'] !== 'cancelled'): ?>
        <section class="card">
            <details<?= $openEdit ? ' open' : '' ?>>
                <summary><h2>Bearbeiten</h2></summary>
                <?php $formHtml(); ?>
            </details>
        </section>
    <?php endif; ?>
<?php endif; ?>

<?php if ($contract): $boardRefType = 'contract'; $boardRefId = (string) $contract['id']; require __DIR__ . '/board_refs.php'; endif; ?>
