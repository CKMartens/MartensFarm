<?php
/** @var array $mods @var array $rest @var bool $canManage @var float $limitMb */
$stateBadge = [
    'ok'       => '',
    'outdated' => ' <span class="badge warn">Server hat neuere Version</span>',
];
$uploadForm = function (array $m, string $label) use ($limitMb): void { ?>
    <form method="post" action="<?= e(url('downloads')) ?>" enctype="multipart/form-data" class="btn-row upload-form">
        <?= csrf_field() ?>
        <input type="hidden" name="mod" value="<?= e($m['mod_name']) ?>">
        <input type="hidden" name="action" value="upload">
        <input type="file" name="file" accept=".zip,application/zip" required aria-label="ZIP für <?= e($m['mod_name']) ?>">
        <button class="btn btn-ghost" type="submit"><?= e($label) ?></button>
        <label class="check-label small"><input class="check" type="checkbox" name="force" value="1"> anderer Dateiname ok</label>
    </form>
<?php };
?>
<header class="page-head">
    <div>
        <h1>Downloads</h1>
        <p class="muted">Mods für unseren Server, die es nicht im ModHub gibt. ModHub-Mods lädt das Spiel beim Beitreten selbst.</p>
    </div>
</header>

<div class="alert alert-warning">
    Nur für unsere Runde: Bitte nicht weitergeben oder woanders hochladen, viele Autoren erlauben das nicht. Nutzung auf eigene Gefahr.
</div>

<section class="card">
    <div class="table-wrap">
        <table class="table">
            <thead>
            <tr>
                <th>Mod</th><th>Version</th><th>Autor</th><th class="num">Größe</th>
                <?php if ($canManage): ?><th class="num">Geladen</th><?php endif; ?>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($mods as $m): ?>
                <tr<?= (int) $m['is_hidden'] === 1 || (int) $m['on_server'] === 0 ? ' class="inactive"' : '' ?>>
                    <td>
                        <strong><?= e($m['title'] ?: $m['mod_name']) ?></strong><?= $stateBadge[$m['state']] ?>
                        <?php if ((int) $m['is_hidden'] === 1): ?> <span class="badge">ausgeblendet</span><?php endif; ?>
                        <?php if ((int) $m['on_server'] === 0): ?> <span class="badge">nicht mehr auf dem Server</span><?php endif; ?>
                        <?php if ($m['file_uploaded_at'] && strtotime((string) $m['file_uploaded_at']) > time() - 7 * 86400): ?> <span class="badge ok">neu</span><?php endif; ?>
                        <div class="muted small"><code><?= e($m['mod_name']) ?>.zip</code> · SHA-256 <code title="<?= e($m['file_sha256']) ?>"><?= e(substr((string) $m['file_sha256'], 0, 12)) ?>…</code></div>
                        <?php if ($m['note']): ?><div class="small pre-line"><?= e($m['note']) ?></div><?php endif; ?>
                    </td>
                    <td>
                        <?= e($m['file_version'] ?: '–') ?>
                        <?php if ($m['state'] === 'outdated'): ?><div class="muted small">Server: <?= e($m['version']) ?></div><?php endif; ?>
                    </td>
                    <td><?= e($m['author'] ?: '–') ?></td>
                    <td class="num"><?= e(fmt_num((int) $m['file_size'] / 1048576, 1)) ?> MB</td>
                    <?php if ($canManage): ?><td class="num"><?= (int) $m['dl_count'] ?></td><?php endif; ?>
                    <td class="num">
                        <a class="btn btn-primary" href="<?= e(url('downloads/datei?mod=' . rawurlencode((string) $m['mod_name']))) ?>" download>Herunterladen</a>
                    </td>
                </tr>
                <?php if ($canManage): ?>
                    <tr class="sub-row<?= (int) $m['is_hidden'] === 1 ? ' inactive' : '' ?>">
                        <td colspan="6">
                            <details>
                                <summary class="small muted">Verwalten</summary>
                                <div class="stack">
                                    <?php $uploadForm($m, 'Neue Version hochladen'); ?>
                                    <form method="post" action="<?= e(url('downloads')) ?>" class="stack">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="mod" value="<?= e($m['mod_name']) ?>">
                                        <input type="hidden" name="action" value="note">
                                        <label class="small">Hinweis / Changelog für alle
                                            <textarea name="note" rows="3" maxlength="5000"><?= e($m['note'] ?? '') ?></textarea>
                                        </label>
                                        <div><button class="btn btn-ghost" type="submit">Hinweis speichern</button></div>
                                    </form>
                                    <div class="btn-row">
                                        <form method="post" action="<?= e(url('downloads')) ?>">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="mod" value="<?= e($m['mod_name']) ?>">
                                            <input type="hidden" name="action" value="toggle">
                                            <button class="btn btn-ghost" type="submit"><?= (int) $m['is_hidden'] === 1 ? 'Wieder anzeigen' : 'Ausblenden' ?></button>
                                        </form>
                                        <form method="post" action="<?= e(url('downloads')) ?>">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="mod" value="<?= e($m['mod_name']) ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <button class="btn btn-ghost" type="submit">Datei löschen</button>
                                        </form>
                                    </div>
                                </div>
                            </details>
                        </td>
                    </tr>
                <?php endif; ?>
            <?php endforeach; ?>
            <?php if (!$mods): ?>
                <tr><td colspan="6" class="muted">Noch keine Mods zum Herunterladen da.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<?php if ($canManage): ?>
    <section class="card">
        <h2>Weitere Mods auf dem Server</h2>
        <p class="muted small">Die meldet der Server im Stats-Feed, es gibt aber noch keine Datei. ModHub-Mods einfach so lassen, die sehen die Mitspieler nicht.
            Für alle anderen die ZIP hochladen (bis <?= e(fmt_num($limitMb)) ?> MB). Die App prüft die modDesc.xml und merkt sich Version und SHA-256.</p>
        <table class="table compact">
            <thead><tr><th>Mod</th><th>Version</th><th>Autor</th><th>ZIP hochladen</th></tr></thead>
            <tbody>
            <?php foreach ($rest as $m): ?>
                <tr>
                    <td><?= e($m['title'] ?: $m['mod_name']) ?><div class="muted small"><code><?= e($m['mod_name']) ?></code></div></td>
                    <td><?= e($m['version'] ?: '–') ?></td>
                    <td><?= e($m['author'] ?: '–') ?></td>
                    <td><?php $uploadForm($m, 'Hochladen'); ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rest): ?><tr><td colspan="4" class="muted">Alles erledigt, oder die Liste kommt erst mit dem nächsten Abruf.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </section>
<?php endif; ?>
