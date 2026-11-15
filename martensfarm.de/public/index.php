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

        case 'GET fuhrpark/fahrzeug':
            $user = require_login();
            require APP_ROOT . '/src/pages/vehicle.php';
            break;

        case 'GET tiere':
            $user = require_login();
            require APP_ROOT . '/src/pages/animals.php';
            break;

        case 'GET lager':
            $user = require_login();
            require APP_ROOT . '/src/pages/storage.php';
            break;

        case 'GET finanzen':
        case 'POST finanzen':
            $user = require_permission('finance.view');
            require APP_ROOT . '/src/pages/finance.php';
            break;

        case 'GET finanzen/buchen':
        case 'POST finanzen/buchen':
            $user = require_permission('finance.book');
            require APP_ROOT . '/src/pages/finance_book.php';
            break;

        case 'GET karte.jpg':
            // Kartenbild vom Gameserver, über die App ausgeliefert: Adresse und Code bleiben auf dem Server
            require_login();
            $file = gs_map_file();
            if ($file === null) {
                http_response_code(404);
                exit;
            }
            header('Content-Type: image/jpeg');
            header('Content-Length: ' . filesize($file));
            header('Cache-Control: private, max-age=3600');
            header('Last-Modified: ' . gmdate('D, d M Y H:i:s', (int) filemtime($file)) . ' GMT');
            readfile($file);
            exit;

        case 'GET aufgaben':
        case 'POST aufgaben':
            $user = require_login();
            require APP_ROOT . '/src/pages/tasks.php';
            break;

        case 'POST aufgaben/verschieben':
            // Drag & Drop auf dem Board (assets/tasks.js), Antwort als JSON
            $user = require_login();
            header('Content-Type: application/json; charset=utf-8');
            $sent = (string) ($_POST['_csrf'] ?? '');
            if ($sent === '' || !hash_equals(csrf_token(), $sent)) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'message' => 'Formular abgelaufen, bitte Seite neu laden.']);
                exit;
            }
            [$ok, $msg] = task_move_request($user, (int) ($_POST['id'] ?? 0), (string) ($_POST['status'] ?? ''));
            if ($ok && $msg !== '') {
                flash('success', $msg);
            }
            http_response_code($ok ? 200 : 403);
            echo json_encode(['ok' => $ok, 'message' => $msg]);
            exit;

        case 'GET aufgaben/neu':
        case 'POST aufgaben/neu':
            $user = require_permission('tasks.create');
            require APP_ROOT . '/src/pages/task_new.php';
            break;

        case 'GET aufgaben/aufgabe':
        case 'POST aufgaben/aufgabe':
            $user = require_login();
            require APP_ROOT . '/src/pages/task.php';
            break;

        case 'GET lohnauftraege':
            $user = require_login();
            require APP_ROOT . '/src/pages/contracts.php';
            break;

        case 'GET lohnauftraege/neu':
        case 'POST lohnauftraege/neu':
        case 'GET lohnauftraege/auftrag':
        case 'POST lohnauftraege/auftrag':
            $user = require_login();
            require APP_ROOT . '/src/pages/contract.php';
            break;

        case 'GET nachrichten':
        case 'POST nachrichten':
            $user = require_login();
            require APP_ROOT . '/src/pages/board.php';
            break;

        case 'GET nachrichten/beitrag':
        case 'POST nachrichten/beitrag':
            $user = require_login();
            require APP_ROOT . '/src/pages/board_post.php';
            break;

        case 'GET downloads':
        case 'POST downloads':
            $user = require_permission('downloads.view');
            require APP_ROOT . '/src/pages/downloads.php';
            break;

        case 'GET downloads/datei':
            // Hochgeladene ZIP ausliefern: liegt außerhalb von public/, nur mit Recht
            $user = require_permission('downloads.view');
            $name = (string) ($_GET['mod'] ?? '');
            $mod = mod_name_valid($name) ? (q('SELECT * FROM server_mods WHERE mod_name = ?', [$name])->fetch() ?: null) : null;
            if ($mod === null || mod_file_state($mod) === 'missing' || ((int) $mod['is_hidden'] === 1 && !can($user, 'downloads.manage'))) {
                http_response_code(404);
                render('error', ['title' => 'Nicht gefunden', 'message' => 'Für diese Mod gibt es keine Datei.']);
                break;
            }
            session_write_close();   // lange Downloads sollen andere Seiten nicht blockieren
            mod_send_file($mod, $user);

        case 'GET preise':
            $user = require_login();
            require APP_ROOT . '/src/pages/prices.php';
            break;

        case 'POST preise':
            // Aktuellen Spielmonat setzen (der Server liefert ihn nicht mit)
            $user = require_permission('game.calendar');
            csrf_check();
            $p = (string) ($_POST['period'] ?? '');
            setting_set('current_period', in_array($p, PERIOD_ORDER, true) ? $p : null);
            $maxDay = max(1, (int) setting_get('days_per_period', '1'));
            setting_set('current_day_in_period', (string) min($maxDay, max(1, (int) ($_POST['day'] ?? 1))));
            flash('success', 'Spielmonat gespeichert.');
            redirect('preise');

        case 'GET admin/nutzer':
        case 'POST admin/nutzer':
            $user = require_permission('admin.users');
            require APP_ROOT . '/src/pages/users.php';
            break;

        case 'GET admin/rollen':
        case 'POST admin/rollen':
            $user = require_permission('admin.roles');
            require APP_ROOT . '/src/pages/roles.php';
            break;

        case 'GET admin/einstellungen':
        case 'POST admin/einstellungen':
            $user = require_permission('admin.settings');
            require APP_ROOT . '/src/pages/settings.php';
            break;

        case 'GET admin/buchungssaetze':
        case 'POST admin/buchungssaetze':
            $user = require_permission('finance.manage');
            require APP_ROOT . '/src/pages/booking_categories.php';
            break;

        case 'POST admin/import':
            $user = require_permission('admin.settings');
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
