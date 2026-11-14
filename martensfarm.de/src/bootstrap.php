<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));

$configFile = APP_ROOT . '/config/config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    exit('config/config.php fehlt – bitte aus config.example.php anlegen.');
}
$GLOBALS['config'] = require $configFile;

date_default_timezone_set(config('app.timezone', 'Europe/Berlin'));

if (config('app.debug', false)) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
}

require APP_ROOT . '/src/helpers.php';
require APP_ROOT . '/src/db.php';
require APP_ROOT . '/src/auth.php';
require APP_ROOT . '/src/permissions.php';
require APP_ROOT . '/src/mailer.php';
require APP_ROOT . '/src/gamedata.php';
require APP_ROOT . '/src/farmdata.php';
require APP_ROOT . '/src/finance.php';
require APP_ROOT . '/src/downloads.php';

/** Zugriff auf Konfigurationswerte per Punkt-Notation, z. B. config('db.host') */
function config(string $key, mixed $default = null): mixed
{
    $value = $GLOBALS['config'];
    foreach (explode('.', $key) as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            return $default;
        }
        $value = $value[$part];
    }
    return $value;
}
