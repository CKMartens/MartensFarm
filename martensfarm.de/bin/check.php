<?php
declare(strict_types=1);

/**
 * Systemcheck (nur Kommandozeile):  keyhelp-php85 bin/check.php
 * Prüft PHP-Erweiterungen, Datenbank, Mailfunktion und ob der Gameserver erreichbar ist.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
require dirname(__DIR__) . '/src/bootstrap.php';

$ok = true;
$line = function (bool $good, string $msg) use (&$ok) {
    echo ($good ? '[ OK ] ' : '[FEHL] ') . $msg . PHP_EOL;
    $ok = $ok && $good;
};

$line(PHP_VERSION_ID >= 80200, 'PHP-Version ' . PHP_VERSION . ' (mind. 8.2)');
foreach (['pdo_mysql', 'curl', 'simplexml', 'mbstring', 'openssl'] as $ext) {
    $line(extension_loaded($ext), "Erweiterung {$ext}");
}
$line(!str_contains((string) config('gameserver.code'), 'HIER_'), 'Server-Code eingetragen');
$line(str_starts_with((string) config('app.base_url'), 'https://'), 'base_url nutzt https');

try {
    $tables = db()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $line(in_array('users', $tables, true), 'Datenbank erreichbar, Tabellen vorhanden (' . count($tables) . ')');
} catch (Throwable $e) {
    $line(false, 'Datenbank: ' . $e->getMessage());
}

try {
    $stats = parse_stats(gs_stats());
    $line(true, 'Gameserver erreichbar: ' . $stats['server_name'] . ' (' . $stats['map_name'] . ')');
} catch (Throwable $e) {
    $m = $e->getMessage();
    $hint = str_contains($m, '(401)') || str_contains($m, '(403)')
        ? ' – Server erreicht, aber der Code stimmt nicht.'
        : ' – evtl. blockiert eine Firewall ausgehende Verbindungen auf diesen Port.';
    $line(false, 'Gameserver: ' . $m . $hint);
}

$line(function_exists('mail'), 'mail() verfügbar');

echo $ok ? "\nAlles bereit.\n" : "\nBitte die Punkte mit [FEHL] beheben.\n";
exit($ok ? 0 : 1);
