<?php
/** @var array $farms @var array $people @var bool $canAssign @var array $errors @var array $form @var ?array $contract @var array $refOptions @var array $dayOptions @var string $action @var ?array $task @var array $user */
$refGroups = ['vehicle' => 'Fahrzeuge', 'pen' => 'Ställe', 'farmland' => 'Felder'];
$refValue = $form['ref_type'] ? $form['ref_type'] . ':' . $form['ref_id'] : '';
$editing = $task !== null;
?>
<?php if (!$editing): ?>
<header class="page-head">
    <div>
        <h1>Neue Aufgabe</h1>
        <p class="muted"><?= $contract ? 'Für den Lohnauftrag „' . e(contract_title($contract)) . '“.' : 'Kommt aufs Board. Ohne Zuweisung kann sie jeder übernehmen.' ?></p>
    </div>
</header>
<?php endif; ?>

<?php if ($errors): ?>
    <div class="alert alert-error"><?= implode('<br>', array_map('e', $errors)) ?></div>
<?php endif; ?>

<?php if (!$editing): ?><section class="card"><?php endif; ?>
<form method="post" action="<?= e(url($action)) ?>" class="stack">
    <?= csrf_field() ?>
    <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int) $task['id'] ?>"><input type="hidden" name="action" value="edit"><?php endif; ?>
    <?php if ($contract): ?><input type="hidden" name="contract_id" value="<?= (int) $contract['id'] ?>"><?php endif; ?>
    <label>Titel
        <input name="title" required maxlength="150" value="<?= e($form['title']) ?>" placeholder="z. B. Feld 12 grubbern">
    </label>
    <label>Beschreibung
        <textarea name="description" rows="4" maxlength="5000"><?= e($form['description']) ?></textarea>
    </label>
    <div class="grid-2 tight">
        <label>Hof
            <select name="farm_id">
                <option value="0">– alle / kein bestimmter –</option>
                <?php foreach ($farms as $id => $name): ?>
                    <option value="<?= (int) $id ?>"<?= (int) $id === (int) $form['farm_id'] ? ' selected' : '' ?>><?= e($name) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Zugewiesen an
            <select name="assignee_id">
                <option value="0">– offen, jeder kann übernehmen –</option>
                <?php foreach ($people as $id => $nick): if (!$canAssign && $id !== (int) $user['id'] && $id !== (int) $form['assignee_id']) { continue; } ?>
                    <option value="<?= (int) $id ?>"<?= (int) $id === (int) $form['assignee_id'] ? ' selected' : '' ?>><?= e($nick) ?><?= $id === (int) $user['id'] ? ' (ich)' : '' ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Priorität
            <select name="priority">
                <?php foreach (TASK_PRIORITIES as $p => $label): ?>
                    <option value="<?= (int) $p ?>"<?= (int) $p === (int) $form['priority'] ? ' selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Fällig im Spiel
            <?php if ($dayOptions): ?>
                <select name="due_day">
                    <option value="">– ohne Termin –</option>
                    <?php foreach ($dayOptions as $day => $label): ?>
                        <option value="<?= (int) $day ?>"<?= $form['due_day'] !== null && (int) $day === (int) $form['due_day'] ? ' selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            <?php else: ?>
                <input type="number" name="due_day" min="0" value="<?= $form['due_day'] !== null ? (int) $form['due_day'] : '' ?>" placeholder="Spieltag (Kalender noch unbekannt)">
            <?php endif; ?>
        </label>
    </div>
    <label>Bezug (optional)
        <select name="ref">
            <option value="">– nichts –</option>
            <?php foreach ($refGroups as $type => $label): if (!$refOptions[$type]) { continue; } ?>
                <optgroup label="<?= e($label) ?>">
                    <?php foreach ($refOptions[$type] as $id => $name): $v = $type . ':' . $id; ?>
                        <option value="<?= e($v) ?>"<?= $v === $refValue ? ' selected' : '' ?>><?= e($name) ?></option>
                    <?php endforeach; ?>
                </optgroup>
            <?php endforeach; ?>
            <?php if ($refValue !== '' && !isset($refOptions[$form['ref_type']][$form['ref_id']])): ?>
                <option value="<?= e($refValue) ?>" selected>(bisheriger Bezug, nicht mehr im Spiel)</option>
            <?php endif; ?>
        </select>
    </label>
    <?php if (!$editing): ?>
        <label>Spalte
            <select name="status">
                <option value="todo"<?= $form['status'] === 'todo' ? ' selected' : '' ?>>To Do</option>
                <option value="backlog"<?= $form['status'] === 'backlog' ? ' selected' : '' ?>>Backlog (später)</option>
            </select>
        </label>
    <?php endif; ?>
    <div class="row">
        <button class="btn btn-primary" type="submit"><?= $editing ? 'Speichern' : 'Anlegen' ?></button>
        <a class="btn btn-ghost" href="<?= e(url($editing ? 'aufgaben/aufgabe?id=' . (int) $task['id'] : ($contract ? 'lohnauftraege/auftrag?id=' . (int) $contract['id'] : 'aufgaben'))) ?>">Abbrechen</a>
    </div>
</form>
<?php if (!$editing): ?></section><?php endif; ?>
