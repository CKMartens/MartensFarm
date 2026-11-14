<?php
declare(strict_types=1);

/**
 * Anmeldung ohne Passwort: Wer seine E-Mail eingibt, bekommt einen Link
 * und einen 6-stelligen Code. Beides gilt einmal und nur kurze Zeit.
 */

function session_start_secure(): void
{
    $https = str_starts_with((string) config('app.base_url'), 'https://');
    session_name('mf_session');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.gc_maxlifetime', (string) (config('login.session_hours', 72) * 3600));
    session_start();

    // Abgelaufene Anmeldung beenden
    $max = config('login.session_hours', 72) * 3600;
    if (isset($_SESSION['user_id'], $_SESSION['login_at']) && time() - $_SESSION['login_at'] > $max) {
        logout();
    }
}

function current_user(): ?array
{
    static $user = false;
    if ($user === false) {
        $user = null;
        if (!empty($_SESSION['user_id'])) {
            $row = q('SELECT u.*, f.name AS farm_name FROM users u LEFT JOIN farms f ON f.farm_id = u.farm_id WHERE u.id = ? AND u.is_active = 1', [$_SESSION['user_id']])->fetch();
            $user = $row ?: null;
            if ($user === null) {
                unset($_SESSION['user_id']);
            }
        }
    }
    return $user;
}

function require_login(): array
{
    $user = current_user();
    if ($user === null) {
        redirect('login');
    }
    return $user;
}

// Rechte prüfen: siehe src/permissions.php (can, require_permission)

/**
 * Anmelde-Mail anfordern. Gibt immer "ok" zurück, damit niemand herausfinden kann,
 * welche Adressen registriert sind. Rate-Limits schützen vor Mail-Spam über unser Formular.
 */
function request_login(string $email): void
{
    $email = mb_strtolower(trim($email));
    $ip = client_ip();

    q('DELETE FROM login_requests WHERE created_at < NOW() - INTERVAL 1 DAY');
    $perEmail = (int) q('SELECT COUNT(*) FROM login_requests WHERE email = ? AND created_at > NOW() - INTERVAL 1 HOUR', [$email])->fetchColumn();
    $perIp    = (int) q('SELECT COUNT(*) FROM login_requests WHERE request_ip = ? AND created_at > NOW() - INTERVAL 1 HOUR', [$ip])->fetchColumn();
    q('INSERT INTO login_requests (email, request_ip) VALUES (?, ?)', [$email, $ip]);

    if ($perEmail >= config('login.max_requests_email_h', 5) || $perIp >= config('login.max_requests_ip_h', 20)) {
        error_log("MartensFarm: Login-Rate-Limit erreicht für {$email} / {$ip}");
        return;
    }

    $user = q('SELECT * FROM users WHERE email = ? AND is_active = 1', [$email])->fetch();
    if (!$user) {
        return;
    }

    // Alte, unbenutzte Token dieses Nutzers entwerten
    q('UPDATE login_tokens SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL', [$user['id']]);

    $token = bin2hex(random_bytes(32));
    $code  = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $minutes = (int) config('login.token_minutes', 15);

    q('INSERT INTO login_tokens (user_id, token_hash, code_hash, expires_at, request_ip) VALUES (?, ?, ?, NOW() + INTERVAL ? MINUTE, ?)', [
        $user['id'], hash('sha256', $token), hash('sha256', $user['id'] . ':' . $code), $minutes, $ip,
    ]);

    // Für die Code-Eingabe merken, wer die Mail angefordert hat
    $_SESSION['pending_login_user'] = (int) $user['id'];

    send_login_mail($user, $token, $code, $minutes);
}

/** Login per Link. Der Link landet auf einer Bestätigungsseite (POST), damit Mail-Scanner ihn nicht "verbrauchen". */
function login_with_token(string $token): bool
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return false;
    }
    $row = q('SELECT * FROM login_tokens WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW()', [hash('sha256', $token)])->fetch();
    if (!$row) {
        return false;
    }
    q('UPDATE login_tokens SET used_at = NOW() WHERE id = ?', [$row['id']]);
    return finish_login((int) $row['user_id']);
}

/** Login per 6-stelligem Code (gleiche Browser-Sitzung wie die Anforderung) */
function login_with_code(string $code): bool
{
    $userId = (int) ($_SESSION['pending_login_user'] ?? 0);
    $code = preg_replace('/\D/', '', $code);
    if ($userId === 0 || strlen($code) !== 6) {
        return false;
    }
    $row = q('SELECT * FROM login_tokens WHERE user_id = ? AND used_at IS NULL AND expires_at > NOW() ORDER BY id DESC LIMIT 1', [$userId])->fetch();
    if (!$row) {
        return false;
    }
    if (!hash_equals($row['code_hash'], hash('sha256', $userId . ':' . $code))) {
        // Nach 5 Fehlversuchen ist der Code verbrannt
        // (MySQL wertet SET von links nach rechts aus, daher used_at zuerst)
        q('UPDATE login_tokens SET used_at = IF(code_tries + 1 >= 5, NOW(), used_at), code_tries = code_tries + 1 WHERE id = ?', [$row['id']]);
        return false;
    }
    q('UPDATE login_tokens SET used_at = NOW() WHERE id = ?', [$row['id']]);
    return finish_login($userId);
}

function finish_login(int $userId): bool
{
    $user = q('SELECT id FROM users WHERE id = ? AND is_active = 1', [$userId])->fetch();
    if (!$user) {
        return false;
    }
    session_regenerate_id(true);
    unset($_SESSION['pending_login_user']);
    $_SESSION['user_id'] = $userId;
    $_SESSION['login_at'] = time();
    q('UPDATE users SET last_login_at = NOW() WHERE id = ?', [$userId]);
    return true;
}

function logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', ['expires' => time() - 42000, 'path' => $p['path'], 'secure' => $p['secure'], 'httponly' => $p['httponly'], 'samesite' => $p['samesite']]);
    }
    session_destroy();
}
