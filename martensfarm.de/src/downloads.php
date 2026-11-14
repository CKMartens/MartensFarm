<?php
declare(strict_types=1);

/**
 * Mod-Downloads.
 *
 * Die Liste kommt automatisch aus dem Stats-Feed des Gameservers (<Mods>), die Dateien
 * holt die App beim Klick direkt vom Server und reicht sie durch. Die Download-Adresse des
 * Servers landet so nie im Browser, und nur angemeldete Nutzer mit Recht „downloads.view“ kommen ran.
 */

/** Mod-Namen wie im Spiel: FS25_Irgendwas, nur Buchstaben, Ziffern, _ und - */
function mod_name_valid(string $name): bool
{
    return strlen($name) <= 100 && preg_match('/^[A-Za-z0-9][A-Za-z0-9_\-]*$/', $name) === 1;
}

/**
 * Basis-Adresse für Mod-Downloads (endet vor dem Dateinamen, ohne / am Ende).
 * Vorrang: Einstellungen → config.php (gameserver.mods_url) → Web-Interface des Servers + /mods.
 */
function mods_base_url(): string
{
    $v = trim((string) setting_get('mods_base_url', ''));
    if ($v === '') {
        $v = trim((string) config('gameserver.mods_url', ''));
    }
    if ($v === '' || str_contains($v, 'IP-DES-GAMESERVERS')) {
        $gs = gs_base_url();
        $v = $gs !== '' ? $gs . '/mods' : '';
    }
    return rtrim($v, '/');
}

/** Woher die Download-Adresse kommt: 'settings', 'config' oder 'gameserver' */
function mods_source(): string
{
    if (trim((string) setting_get('mods_base_url', '')) !== '') {
        return 'settings';
    }
    $c = trim((string) config('gameserver.mods_url', ''));
    return $c !== '' && !str_contains($c, 'IP-DES-GAMESERVERS') ? 'config' : 'gameserver';
}

/**
 * Eingabe prüfen: http(s)-Adresse mit Pfad, ohne Zugangsdaten/Query. Ein kompletter Link auf eine
 * Mod (…/mods/FS25_Irgendwas.zip) ist auch ok, der Dateiname wird abgeschnitten. Null = ungültig.
 */
function mods_normalize_url(string $url): ?string
{
    $url = trim($url);
    $p = parse_url($url);
    if (!$p || !in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true) || empty($p['host'])
        || isset($p['user']) || isset($p['pass']) || isset($p['query']) || isset($p['fragment'])) {
        return null;
    }
    $path = (string) ($p['path'] ?? '');
    if (preg_match('~/[^/]+\.zip$~i', $path)) {
        $path = (string) preg_replace('~/[^/]+\.zip$~i', '', $path);
    }
    if (preg_match('~[^A-Za-z0-9/._\-]~', $path)) {
        return null;
    }
    return strtolower($p['scheme']) . '://' . strtolower($p['host']) . (isset($p['port']) ? ':' . (int) $p['port'] : '') . rtrim($path, '/');
}

/** Adresse für die Anzeige: Host und Pfad weglassen, damit sie auf keinem Screenshot landet */
function mods_mask_url(string $url): string
{
    $p = parse_url($url);
    if (!$p || empty($p['host'])) {
        return '';
    }
    return ($p['scheme'] ?? 'https') . '://…/' . basename((string) ($p['path'] ?? ''));
}

