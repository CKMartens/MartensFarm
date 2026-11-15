<?php
declare(strict_types=1);

// Cronjob: holt alle paar Minuten die Daten vom Gameserver.
// KeyHelp → Cronjobs, alle 5 Minuten:
//   /usr/bin/keyhelp-php85 /home/users/cmartens/www/martensfarm.de/cron/import.php
// Nur über die Kommandozeile ausführbar.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require dirname(__DIR__) . '/src/bootstrap.php';

// Überlappende Läufe verhindern
$lock = fopen(sys_get_temp_dir() . '/martensfarm-import.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "Import läuft bereits.\n");
    exit(0);
}

$force = in_array('--force', $argv, true);
try {
    foreach (run_import($force) as $line) {
        echo date('Y-m-d H:i:s') . ' ' . $line . PHP_EOL;
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Import-Fehler: ' . $e->getMessage() . PHP_EOL);
    $failed = true;
}

// Mods aus dem FTP-Eingang (var/mods-inbox/) übernehmen, unabhängig davon, ob der Gameserver erreichbar war
try {
    foreach (mods_inbox_auto_import() as $line) {
        echo date('Y-m-d H:i:s') . ' ' . $line . PHP_EOL;
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'FTP-Eingang: ' . $e->getMessage() . PHP_EOL);
}

flock($lock, LOCK_UN);
exit(isset($failed) ? 1 : 0);
