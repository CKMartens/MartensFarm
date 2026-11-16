<?php
/** @var array $mod */
?>
<header class="page-head">
    <div>
        <p class="muted small"><a href="<?= e(url('downloads')) ?>">← Downloads</a></p>
        <h1>🔒 Private Mod: <?= e($mod['title'] ?: $mod['mod_name']) ?></h1>
    </div>
</header>

<section class="card narrow">
    <div class="alert alert-warning">
        <strong>Diese Mod ist privat.</strong> Sie ist nicht öffentlich erschienen und nur für unsere Runde gedacht.
        Bitte nicht weitergeben, nicht woanders hochladen und nicht über sie in Foren oder Videos berichten, ohne vorher zu fragen.
        Nutzung auf eigene Gefahr.
    </div>
    <?php if ($mod['note']): ?><p class="small pre-line"><?= e($mod['note']) ?></p><?php endif; ?>
    <form method="get" action="<?= e(url('downloads/datei')) ?>" class="stack">
        <input type="hidden" name="mod" value="<?= e($mod['mod_name']) ?>">
        <input type="hidden" name="ok" value="1">
        <label class="check-label"><input class="check" type="checkbox" name="gelesen" value="1" required> Verstanden, ich gebe die Mod nicht weiter.</label>
        <div class="btn-row">
            <button class="btn btn-primary" type="submit">Herunterladen</button>
            <a class="btn btn-ghost" href="<?= e(url('downloads')) ?>">Abbrechen</a>
        </div>
    </form>
    <p class="muted small"><code><?= e($mod['mod_name']) ?>.zip</code> · Version <?= e($mod['file_version'] ?: '–') ?> · <?= e(fmt_num((int) $mod['file_size'] / 1048576, 1)) ?> MB</p>
</section>
