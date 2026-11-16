<?php
/** @var array $user @var array $task @var array $farms @var array $people @var bool $canAssign @var bool $canEdit @var array $errors @var array $form
 *  @var ?array $contract @var array $events @var array $names @var ?array $ref @var array $moves @var bool $openEdit
 *  @var bool $canDelete @var bool $confirmDelete */
$overdue = task_is_overdue($task);
$uid = (int) $user['id'];
$isMine = $task['assignee_id'] !== null && (int) $task['assignee_id'] === $uid;
$closed = in_array($task['status'], ['done', 'dropped'], true);
?>
<header class="page-head">
    <div>
        <p class="muted small"><a href="<?= e(url('aufgaben' . ($task['farm_id'] ? '?hof=' . (int) $task['farm_id'] : ''))) ?>">← Aufgaben</a></p>
        <h1><?= e($task['title']) ?></h1>
        <p class="muted">
            <span class="badge<?= $task['status'] === 'done' ? ' ok' : '' ?>"><?= e(TASK_STATUSES[$task['status']] ?? $task['status']) ?></span>
            <?php if ((int) $task['priority'] !== 2): ?><span class="badge<?= (int) $task['priority'] === 4 ? ' bad' : ((int) $task['priority'] === 3 ? ' warn' : '') ?>"><?= e(TASK_PRIORITIES[(int) $task['priority']]) ?></span><?php endif; ?>
            <?php if ($task['auto_key'] !== null): ?><span class="badge">🤖 automatisch</span><?php endif; ?>
            <?php if ($task['archived_at'] !== null): ?><span class="badge">🗄 archiviert</span><?php endif; ?>
            <?= $task['farm_id'] ? e(farm_name((int) $task['farm_id'])) : 'alle Höfe' ?>
        </p>
    </div>
</header>

<?php if ($confirmDelete): ?>
    <section class="card" id="loeschen">
        <h2>Aufgabe endgültig löschen?</h2>
        <p class="muted small">Die Aufgabe verschwindet mit allen Kommentaren und dem Verlauf, das lässt sich nicht rückgängig machen.
            Soll sie nur vom Board, reicht „Verworfen“.</p>
        <form method="post" action="<?= e(url('aufgaben/aufgabe')) ?>" class="btn-row">
            <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $task['id'] ?>"><input type="hidden" name="action" value="delete">
            <button class="btn btn-primary" type="submit">Endgültig löschen</button>
            <a class="btn btn-ghost" href="<?= e(url('aufgaben/aufgabe?id=' . (int) $task['id'])) ?>">Abbrechen</a>
        </form>
    </section>
<?php endif; ?>

