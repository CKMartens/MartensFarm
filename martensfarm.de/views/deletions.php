<?php
/** @var array $user @var array $farms @var int $farmId @var array $savegames @var int $sgId @var ?int $currentSg @var array $rows @var array $cats */
$sign = fn(int $v): string => ($v > 0 ? '+' : '') . fmt_money($v);
?>
<header class="page-head">
    <div>
        <h1>Löschprotokoll</h1>
        <p class="muted">Endgültig gelöschte Buchungen: was es war, wer sie angelegt und wer sie wann gelöscht hat.</p>
    </div>
    <form method="get" action="<?= e(url('admin/loeschprotokoll')) ?>" class="inline">
        <select name="hof" aria-label="Hof">
            <option value="0">Alle Höfe</option>
            <?php foreach ($farms as $id => $name): ?>
                <option value="<?= (int) $id ?>"<?= (int) $id === $farmId ? ' selected' : '' ?>><?= e($name) ?></option>
            <?php endforeach; ?>
        </select>
        <?php if (count($savegames) > 1): ?>
            <select name="sg" aria-label="Savegame">
                <option value="0"<?= $sgId === 0 ? ' selected' : '' ?>>Alle Savegames</option>
                <?php foreach ($savegames as $id => $name): ?>
                    <option value="<?= (int) $id ?>"<?= (int) $id === $sgId ? ' selected' : '' ?>><?= e($name) ?><?= (int) $id === (int) $currentSg ? ' (aktuell)' : '' ?></option>
                <?php endforeach; ?>
            </select>
        <?php endif; ?>
        <button class="btn btn-ghost btn-sm" type="submit">Anzeigen</button>
    </form>
</header>

<section class="card">
    <?php if ($rows): ?>
        <div class="table-wrap">
        <table class="table compact">
            <thead><tr><th>Hof</th><th>Tag</th><th>Buchungssatz</th><th>Text</th><th class="num">Betrag</th><th>Angelegt</th><th>Gelöscht</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $d): ?>
                <tr>
                    <td><?= e(farm_name((int) $d['farm_id'])) ?></td>
                    <td><?= (int) $d['game_day'] ?></td>
                    <td><?= e($cats[(int) $d['category_id']]['name'] ?? '?') ?><?= (int) $d['was_cancelled'] === 1 ? ' <span class="badge">war storniert</span>' : '' ?></td>
                    <td><?= e($d['text']) ?><?= $d['reason'] !== '' ? '<br><span class="small muted">Grund: ' . e($d['reason']) . '</span>' : '' ?></td>
                    <td class="num"><?= e($sign((int) $d['amount'])) ?></td>
                    <td><?= e($d['created_nick'] ?? '–') ?><br><span class="muted small"><?= e(fmt_datetime($d['created_at'])) ?></span></td>
                    <td><strong><?= e($d['deleted_nick'] ?? '–') ?></strong><br><span class="muted small"><?= e(fmt_datetime($d['deleted_at'])) ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <p class="muted small"><?= count($rows) ?> Einträge<?= count($rows) >= 300 ? ' (die neuesten 300)' : '' ?>. Gelöscht werden kann nur mit dem Recht „Buchungen löschen“, hier sieht man nur Höfe, deren Finanzen man sehen darf.</p>
    <?php else: ?>
        <p class="muted">Noch nichts gelöscht.</p>
    <?php endif; ?>
</section>
