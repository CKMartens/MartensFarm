<?php
/** @var array $mods @var array $rest @var bool $canManage @var float $limitMb @var array $inbox @var ?string $inboxError @var array $modChoices */
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
                <tr id="mod-<?= e($m['mod_name']) ?>"<?= (int) $m['is_hidden'] === 1 || (int) $m['on_server'] === 0 ? ' class="inactive"' : '' ?>>
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
    <section class="card" id="ftp-eingang">
        <h2>Per FTP bereitgestellt</h2>
        <p class="muted small">Große Mods nicht über den Browser hochladen, sondern per FTP in den Eingangsordner
            <?= config('downloads.inbox_dir') ? '(eingestellt in <code>downloads.inbox_dir</code>)' : '<code>var/mods-inbox/</code>' ?> legen. Heißt die ZIP genau wie die Mod
            (z. B. <code>FS25_Irgendwas.zip</code>), übernimmt der Cron sie innerhalb von 5 Minuten automatisch<?= config('downloads.inbox_auto', true) ? '' : ' (zurzeit abgeschaltet: downloads.inbox_auto)' ?>.
            Alles andere hier zuordnen und übernehmen. Die PHP-Upload-Grenze gilt dabei nicht.</p>
        <?php if ($inboxError): ?>
            <div class="alert alert-error"><?= e($inboxError) ?></div>
        <?php else: ?>
            <table class="table compact">
                <thead><tr><th>Datei</th><th class="num">Größe</th><th>Inhalt</th><th>Übernehmen als</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($inbox as $f):
                    $pre = $f['mod']['mod_name'] ?? ($f['guess']['mod_name'] ?? ''); ?>
                    <tr>
                        <td><code><?= e($f['file']) ?></code>
                            <div class="muted small">geändert <?= e(date('d.m.Y H:i', $f['mtime'])) ?></div></td>
                        <td class="num"><?= e(fmt_num($f['size'] / 1048576, 1)) ?> MB</td>
                        <td>
                            <?php if (!$f['ready']): ?>
                                <span class="badge warn">wird noch hochgeladen?</span>
                            <?php elseif ($f['problem']): ?>
                                <span class="badge bad">fehlerhaft</span><div class="small"><?= e($f['problem']) ?></div>
                            <?php else: ?>
                                <?= e($f['zip']['title'] ?? '–') ?><div class="muted small">Version <?= e($f['zip']['version'] ?? 'unbekannt') ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <form method="post" action="<?= e(url('downloads')) ?>" class="btn-row">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="inbox_take">
                                <input type="hidden" name="file" value="<?= e($f['file']) ?>">
                                <select name="mod" required aria-label="Mod für <?= e($f['file']) ?>">
                                    <option value="">– Mod wählen –</option>
                                    <?php foreach ($modChoices as $c): ?>
                                        <option value="<?= e($c['mod_name']) ?>"<?= strcasecmp($pre, (string) $c['mod_name']) === 0 ? ' selected' : '' ?>>
                                            <?= e(($c['title'] ?: $c['mod_name']) . ' (' . $c['mod_name'] . ')' . ((int) $c['on_server'] === 1 ? '' : ' – nicht auf dem Server')) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <button class="btn btn-primary" type="submit"<?= $f['ready'] && !$f['problem'] ? '' : ' disabled' ?>>Übernehmen</button>
                            </form>
                            <?php if ($f['mod'] === null && $f['guess'] !== null): ?><div class="muted small">Vorschlag nach Dateinamen, bitte prüfen.</div><?php endif; ?>
                            <?php if ($f['mod'] === null && $f['guess'] === null): ?><div class="muted small">Name passt zu keiner Mod aus der Server-Liste.</div><?php endif; ?>
                        </td>
                        <td class="num">
                            <form method="post" action="<?= e(url('downloads')) ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="inbox_delete">
                                <input type="hidden" name="file" value="<?= e($f['file']) ?>">
                                <button class="btn btn-ghost" type="submit">Löschen</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$inbox): ?><tr><td colspan="5" class="muted">Der Eingang ist leer.</td></tr><?php endif; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>

    <section class="card">
        <h2>Weitere Mods auf dem Server</h2>
        <p class="muted small">Die meldet der Server im Stats-Feed, es gibt aber noch keine Datei. ModHub-Mods einfach so lassen, die sehen die Mitspieler nicht.
            Für alle anderen die ZIP hochladen (bis <?= e(fmt_num($limitMb)) ?> MB, größere per FTP, siehe oben). Die App prüft die modDesc.xml und merkt sich Version und SHA-256.</p>
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