<div class="grid-2">
    <section class="card">
        <h2>Worum geht's</h2>
        <?php if ($task['description']): ?><p class="pre-line"><?= e($task['description']) ?></p><?php else: ?><p class="muted">Keine Beschreibung.</p><?php endif; ?>
        <table class="table compact">
            <tr><th>Zugewiesen</th><td><?= $task['assignee_id'] !== null ? e($names[(int) $task['assignee_id']] ?? '?') : '<span class="muted">offen</span>' ?></td></tr>
            <tr><th>Fällig</th><td class="<?= $overdue ? 'bad' : '' ?>"><?= $task['due_day'] !== null ? e($closed ? (game_day_info((int) $task['due_day'])['date'] ?? 'Tag ' . (int) $task['due_day']) : game_day_label((int) $task['due_day'])) : '<span class="muted">ohne Termin</span>' ?></td></tr>
            <?php if ($ref): ?>
                <tr><th><?= e($ref[0]) ?></th><td><?= $ref[2] ? '<a href="' . e(url($ref[2])) . '">' . e($ref[1]) . '</a>' : e($ref[1]) ?></td></tr>
            <?php endif; ?>
            <?php if ($contract): ?>
                <tr><th>Lohnauftrag</th><td><a href="<?= e(url('lohnauftraege/auftrag?id=' . (int) $contract['id'])) ?>"><?= e(contract_title($contract)) ?></a></td></tr>
            <?php endif; ?>
            <tr><th>Angelegt</th><td><?= $task['created_by'] !== null ? e($names[(int) $task['created_by']] ?? '?') : 'automatisch' ?>, <?= e(fmt_datetime($task['created_at'])) ?></td></tr>
            <?php if ($task['done_at']): ?><tr><th>Erledigt</th><td><?= e(fmt_datetime($task['done_at'])) ?><?= $task['done_by'] !== null ? ' von ' . e($names[(int) $task['done_by']] ?? '?') : ' (automatisch)' ?></td></tr><?php endif; ?>
        </table>

        <?php if ($moves): ?>
            <h2 class="spaced">Verschieben</h2>
            <div class="btn-row">
                <?php foreach ($moves as $to): ?>
                    <form method="post" action="<?= e(url('aufgaben/aufgabe')) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= (int) $task['id'] ?>">
                        <input type="hidden" name="action" value="status">
                        <input type="hidden" name="status" value="<?= e($to) ?>">
                        <button class="btn <?= $to === 'done' || ($to === 'review' && !$canAssign) ? 'btn-primary' : 'btn-ghost' ?> btn-sm" type="submit"><?= e(TASK_STATUSES[$to]) ?></button>
                    </form>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="btn-row">
            <?php if (task_can_archive($user, $task)): ?>
                <form method="post" action="<?= e(url('aufgaben/aufgabe')) ?>">
                    <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $task['id'] ?>">
                    <input type="hidden" name="action" value="<?= $task['archived_at'] === null ? 'archive' : 'unarchive' ?>">
                    <button class="btn btn-ghost btn-sm" type="submit"><?= $task['archived_at'] === null ? '🗄 Archivieren' : 'Aus dem Archiv holen' ?></button>
                </form>
            <?php endif; ?>
            <?php if (task_can_take($user, $task)): ?>
                <form method="post" action="<?= e(url('aufgaben/aufgabe')) ?>">
                    <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $task['id'] ?>"><input type="hidden" name="action" value="take">
                    <button class="btn btn-primary btn-sm" type="submit">Übernehmen</button>
                </form>
            <?php endif; ?>
            <?php if ($isMine && !$closed && can($user, 'tasks.work')): ?>
                <form method="post" action="<?= e(url('aufgaben/aufgabe')) ?>">
                    <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $task['id'] ?>"><input type="hidden" name="action" value="release">
                    <button class="btn btn-ghost btn-sm" type="submit">Zurückgeben</button>
                </form>
            <?php endif; ?>
            <?php if ($canAssign && !$closed): ?>
                <form method="post" action="<?= e(url('aufgaben/aufgabe')) ?>" class="inline">
                    <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $task['id'] ?>"><input type="hidden" name="action" value="assign">
                    <select name="assignee_id" aria-label="Zuweisen an">
                        <option value="0">– offen –</option>
                        <?php foreach ($people as $pid => $nick): ?>
                            <option value="<?= (int) $pid ?>"<?= (int) $pid === (int) $task['assignee_id'] ? ' selected' : '' ?>><?= e($nick) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button class="btn btn-ghost btn-sm" type="submit">Zuweisen</button>
                </form>
            <?php endif; ?>
        </div>
    </section>

    <section class="card">
        <h2>Verlauf</h2>
        <ul class="plain task-log">
            <?php foreach ($events as $ev): $who = $ev['user_id'] !== null ? ($names[(int) $ev['user_id']] ?? '?') : 'App'; ?>
                <li class="<?= $ev['kind'] === 'comment' ? 'comment' : '' ?>">
                    <span class="muted small"><?= e(fmt_datetime($ev['created_at'])) ?> · <?= e($who) ?></span><br>
                    <?php if ($ev['kind'] === 'comment'): ?>
                        <span class="pre-line"><?= e($ev['text']) ?></span>
                    <?php else: ?>
                        <span class="muted"><?= e(match ($ev['kind']) {
                            'created' => 'angelegt' . ($ev['text'] !== '' ? ' (' . $ev['text'] . ')' : ''),
                            'status', 'auto' => 'verschoben: ' . $ev['text'],
                            'assign' => 'zugewiesen: ' . $ev['text'],
                            default => $ev['text'],
                        }) ?></span>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php if (task_can_comment($user)): ?>
            <form method="post" action="<?= e(url('aufgaben/aufgabe')) ?>" class="stack">
                <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $task['id'] ?>"><input type="hidden" name="action" value="comment">
                <label>Kommentar
                    <textarea name="text" rows="2" maxlength="1000" required placeholder="z. B. Saatgut fehlt noch, hole ich morgen"></textarea>
                </label>
                <div><button class="btn btn-ghost btn-sm" type="submit">Kommentieren</button></div>
            </form>
        <?php endif; ?>
    </section>
</div>

<?php if ($canEdit && $task['status'] !== 'dropped'): ?>
    <section class="card">
        <details<?= $openEdit ? ' open' : '' ?>>
            <summary><h2>Bearbeiten</h2></summary>
            <?php require __DIR__ . '/task_form.php'; ?>
        </details>
    </section>
<?php endif; ?>

<?php if ($canDelete && !$confirmDelete): ?>
    <p class="muted small"><a href="<?= e(url('aufgaben/aufgabe?id=' . (int) $task['id'] . '&loeschen=1#loeschen')) ?>">Aufgabe löschen …</a></p>
<?php endif; ?>

<?php $boardRefType = 'task'; $boardRefId = (string) $task['id']; require __DIR__ . '/board_refs.php'; ?>
