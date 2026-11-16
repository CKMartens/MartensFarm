<?php
/** @var array $user @var array $post @var array $replies @var bool $canModerate @var array $errors @var array $form @var array $serverMods
 *  @var ?array $installedMod @var ?array $ref @var bool $openEdit @var bool $dismissed */
$p = $post;
[$kLabel, $kIcon] = BOARD_KINDS[$p['kind']] ?? ['?', '•'];
$pinned = (int) $p['is_pinned'] === 1 && !board_expired($p);
$hidden = $p['hidden_at'] !== null;
?>
<header class="page-head">
    <div>
        <p class="muted small"><a href="<?= e(url('nachrichten?art=' . $p['kind'])) ?>">← Nachrichten</a></p>
        <h1><?= $kIcon ?> <?= e($p['title']) ?></h1>
        <p class="muted">
            <?= e($kLabel) ?> von <?= e($p['nickname'] ?? '–') ?> · <?= e(fmt_datetime($p['created_at'])) ?>
            <?= $pinned ? ' <span class="badge warn">📌 angeheftet</span>' : '' ?>
            <?= $pinned && (int) $p['is_sticky'] === 1 ? ' <span class="badge bad">nicht ausblendbar</span>' : '' ?>
            <?= $p['expires_at'] !== null ? ' <span class="badge' . (board_expired($p) ? '' : ' ok') . '">' . (board_expired($p) ? 'abgelaufen' : 'bis ' . e(date('d.m.Y', strtotime((string) $p['expires_at'])))) . '</span>' : '' ?>
            <?= $hidden ? ' <span class="badge">ausgeblendet</span>' : '' ?>
        </p>
    </div>
    <div class="btn-row">
        <?php if ($p['kind'] === 'announcement' && can($user, 'board.announce') && !$hidden): ?>
            <form method="post" action="<?= e(url('nachrichten/beitrag')) ?>">
                <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $p['id'] ?>"><input type="hidden" name="action" value="pin">
                <button class="btn btn-ghost btn-sm" type="submit"><?= (int) $p['is_pinned'] === 1 ? 'Nicht mehr anheften' : 'Anheften' ?></button>
            </form>
        <?php endif; ?>
        <?php if ($pinned && !$hidden && board_can_dismiss($user, $p)): ?>
            <form method="post" action="<?= e(url('nachrichten/beitrag')) ?>">
                <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $p['id'] ?>"><input type="hidden" name="action" value="<?= $dismissed ? 'undismiss' : 'dismiss' ?>">
                <button class="btn btn-ghost btn-sm" type="submit"><?= $dismissed ? 'Wieder aufs Dashboard' : 'Vom Dashboard nehmen' ?></button>
            </form>
        <?php endif; ?>
        <?php if (board_can_hide($user, $p)): ?>
            <form method="post" action="<?= e(url('nachrichten/beitrag')) ?>">
                <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                <input type="hidden" name="action" value="<?= $hidden ? 'unhide' : 'hide' ?>">
                <?php if (!$hidden || $canModerate): ?><button class="btn btn-ghost btn-sm" type="submit"><?= $hidden ? 'Wieder zeigen' : 'Ausblenden' ?></button><?php endif; ?>
            </form>
        <?php endif; ?>
    </div>
</header>

