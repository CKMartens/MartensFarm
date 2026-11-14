<?php
declare(strict_types=1);

/**
 * Mod-Downloads.
 *
 * Welche Mods auf dem Server laufen (Name, Version, Autor), kommt automatisch aus dem Stats-Feed.
 * Die Dateien selbst lädt ein Verwalter in der App hoch: Nitrado gibt Mods nur mit Login im
 * Web-Interface heraus. Die ZIPs liegen außerhalb des Document Root (var/mods/) und gehen nur mit
 * Rechteprüfung über PHP raus. Beim Hochladen liest die App die Version aus der modDesc.xml,
 * so fällt auf, wenn der Server inzwischen eine neuere Version hat.
 */

/** Mod-Namen wie im Spiel: FS25_Irgendwas, nur Buchstaben, Ziffern, _ und - */
function mod_name_valid(string $name): bool
{
    return strlen($name) <= 100 && preg_match('/^[A-Za-z0-9][A-Za-z0-9_\-]*$/', $name) === 1;
}

/** Ablage der hochgeladenen ZIPs (außerhalb von public/) */
function mods_dir(): string
{
    $dir = rtrim((string) config('downloads.dir', APP_ROOT . '/var/mods'), '/');
    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('Ablage für Mods lässt sich nicht anlegen.');
    }
    return $dir;
}

function mod_file_path(string $modName): string
{
    return mods_dir() . '/' . $modName . '.zip';
}

/** Größte Datei, die hochgeladen werden kann: App-Grenze und PHP-Grenzen (upload_max_filesize, post_max_size) */
function mods_upload_limit(): int
{
    $toBytes = function (string $v): int {
        $v = trim($v);
        $n = (int) $v;
        return match (strtolower(substr($v, -1))) {
            'g' => $n * 1024 ** 3,
            'm' => $n * 1024 ** 2,
            'k' => $n * 1024,
            default => $n,
        };
    };
    $limits = [(int) config('downloads.max_mb', 1024) * 1024 * 1024];
    foreach (['upload_max_filesize', 'post_max_size'] as $ini) {
        $b = $toBytes((string) ini_get($ini));
        if ($b > 0) {
            $limits[] = $b;
        }
    }
    return min($limits);
}

/** Versionen vergleichbar machen: "1.0.0.0" und "1.0" gelten als gleich */
function mod_version_norm(?string $v): string
{
    $v = trim((string) $v);
    return $v === '' ? '' : (string) preg_replace('/(\.0)+$/', '', $v);
}

/**
 * Hochgeladene ZIP prüfen: echte ZIP, modDesc.xml im Hauptordner, Version auslesen.
 * Gibt ['version' => …, 'title' => …] zurück oder wirft eine Exception mit verständlicher Meldung.
 */
function mod_inspect_zip(string $file): array
{
    if (!class_exists('ZipArchive')) {
        // Ohne zip-Erweiterung wenigstens die Signatur prüfen
        $sig = (string) file_get_contents($file, false, null, 0, 4);
        if ($sig !== "PK\x03\x04") {
            throw new RuntimeException('Das ist keine ZIP-Datei.');
        }
        return ['version' => null, 'title' => null];
    }
    $zip = new ZipArchive();
    if ($zip->open($file, ZipArchive::RDONLY) !== true) {
        throw new RuntimeException('Das ist keine gültige ZIP-Datei.');
    }
    $xml = $zip->getFromName('modDesc.xml');
    $zip->close();
    if ($xml === false) {
        throw new RuntimeException('In der ZIP fehlt die modDesc.xml im Hauptordner. Ist das wirklich eine LS-Mod (nicht doppelt gezippt)?');
    }
    $prev = libxml_use_internal_errors(true);
    $x = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
    libxml_use_internal_errors($prev);
    if ($x === false) {
        return ['version' => null, 'title' => null];
    }
    $title = null;
    foreach (['de', 'en'] as $lang) {
        if (isset($x->title->{$lang}) && trim((string) $x->title->{$lang}) !== '') {
            $title = trim((string) $x->title->{$lang});
            break;
        }
    }
    $version = trim((string) ($x->version ?? ''));
    return ['version' => $version !== '' ? mb_substr($version, 0, 30) : null, 'title' => $title];
}

