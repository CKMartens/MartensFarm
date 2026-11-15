<?php
/**
 * Hinweise aus dem Nachrichtenboard zu einer Aufgabe, einem Lohnauftrag oder Fahrzeug.
 * Erwartet $boardRefType und $boardRefId.
 * @var array $user @var string $boardRefType @var string $boardRefId
 */
$boardPosts = board_for_ref($boardRefType, $boardRefId);
$boardCanPost = can($user, 'board.post');
if ($boardPosts || $boardCanPost):
?>
<section class="card">
    <div class="page-head">
        <h2>Hinweise <span class="muted small">(Nachrichtenboard)</span></h2>
        <?php if ($boardCanPost): ?>
            <a class="btn btn-ghost btn-sm" href="<?= e(url('nachrichten?bezug=' . rawurlencode($boardRefType . ':' . $boardRefId) . '#neu')) ?>">+ Hinweis</a>
        <?php endif; ?>
    </div>
    <?php if ($boardPosts): ?>
        <ul class="plain">
            <?php foreach ($boardPosts as $bp): ?>
                <li><a href="<?= e(url('nachrichten/beitrag?id=' . (int) $bp['id'])) ?>"><?= e($bp['title']) ?></a>
                    <span class="muted small">· <?= e($bp['nickname'] ?? '–') ?>, <?= e(fmt_datetime($bp['created_at'])) ?></span></li>
            <?php endforeach; ?>
        </ul>
    <?php else: ?>
        <p class="muted small">Noch keine Hinweise.</p>
    <?php endif; ?>
</section>
<?php endif; ?>
