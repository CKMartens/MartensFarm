<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

// Sicherheits-Header für jede Seite
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'self'");
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('X-Frame-Options: DENY');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');

session_start_secure();

$path   = trim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/', '/');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    switch ("{$method} {$path}") {
        // ---------- Anmeldung ----------
        case 'GET login':
            if (current_user()) {
                redirect('');
            }
            render('login', ['title' => 'Anmelden'], false);
            break;

        case 'POST login':
            csrf_check();
            $email = (string) ($_POST['email'] ?? '');
            if (filter_var(trim($email), FILTER_VALIDATE_EMAIL)) {
                request_login($email);
            }
            // Immer dieselbe Antwort – verrät nicht, ob die Adresse bekannt ist
            render('login_sent', ['title' => 'Mail unterwegs'], false);
            break;

        case 'POST login/code':
            csrf_check();
            if (login_with_code((string) ($_POST['code'] ?? ''))) {
                redirect('');
            }
            render('login_sent', ['title' => 'Mail unterwegs', 'error' => 'Der Code passt nicht oder ist abgelaufen.'], false);
            break;

        case 'GET login/link':
            // Nur Bestätigungsseite anzeigen – erst der Klick auf den Button meldet an
            render('login_confirm', ['title' => 'Anmeldung bestätigen', 'token' => (string) ($_GET['t'] ?? '')], false);
            break;

        case 'POST login/link':
            csrf_check();
            if (login_with_token((string) ($_POST['t'] ?? ''))) {
                redirect('');
            }
            render('error', ['title' => 'Link ungültig', 'message' => 'Der Anmelde-Link ist abgelaufen oder wurde schon benutzt. Fordere einfach einen neuen an.', 'loginLink' => true], false);
            break;

        case 'POST logout':
            csrf_check();
            logout();
            redirect('login');

        // ---------- Seiten ----------
        case 'GET ':
            $user = require_login();
            require APP_ROOT . '/src/pages/dashboard.php';
            break;

        case 'GET fuhrpark':
            $user = require_login();
            require APP_ROOT . '/src/pages/fleet.php';
            break;

        case 'GET preise':
            $user = require_login();
            require APP_ROOT . '/src/pages/prices.php';
            break;

        case 'POST preise':
            // Aktuellen Spielmonat setzen (der Server liefert ihn nicht mit)
            $user = require_role('hofleiter');
            csrf_check();
            $p = (string) ($_POST['period'] ?? '');
            setting_set('current_period', in_array($p, PERIOD_ORDER, true) ? $p : null);
            $maxDay = max(1, (int) setting_get('days_per_period', '1'));
            setting_set('current_day_in_period', (string) min($maxDay, max(1, (int) ($_POST['day'] ?? 1))));
            flash('success', 'Spielmonat gespeichert.');
            redirect('preise');

        case 'GET admin/nutzer':
        case 'POST admin/nutzer':
            $user = require_role('admin');
            require APP_ROOT . '/src/pages/users.php';
            break;

        case 'POST admin/import':
            $user = require_role('admin');
            csrf_check();
            $log = run_import(true);
            flash('info', 'Import: ' . implode(' ', $log));
            redirect('');

        default:
            http_response_code(404);
            render('error', ['title' => 'Nicht gefunden', 'message' => 'Diese Seite gibt es nicht.'], current_user() !== null);
    }
} catch (Throwable $e) {
    error_log('MartensFarm: ' . $e);
    http_response_code(500);
    $msg = config('app.debug') ? $e->getMessage() : 'Da ist etwas schiefgelaufen. Der Fehler wurde protokolliert.';
    render('error', ['title' => 'Fehler', 'message' => $msg], false);
}