<div class="grid-2">
    <section class="card">
        <?php if ($p['body']): ?><p class="pre-line"><?= e($p['body']) ?></p><?php else: ?><p class="muted">Kein weiterer Text.</p><?php endif; ?>
        <table class="table compact">
            <?php if ($ref): ?>
                <tr><th><?= e($ref[0]) ?></th><td><?= $ref[2] ? '<a href="' . e(url($ref[2])) . '">' . e($ref[1]) . '</a>' : e($ref[1]) ?></td></tr>
            <?php endif; ?>
            <?php if ($p['kind'] === 'mod_wish'): ?>
                <?php if ($p['mod_url']): ?>
                    <tr><th>Link</th><td><a href="<?= e($p['mod_url']) ?>" rel="noopener noreferrer nofollow" target="_blank"><?= e(parse_url($p['mod_url'], PHP_URL_HOST) ?: $p['mod_url']) ?> ↗</a></td></tr>
                <?php endif; ?>
                <tr><th>Status</th><td><span class="badge"><?= e(MOD_WISH_STATUSES[$p['mod_status'] ?? 'open'] ?? '?') ?></span>
                    <?php if ($installedMod && can($user, 'downloads.view')): ?>
                        · <a href="<?= e(url('downloads#mod-' . $installedMod['mod_name'])) ?>"><?= e($installedMod['title'] ?: $installedMod['mod_name']) ?> in den Downloads</a>
                    <?php endif; ?></td></tr>
            <?php endif; ?>
        </table>

        <?php if ($p['kind'] === 'mod_wish' && $canModerate): ?>
            <h2 class="spaced">Status setzen</h2>
            <form method="post" action="<?= e(url('nachrichten/beitrag')) ?>" class="stack">
                <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $p['id'] ?>"><input type="hidden" name="action" value="mod_status">
                <select name="mod_status" aria-label="Status">
                    <?php foreach (MOD_WISH_STATUSES as $k => $label): ?>
                        <option value="<?= e($k) ?>"<?= ($p['mod_status'] ?? 'open') === $k ? ' selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if ($serverMods): ?>
                    <label>Bei „installiert“: Mod auf dem Server
                        <select name="mod_name">
                            <option value="">– keine Zuordnung –</option>
                            <?php foreach ($serverMods as $name => $title): ?>
                                <option value="<?= e($name) ?>"<?= $p['mod_name'] === $name ? ' selected' : '' ?>><?= e($title ?: $name) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                <?php endif; ?>
                <div><button class="btn btn-ghost btn-sm" type="submit">Speichern</button></div>
            </form>
        <?php endif; ?>
    </section>

    <section class="card" id="antworten">
        <h2>Antworten <span class="muted small">(<?= count(array_filter($replies, fn($r) => $r['hidden_at'] === null)) ?>)</span></h2>
        <?php if ($replies): ?>
            <ul class="plain task-log">
                <?php foreach ($replies as $r): ?>
                    <li class="comment<?= $r['hidden_at'] !== null ? ' inactive' : '' ?>">
                        <span class="muted small"><?= e(fmt_datetime($r['created_at'])) ?> · <?= e($r['nickname'] ?? '–') ?><?= $r['hidden_at'] !== null ? ' · ausgeblendet' : '' ?></span>
                        <?php if (board_can_hide($user, $r) && ($r['hidden_at'] === null || $canModerate)): ?>
                            <form method="post" action="<?= e(url('nachrichten/beitrag')) ?>" class="inline">
                                <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $p['id'] ?>"><input type="hidden" name="reply_id" value="<?= (int) $r['id'] ?>">
                                <input type="hidden" name="action" value="<?= $r['hidden_at'] === null ? 'hide_reply' : 'unhide_reply' ?>">
                                <button class="btn btn-ghost btn-sm" type="submit"><?= $r['hidden_at'] === null ? 'ausblenden' : 'zeigen' ?></button>
                            </form>
                        <?php endif; ?>
                        <br><span class="pre-line"><?= e($r['body']) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <p class="muted">Noch keine Antworten.</p>
        <?php endif; ?>
        <?php if (board_can_reply($user, $p)): ?>
            <form method="post" action="<?= e(url('nachrichten/beitrag')) ?>" class="stack">
                <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $p['id'] ?>"><input type="hidden" name="action" value="reply">
                <label>Antwort
                    <textarea name="body" rows="2" maxlength="3000" required></textarea>
                </label>
                <div><button class="btn btn-ghost btn-sm" type="submit">Antworten</button></div>
            </form>
        <?php endif; ?>
    </section>
</div>

<?php if (board_can_edit($user, $p) && !$hidden): ?>
    <section class="card">
        <details<?= $openEdit ? ' open' : '' ?>>
            <summary><h2>Bearbeiten</h2></summary>
            <?php if ($errors): ?><div class="alert alert-error"><?= implode('<br>', array_map('e', $errors)) ?></div><?php endif; ?>
            <form method="post" action="<?= e(url('nachrichten/beitrag')) ?>" class="stack">
                <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $p['id'] ?>"><input type="hidden" name="action" value="edit">
                <label>Titel <input name="title" maxlength="150" required value="<?= e($form['title']) ?>"></label>
                <label>Text <textarea name="body" rows="4" maxlength="5000"><?= e($form['body']) ?></textarea></label>
                <?php if ($p['kind'] === 'mod_wish'): ?>
                    <label>Link <input name="mod_url" type="url" maxlength="255" value="<?= e($form['mod_url']) ?>"></label>
                <?php elseif ($p['kind'] === 'announcement'): ?>
                    <div class="row">
                        <label class="check-label"><input class="check" type="checkbox" name="is_pinned" value="1"<?= !empty($form['is_pinned']) ? ' checked' : '' ?>> anheften (oben und auf dem Dashboard)</label>
                        <label class="check-label"><input class="check" type="checkbox" name="is_sticky" value="1"<?= !empty($form['is_sticky']) ? ' checked' : '' ?>> nicht ausblendbar</label>
                        <label>Anzeigen bis einschließlich <input type="date" name="expires_at" value="<?= e($form['expires_at']) ?>"></label>
                    </div>
                <?php endif; ?>
                <div><button class="btn btn-primary" type="submit">Speichern</button></div>
            </form>
        </details>
    </section>
<?php endif; ?>
