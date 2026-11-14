<?php
/** @var array $user @var array $farms @var int $farmId @var int $mine @var array $columns @var array $names @var string $query @var array $refs @var array $contracts @var ?int $gameDay */
$order = array_keys(TASK_COLUMNS);
$refIcons = ['Fahrzeug' => '🚜', 'Stall' => '🐄', 'Feld' => '🌾'];
$total = array_sum(array_map('count', $columns)) - count($columns['done']);
?>
<header class="page-head">
    <div>
        <h1>Aufgaben</h1>
        <p class="muted"><?= (int) $total ?> offen<?= $gameDay !== null ? ' · heute ist ' . e(game_day_info($gameDay)['date'] ?? 'Tag ' . $gameDay) : '' ?>. Karten lassen sich per Drag &amp; Drop verschieben.</p>
    </div>
    <div class="btn-row">
        <form method="get" action="<?= e(url('aufgaben')) ?>" class="inline">
            <select name="hof" aria-label="Hof">
                <option value="0">Alle Höfe</option>
                <?php foreach ($farms as $id => $name): ?>
                    <option value="<?= (int) $id ?>"<?= (int) $id === $farmId ? ' selected' : '' ?>><?= e($name) ?></option>
                <?php endforeach; ?>
            </select>
            <label class="check-label small"><input class="check" type="checkbox" name="meine" value="1"<?= $mine ? ' checked' : '' ?>> nur meine</label>
            <button class="btn btn-ghost btn-sm" type="submit">Filtern</button>
        </form>
        <?php if (can($user, 'tasks.create')): ?>
            <a class="btn btn-primary" href="<?= e(url('aufgaben/neu' . ($farmId ? '?hof=' . $farmId : ''))) ?>">+ Neue Aufgabe</a>
        <?php endif; ?>
    </div>
</header>

<div class="board" data-move-url="<?= e(url('aufgaben/verschieben')) ?>" data-csrf="<?= e(csrf_token()) ?>">
    <?php foreach (TASK_COLUMNS as $status => $label): ?>
        <section class="board-col" data-status="<?= e($status) ?>">
            <h2><?= e($label) ?> <span class="muted small"><?= count($columns[$status]) ?></span></h2>
            <?php if ($status === 'done' && $columns['done']): ?><p class="muted small">die letzten 30 Tage</p><?php endif; ?>
            <div class="board-cards">
                <?php foreach ($columns[$status] as $t):
                    $moves = task_allowed_moves($user, $t);
                    $pos = array_search($status, $order, true);
                    $prev = null; $next = null;
                    for ($i = $pos - 1; $i >= 0; $i--) { if (in_array($order[$i], $moves, true)) { $prev = $order[$i]; break; } }
                    for ($i = $pos + 1; $i < count($order); $i++) { if (in_array($order[$i], $moves, true)) { $next = $order[$i]; break; } }
                    $ref = $refs[(int) $t['id']] ?? null;
                    $overdue = task_is_overdue($t);
                    $contract = $t['contract_id'] !== null ? ($contracts[(int) $t['contract_id']] ?? null) : null;
                ?>
                    <article class="task-card prio-<?= (int) $t['priority'] ?><?= $overdue ? ' overdue' : '' ?>"<?= array_intersect($moves, $order) ? ' draggable="true"' : '' ?>
                             data-id="<?= (int) $t['id'] ?>" data-moves="<?= e(implode(',', array_intersect($moves, $order))) ?>">
                        <a class="task-title" href="<?= e(url('aufgaben/aufgabe?id=' . (int) $t['id'])) ?>"><?= e($t['title']) ?></a>
                        <div class="task-meta">
                            <?php if ((int) $t['priority'] >= 3): ?><span class="badge <?= (int) $t['priority'] === 4 ? 'bad' : 'warn' ?>"><?= e(TASK_PRIORITIES[(int) $t['priority']]) ?></span><?php endif; ?>
                            <span class="badge"><?= $t['assignee_id'] !== null ? '👤 ' . e($names[(int) $t['assignee_id']] ?? '?') : 'offen' ?></span>
                            <?php if ($t['due_day'] !== null && $status !== 'done'): ?><span class="badge<?= $overdue ? ' bad' : '' ?>">📅 <?= e(game_day_label((int) $t['due_day'])) ?></span><?php endif; ?>
                            <?php if ($ref): ?><span class="badge"><?= $refIcons[$ref[0]] ?? '' ?> <?= e($ref[1]) ?></span><?php endif; ?>
                            <?php if ($contract): ?><span class="badge">🤝 <?= e(contract_client($contract)) ?></span><?php endif; ?>
                            <?php if ($t['auto_key'] !== null): ?><span class="badge" title="Automatisch angelegt, schließt sich selbst">🤖 auto</span><?php endif; ?>
                            <?php if ($farmId === 0 && $t['farm_id'] !== null && count($farms) > 1): ?><span class="muted small"><?= e(farm_name((int) $t['farm_id'])) ?></span><?php endif; ?>
                        </div>
                        <?php if ($prev || $next || task_can_take($user, $t)): ?>
                            <div class="task-actions">
                                <?php foreach ([[$prev, '◀'], [$next, '▶']] as [$to, $arrow]): if (!$to) { continue; } ?>
                                    <form method="post" action="<?= e(url('aufgaben')) ?>">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                                        <input type="hidden" name="status" value="<?= e($to) ?>">
                                        <input type="hidden" name="back" value="<?= e($query) ?>">
                                        <button class="btn btn-ghost btn-sm" type="submit" title="Nach „<?= e(TASK_COLUMNS[$to]) ?>“"><?= $arrow === '◀' ? '◀ ' . e(TASK_COLUMNS[$to]) : e(TASK_COLUMNS[$to]) . ' ▶' ?></button>
                                    </form>
                                <?php endforeach; ?>
                                <?php if (task_can_take($user, $t)): ?>
                                    <form method="post" action="<?= e(url('aufgaben')) ?>">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                                        <input type="hidden" name="action" value="take">
                                        <input type="hidden" name="back" value="<?= e($query) ?>">
                                        <button class="btn btn-ghost btn-sm" type="submit">Übernehmen</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
                <?php if (!$columns[$status]): ?><p class="muted small board-empty">Nichts hier.</p><?php endif; ?>
            </div>
        </section>
    <?php endforeach; ?>
</div>

<p class="muted small">
    🤖 Werkstatt- und Tierpflege-Aufgaben legt die App selbst an (Schwellen unter Einstellungen) und hakt sie ab, sobald der Server nach der Reparatur bzw. dem Auffüllen gespeichert hat.
    <?= tasks_review_required() ? 'Fertige Aufgaben kommen in „Prüfung“, abgehakt werden sie von jemandem, der Aufgaben verteilt.' : '' ?>
</p>