function mod_download_url(string $modName, ?string $base = null): string
{
    return ($base ?? mods_base_url()) . '/' . rawurlencode($modName) . '.zip';
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

/**
 * Mod vom Server holen und direkt an den Browser durchreichen (ohne Zwischenspeichern).
 * Gibt bei Erfolg nichts zurück und beendet, sonst eine Fehlermeldung für die Seite.
 */
function mod_stream(array $mod, ?array $user): string
{
    $base = mods_base_url();
    if ($base === '') {
        return 'Für Downloads ist keine Server-Adresse eingetragen.';
    }
    @set_time_limit(0);
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    $name = (string) $mod['mod_name'];
    $status = 0;
    $length = null;
    $started = false;
    $maxBytes = (int) config('downloads.max_mb', 1024) * 1024 * 1024;

    $ch = curl_init(mod_download_url($name, $base));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER  => false,
        CURLOPT_FOLLOWLOCATION  => true,
        CURLOPT_MAXREDIRS       => 3,
        CURLOPT_PROTOCOLS       => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_CONNECTTIMEOUT  => 10,
        CURLOPT_LOW_SPEED_LIMIT => 1024,  // abbrechen, wenn 60 s lang weniger als 1 KB/s kommt
        CURLOPT_LOW_SPEED_TIME  => 60,
        CURLOPT_USERAGENT       => 'MartensFarm-Downloads/1.0',
        CURLOPT_HEADERFUNCTION  => function ($ch, string $line) use (&$status, &$length): int {
            if (preg_match('~^HTTP/\S+\s+(\d{3})~', $line, $m)) {
                $status = (int) $m[1];   // bei Weiterleitungen gilt die letzte Antwort
                $length = null;
            } elseif (preg_match('~^Content-Length:\s*(\d+)~i', $line, $m)) {
                $length = (int) $m[1];
            }
            return strlen($line);
        },
        CURLOPT_WRITEFUNCTION   => function ($ch, string $chunk) use (&$status, &$length, &$started, $name, $maxBytes): int {
            if (!$started) {
                if ($status !== 200 || ($length !== null && $length > $maxBytes)) {
                    return -1;   // abbrechen, Fehlerseite statt halber Datei
                }
                $started = true;
                header('Content-Type: application/zip');
                header('Content-Disposition: attachment; filename="' . $name . '.zip"');
                header('Cache-Control: private, no-store');
                if ($length !== null) {
                    header('Content-Length: ' . $length);
                }
            }
            echo $chunk;
            flush();
            return strlen($chunk);
        },
    ]);
    $ok = curl_exec($ch);
    $err = curl_error($ch);
    unset($ch);

    if ($started) {
        if ($ok) {
            q('INSERT INTO mod_downloads (mod_name, version, user_id, downloaded_at) VALUES (?, ?, ?, ?)',
                [$name, $mod['version'], $user['id'] ?? null, now()]);
        } else {
            error_log('MartensFarm: Download ' . $name . ' abgebrochen: ' . $err);
        }
        exit;
    }
    if ($status === 200 && $length !== null && $length > $maxBytes) {
        return 'Die Datei ist größer als erlaubt (' . (int) config('downloads.max_mb', 1024) . ' MB).';
    }
    if ($status === 404) {
        return 'Der Server bietet diese Mod nicht zum Download an (404). Ist die Adresse unter Einstellungen → Mod-Downloads richtig?';
    }
    // Keine Adresse in die Meldung, die landet auf der Seite
    $host = (string) parse_url($base, PHP_URL_HOST);
    $err = $host !== '' ? str_replace($host, 'Gameserver', $err) : $err;
    return trim('Download klappt gerade nicht' . ($status ? " ({$status})" : '') . '. ' . $err);
}

/** Erreichbarkeit prüfen: HEAD auf eine Mod. Gibt [ok, Meldung] zurück. */
function mods_test(string $base, string $modName): array
{
    $ch = curl_init(mod_download_url($modName, $base));
    curl_setopt_array($ch, [
        CURLOPT_NOBODY          => true,
        CURLOPT_RETURNTRANSFER  => true,
        CURLOPT_FOLLOWLOCATION  => true,
        CURLOPT_MAXREDIRS       => 3,
        CURLOPT_PROTOCOLS       => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_CONNECTTIMEOUT  => 5,
        CURLOPT_TIMEOUT         => 15,
        CURLOPT_USERAGENT       => 'MartensFarm-Downloads/1.0',
    ]);
    curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $size = (float) curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
    $err = curl_error($ch);
    unset($ch);
    if ($status === 200) {
        return [true, $modName . '.zip ist erreichbar' . ($size > 0 ? ' (' . fmt_num($size / 1048576, 1) . ' MB)' : '') . '.'];
    }
    $host = (string) parse_url($base, PHP_URL_HOST);
    $err = $host !== '' ? str_replace($host, 'Gameserver', $err) : $err;
    return [false, trim($modName . '.zip nicht erreichbar' . ($status ? " ({$status})" : '') . '. ' . $err)];
}
