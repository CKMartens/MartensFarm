<?php
/** @var array $user @var array $farms @var int $farmId @var array $tasks @var array $names */
?>
<header class="page-head">
    <div>
        <p class="muted small"><a href="<?= e(url('aufgaben' . ($farmId ? '?hof=' . $farmId : ''))) ?>">← Aufgaben</a></p>
        <h1>Aufgaben-Archiv</h1>
        <p class="muted">Erledigte und verworfene Aufgaben, die vom Board genommen wurden: von Hand oder automatisch zum nächsten Spielmonat. Nichts davon ist gelöscht.</p>
    </div>
    <?php if (count($farms) > 1): ?>
        <form method="get" action="<?= e(url('aufgaben')) ?>" class="inline">
            <input type="hidden" name="archiv" value="1">
            <select name="hof" aria-label="Hof">
                <option value="0">Alle Höfe</option>
                <?php foreach ($farms as $id => $name): ?>
                    <option value="<?= (int) $id ?>"<?= (int) $id === $farmId ? ' selected' : '' ?>><?= e($name) ?></option>
                <?php endforeach; ?>
            </select>
            <button class="btn btn-ghost btn-sm" type="submit">Filtern</button>
        </form>
    <?php endif; ?>
</header>

<section class="card">
    <div class="table-wrap">
        <table class="table compact">
            <thead><tr><th>Aufgabe</th><th>Status</th><th>Hof</th><th>Erledigt von</th><th>Zu am</th><th>Archiviert</th></tr></thead>
            <tbody>
            <?php foreach ($tasks as $t): ?>
                <tr>
                    <td><a href="<?= e(url('aufgaben/aufgabe?id=' . (int) $t['id'])) ?>"><?= e($t['title']) ?></a><?= $t['auto_key'] !== null ? ' <span class="muted small">🤖</span>' : '' ?></td>
                    <td><span class="badge<?= $t['status'] === 'done' ? ' ok' : '' ?>"><?= e(TASK_STATUSES[$t['status']] ?? $t['status']) ?></span></td>
                    <td><?= $t['farm_id'] ? e(farm_name((int) $t['farm_id'])) : '<span class="muted">alle</span>' ?></td>
                    <td><?= $t['done_by'] !== null ? e($names[(int) $t['done_by']] ?? '?') : ($t['status'] === 'done' ? '<span class="muted">automatisch</span>' : '<span class="muted">–</span>') ?></td>
                    <td><?= $t['done_day'] !== null ? e(game_day_info((int) $t['done_day'])['date'] ?? 'Tag ' . (int) $t['done_day']) : '<span class="muted">–</span>' ?></td>
                    <td class="small"><?= e(fmt_datetime((string) $t['archived_at'])) ?><?= $t['archived_by'] !== null ? ' · ' . e($names[(int) $t['archived_by']] ?? '?') : ' · automatisch' ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$tasks): ?><tr><td colspan="6" class="muted">Das Archiv ist leer.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if (count($tasks) >= 300): ?><p class="muted small">Es werden die neuesten 300 angezeigt.</p><?php endif; ?>
</section>
