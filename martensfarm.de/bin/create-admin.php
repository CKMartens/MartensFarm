<?php
declare(strict_types=1);

/**
 * Ersten Admin anlegen (nur Kommandozeile):
 *   keyhelp-php85 bin/create-admin.php "Carsten Martens" "[MF]Carsten" carsten@example.de
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
require dirname(__DIR__) . '/src/bootstrap.php';

[$script, $name, $nick, $email] = array_pad($argv, 4, null);
if (!$name || !$nick || !$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Aufruf: php bin/create-admin.php \"Name\" \"Nickname\" email@domain.de\n");
    exit(1);
}

$email = mb_strtolower(trim($email));
$exists = q('SELECT id FROM users WHERE email = ?', [$email])->fetch();
if ($exists) {
    q("UPDATE users SET role = 'admin', is_active = 1 WHERE id = ?", [$exists['id']]);
    echo "Bestehender Nutzer {$email} ist jetzt Admin.\n";
} else {
    q("INSERT INTO users (name, nickname, email, role) VALUES (?, ?, ?, 'admin')", [$name, $nick, $email]);
    echo "Admin {$nick} <{$email}> angelegt. Anmelden unter " . url('login') . "\n";
}