/** Hochgeladene Datei für eine Mod übernehmen. Gibt eine Erfolgsmeldung zurück oder wirft. */
function mod_store_upload(array $mod, array $upload, array $user): string
{
    $err = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
        throw new RuntimeException('Die Datei ist zu groß (Grenze ' . fmt_num(mods_upload_limit() / 1048576) . ' MB).');
    }
    if ($err !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($upload['tmp_name'] ?? ''))) {
        throw new RuntimeException('Es kam keine Datei an.');
    }
    $tmp = (string) $upload['tmp_name'];
    $size = (int) filesize($tmp);
    if ($size <= 0 || $size > mods_upload_limit()) {
        throw new RuntimeException('Die Datei ist leer oder zu groß.');
    }
    $name = (string) $mod['mod_name'];
    $orig = pathinfo((string) ($upload['name'] ?? ''), PATHINFO_FILENAME);
    if ($orig !== '' && strcasecmp($orig, $name) !== 0 && !isset($upload['force'])) {
        throw new RuntimeException('Die Datei heißt „' . $orig . '.zip“, erwartet ist „' . $name . '.zip“. Falsche Mod erwischt?');
    }
    $info = mod_inspect_zip($tmp);
    $sha = hash_file('sha256', $tmp);

    $target = mod_file_path($name);
    $part = $target . '.part';
    if (!move_uploaded_file($tmp, $part) || !@rename($part, $target)) {
        @unlink($part);
        throw new RuntimeException('Die Datei ließ sich nicht speichern (Rechte auf var/mods/ prüfen).');
    }
    @chmod($target, 0640);

    q('UPDATE server_mods SET file_version = ?, file_sha256 = ?, file_size = ?, file_uploaded_at = ?, file_uploaded_by = ? WHERE mod_name = ?',
        [$info['version'], $sha, $size, now(), (int) $user['id'], $name]);

    $msg = $name . '.zip hochgeladen (' . fmt_num($size / 1048576, 1) . ' MB';
    $msg .= $info['version'] ? ', Version ' . $info['version'] . ')' : ', Version unbekannt)';
    if ($info['version'] && $mod['version'] && mod_version_norm($info['version']) !== mod_version_norm((string) $mod['version'])) {
        return $msg . '. Achtung: Auf dem Server läuft Version ' . $mod['version'] . '.';
    }
    return $msg . '.';
}

function mod_delete_file(string $modName): void
{
    @unlink(mod_file_path($modName));
    q('UPDATE server_mods SET file_version = NULL, file_sha256 = NULL, file_size = NULL, file_uploaded_at = NULL, file_uploaded_by = NULL WHERE mod_name = ?', [$modName]);
}

/** Status einer Mod für die Liste: 'ok', 'outdated', 'missing' */
function mod_file_state(array $mod): string
{
    if (empty($mod['file_sha256']) || !is_file(mod_file_path((string) $mod['mod_name']))) {
        return 'missing';
    }
    if ($mod['file_version'] && $mod['version'] && mod_version_norm((string) $mod['file_version']) !== mod_version_norm((string) $mod['version'])) {
        return 'outdated';
    }
    return 'ok';
}

/** ZIP an den Browser ausliefern und den Download zählen */
function mod_send_file(array $mod, array $user): never
{
    $name = (string) $mod['mod_name'];
    $file = mod_file_path($name);
    @set_time_limit(0);
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $name . '.zip"');
    header('Content-Length: ' . filesize($file));
    header('Cache-Control: private, no-store');
    q('INSERT INTO mod_downloads (mod_name, version, user_id, downloaded_at) VALUES (?, ?, ?, ?)',
        [$name, $mod['file_version'] ?: $mod['version'], $user['id'] ?? null, now()]);
    readfile($file);
    exit;
}

/** Mod-Liste aus dem Feed in die Datenbank übernehmen */
function sync_server_mods(array $mods, string $now): void
{
    $known = [];
    foreach (q('SELECT mod_name, version, hash, on_server FROM server_mods')->fetchAll() as $r) {
        $known[$r['mod_name']] = $r;
    }
    foreach ($mods as $name => $m) {
        $old = $known[$name] ?? null;
        $changed = $old === null || (string) $old['version'] !== $m['version'] || (string) $old['hash'] !== $m['hash'] || (int) $old['on_server'] === 0;
        q('INSERT INTO server_mods (mod_name, title, version, author, hash, on_server, first_seen_at, last_seen_at, changed_at)
           VALUES (?, ?, ?, ?, ?, 1, ?, ?, ?)
           ON DUPLICATE KEY UPDATE title = VALUES(title), version = VALUES(version), author = VALUES(author), hash = VALUES(hash),
               on_server = 1, last_seen_at = VALUES(last_seen_at), changed_at = IF(?, VALUES(changed_at), changed_at)', [
            $name, mb_substr($m['title'], 0, 200) ?: null, mb_substr($m['version'], 0, 30) ?: null,
            mb_substr($m['author'], 0, 200) ?: null, mb_substr($m['hash'], 0, 64) ?: null, $now, $now, $now, $changed ? 1 : 0,
        ]);
    }
    $gone = array_diff(array_keys($known), array_keys($mods));
    if ($gone) {
        q('UPDATE server_mods SET on_server = 0 WHERE mod_name IN (' . implode(',', array_fill(0, count($gone), '?')) . ')', array_values($gone));
    }
    q('DELETE FROM mod_downloads WHERE downloaded_at < NOW() - INTERVAL 180 DAY');
}
