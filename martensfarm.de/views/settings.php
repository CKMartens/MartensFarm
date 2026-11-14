<?php
/** @var array $byId @var array $activeIds @var array $counts @var array $savegames @var ?int $sgId @var ?array $mfInfo @var array $gs @var array $mods @var ?string $mapName */
?>
<header class="page-head">
    <div>
        <h1>Einstellungen</h1>
        <p class="muted">Welche Höfe zählen, wie sie heißen und ab wann es Hinweise gibt. Gilt für jede Map.</p>
    </div>
</header>

<form method="post" action="<?= e(url('admin/einstellungen')) ?>">
    <?= csrf_field() ?>
    <div class="grid-2">
        <section class="card">
            <h2>Höfe</h2>
            <p class="muted small">Haken = Hof wird in der App angezeigt. Höfe, die der Map gehören (oft Hof 15), oder interne Höfe einfach weglassen.
                Ein leerer Name übernimmt beim nächsten Save wieder den Namen aus dem Spiel.</p>
            <table class="table compact">
                <thead><tr><th>Zählt</th><th>Hof</th><th>Name in der App</th><th class="num">Ställe</th><th class="num">Fahrzeuge</th></tr></thead>
                <tbody>
                <?php foreach ($byId as $id => $f): ?>
                    <tr>
                        <td><input class="check" type="checkbox" name="active[]" value="<?= (int) $id ?>"<?= in_array($id, $activeIds, true) || !$activeIds ? ' checked' : '' ?> aria-label="Hof <?= (int) $id ?> zählt"></td>
                        <td>#<?= (int) $id ?><?= !empty($f['virtual']) ? ' <span class="badge">nur Gebäude</span>' : '' ?></td>
                        <td><input type="text" name="name[<?= (int) $id ?>]" value="<?= e((int) $f['name_locked'] === 1 || empty($f['virtual']) ? $f['name'] : '') ?>" maxlength="100" placeholder="Hof <?= (int) $id ?>"></td>
                        <td class="num"><?= (int) ($counts[$id]['pens'] ?? 0) ?></td>
                        <td class="num"><?= (int) ($counts[$id]['vehicles'] ?? 0) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$byId): ?><tr><td colspan="5" class="muted">Noch keine Höfe importiert.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </section>

        <section class="card">
            <h2>Hinweise und Schwellen</h2>
            <div class="stack">
                <?php foreach (NUMBER_SETTINGS as $key => [$label, $min, $max, $unit]): ?>
                    <label><?= e($label) ?> (<?= e($unit) ?>)
                        <input type="number" name="<?= e($key) ?>" min="<?= (int) $min ?>" max="<?= (int) $max ?>" value="<?= (int) setting_int($key) ?>">
                    </label>
                <?php endforeach; ?>
                <label class="check-label"><input class="check" type="checkbox" name="hide_empty_pens" value="1"<?= setting_int('hide_empty_pens') === 1 ? ' checked' : '' ?>> Leere Ställe auf der Tiere-Seite ausblenden</label>
            </div>
        </section>
    </div>
    <p><button class="btn btn-primary" type="submit">Speichern</button></p>
</form>

<div class="grid-2">
    <section class="card">
        <h2>Gameserver</h2>
        <p class="muted small">Adresse und Feed-Code des Dedicated Servers, z. B. nach einem Server- oder Anbieterwechsel.
            Beides steht im Web-Interface des Servers in den Feed-Links. Der Code wird hier nie wieder angezeigt, nur seine letzten drei Zeichen.</p>
        <form method="post" action="<?= e(url('admin/einstellungen')) ?>" class="stack">
            <?= csrf_field() ?>
            <input type="hidden" name="section" value="gameserver">
            <label>Adresse
                <input type="text" name="gs_base_url" value="<?= e($gs['base_url']) ?>" placeholder="http://IP-oder-Name:Port" maxlength="200" autocomplete="off" spellcheck="false">
            </label>
            <label>Feed-Code
                <input type="password" name="gs_code" value="" placeholder="<?= e($gs['masked'] !== '' ? 'gespeichert ' . $gs['masked'] . ', leer lassen = behalten' : 'Code eintragen') ?>" maxlength="100" autocomplete="new-password" spellcheck="false">
            </label>
            <p class="muted small">Aktuell aus: <strong><?= $gs['source'] === 'settings' ? 'diesen Einstellungen' : 'config.php' ?></strong></p>
            <div class="btn-row">
                <button class="btn btn-ghost" type="submit" name="do" value="test">Verbindung testen</button>
                <button class="btn btn-primary" type="submit" name="do" value="save">Speichern</button>
                <?php if ($gs['source'] === 'settings'): ?>
                    <button class="btn btn-ghost" type="submit" name="do" value="reset">Zurück zu config.php</button>
                <?php endif; ?>
            </div>
        </form>
    </section>

    <section class="card">
        <h2>Karte</h2>
        <img class="map-img" src="<?= e(url('karte.jpg')) ?>" alt="Karte<?= $mapName ? ' ' . e($mapName) : '' ?>" width="512" height="512" loading="lazy">
        <p class="muted small"><?= $mapName ? e($mapName) . ' · ' : '' ?>Kommt aus dem Stats-Feed und wird alle 6 Stunden neu geladen, nach einem Map- oder Serverwechsel sofort.</p>
    </section>
