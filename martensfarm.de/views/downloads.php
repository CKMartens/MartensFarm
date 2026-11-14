<?php
/** @var array $mods @var array $gone @var bool $canManage @var bool $configured */
?>
<header class="page-head">
    <div>
        <h1>Downloads</h1>
        <p class="muted">Die Mods, die gerade auf unserem Gameserver laufen. Die Liste aktualisiert sich bei jedem Abruf von selbst.</p>
    </div>
</header>

<div class="alert alert-warning">
    Nur für unsere Runde: Die Dateien kommen direkt von unserem Server. Bitte nicht weitergeben oder woanders hochladen,
    viele Autoren erlauben das nicht. Nutzung auf eigene Gefahr. Mods aus dem offiziellen ModHub lädst du am besten im Spiel.
</div>

<?php if (!$configured): ?>
    <div class="alert alert-error">Für Downloads ist noch keine Server-Adresse eingetragen<?= can(current_user(), 'admin.settings') ? ' (Einstellungen → Mod-Downloads)' : '' ?>.</div>
<?php endif; ?>

<section class="card">
    <div class="table-wrap">
        <table class="table">
            <thead>
            <tr>
                <th>Mod</th><th>Version</th><th>Autor</th><th>Prüfsumme</th>
                <?php if ($canManage): ?><th class="num">Geladen</th><?php endif; ?>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($mods as $m): ?>
                <tr<?= (int) $m['is_hidden'] === 1 ? ' class="inactive"' : '' ?>>
                    <td>
                        <strong><?= e($m['title'] ?: $m['mod_name']) ?></strong>
                        <?php if ((int) $m['is_hidden'] === 1): ?> <span class="badge">ausgeblendet</span><?php endif; ?>
                        <?php if ($m['changed_at'] && strtotime((string) $m['changed_at']) > time() - 7 * 86400): ?> <span class="badge ok">neu</span><?php endif; ?>
                        <div class="muted small"><code><?= e($m['mod_name']) ?>.zip</code></div>
                        <?php if ($m['note']): ?><div class="small pre-line"><?= e($m['note']) ?></div><?php endif; ?>
                    </td>
                    <td><?= e($m['version'] ?: '–') ?></td>
                    <td><?= e($m['author'] ?: '–') ?></td>
                    <td><?php if ($m['hash']): ?><code class="small" title="<?= e($m['hash']) ?>"><?= e(substr((string) $m['hash'], 0, 10)) ?>…</code><?php else: ?>–<?php endif; ?></td>
                    <?php if ($canManage): ?><td class="num"><?= (int) $m['dl_count'] ?></td><?php endif; ?>
                    <td class="num">
                        <?php if ($configured): ?>
                            <a class="btn btn-primary" href="<?= e(url('downloads/datei?mod=' . rawurlencode((string) $m['mod_name']))) ?>" download>Herunterladen</a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php if ($canManage): ?>
                    <tr class="sub-row<?= (int) $m['is_hidden'] === 1 ? ' inactive' : '' ?>">
                        <td colspan="6">
                            <details>
                                <summary class="small muted">Verwalten</summary>
                                <div class="btn-row">
                                    <form method="post" action="<?= e(url('downloads')) ?>" class="stack grow">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="mod" value="<?= e($m['mod_name']) ?>">
                                        <input type="hidden" name="action" value="note">
                                        <label class="small">Hinweis / Changelog für alle
                                            <textarea name="note" rows="3" maxlength="5000"><?= e($m['note'] ?? '') ?></textarea>
                                        </label>
                                        <div><button class="btn btn-ghost" type="submit">Hinweis speichern</button></div>
                                    </form>
                                    <form method="post" action="<?= e(url('downloads')) ?>">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="mod" value="<?= e($m['mod_name']) ?>">
                                        <input type="hidden" name="action" value="toggle">
                                        <button class="btn btn-ghost" type="submit"><?= (int) $m['is_hidden'] === 1 ? 'Wieder anzeigen' : 'Ausblenden' ?></button>
                                    </form>
                                </div>
                            </details>
                        </td>
                    </tr>
                <?php endif; ?>
            <?php endforeach; ?>
            <?php if (!$mods): ?>
                <tr><td colspan="6" class="muted">Noch keine Mods bekannt. Die Liste kommt mit dem nächsten Abruf vom Server.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <p class="muted small">Die Prüfsumme ist die, die der Server meldet. Ausgeblendete Mods sieht nur, wer Downloads verwalten darf.</p>
</section>

<?php if ($gone): ?>
    <section class="card">
        <h2>Nicht mehr auf dem Server</h2>
        <ul class="plain">
            <?php foreach ($gone as $g): ?>
                <li><?= e($g['title'] ?: $g['mod_name']) ?> <?= e($g['version'] ?? '') ?> <span class="muted small">zuletzt gesehen <?= e(fmt_datetime($g['last_seen_at'])) ?></span></li>
            <?php endforeach; ?>
        </ul>
    </section>
<?php endif; ?>