</div>

<div class="grid-2">
    <section class="card">
        <h2>Mod-Downloads</h2>
        <p class="muted small">Von hier holt die App die Mods für die Seite <a href="<?= e(url('downloads')) ?>">Downloads</a>.
            Bei Nitrado im Web-Interface unter Mods einen Download-Link kopieren und komplett einfügen, der Dateiname wird abgeschnitten.
            Die Adresse bleibt auf dem Server, die Mitspieler sehen sie nicht.</p>
        <form method="post" action="<?= e(url('admin/einstellungen')) ?>" class="stack">
            <?= csrf_field() ?>
            <input type="hidden" name="section" value="mods">
            <label>Download-Adresse
                <input type="text" name="mods_base_url" value="" placeholder="<?= e($mods['masked'] !== '' ? 'aktuell ' . $mods['masked'] . ', leer lassen = behalten' : 'https://…/mods/FS25_Beispiel.zip') ?>" maxlength="300" autocomplete="off" spellcheck="false">
            </label>
            <p class="muted small">Aktuell aus: <strong><?= ['settings' => 'diesen Einstellungen', 'config' => 'config.php', 'gameserver' => 'Gameserver-Adresse + /mods'][$mods['source']] ?></strong>
                · <?= (int) $mods['count'] ?> Mods auf dem Server</p>
            <div class="btn-row">
                <button class="btn btn-ghost" type="submit" name="do" value="test">Download testen</button>
                <button class="btn btn-primary" type="submit" name="do" value="save">Speichern</button>
                <?php if ($mods['source'] === 'settings'): ?>
                    <button class="btn btn-ghost" type="submit" name="do" value="reset">Vorgabe nutzen</button>
                <?php endif; ?>
            </div>
        </form>
    </section>
</div>

<div class="grid-2">
    <section class="card">
        <h2>Export-Mod</h2>
        <?php if ($mfInfo): ?>
            <ul class="plain">
                <li>Version <?= e($mfInfo['mod_version']) ?> · Schema <?= (int) $mfInfo['schema'] ?></li>
                <li>Letzter Save: <?= e(fmt_datetime($mfInfo['written_at'])) ?> · übernommen <?= e(fmt_ago($mfInfo['imported_at'])) ?></li>
                <?php if (!empty($mfInfo['missing'])): ?>
                    <li class="warn">Nicht geliefert: <?= e(implode(', ', $mfInfo['missing'])) ?></li>
                <?php endif; ?>
            </ul>
        <?php else: ?>
            <p class="muted">Noch kein Export gefunden. Die Mod <code>FS25_MartensFarmExport</code> muss auf dem Server laufen, danach einmal speichern.</p>
        <?php endif; ?>
    </section>

    <section class="card">
        <h2>Savegames</h2>
        <p class="muted small">Bei einer neuen Map oder einem neuen Spielstand legt die App automatisch ein neues Savegame an und archiviert die Kontostände des alten.</p>
        <table class="table compact">
            <thead><tr><th>Savegame</th><th>Map</th><th>Zuletzt</th><th class="num">Geld im Archiv</th></tr></thead>
            <tbody>
            <?php foreach ($savegames as $s): ?>
                <tr>
                    <td><?= e($s['savegame_name']) ?><?= (int) $s['id'] === $sgId ? ' <span class="badge ok">aktuell</span>' : '' ?></td>
                    <td><?= e($s['map_title'] ?: $s['map_id']) ?></td>
                    <td><?= e(fmt_datetime($s['last_seen_at'])) ?></td>
                    <td class="num"><?= $s['archived_money'] !== null && (int) $s['id'] !== $sgId ? e(fmt_money((int) $s['archived_money'])) : '–' ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$savegames): ?><tr><td colspan="4" class="muted">Noch keins erkannt, kommt mit dem nächsten Abruf.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </section>
</div>
