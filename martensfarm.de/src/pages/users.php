<?php
declare(strict_types=1);
/** @var array $user */

$farms = active_farms();
$roles = roles_all();
$errors = [];
$isAdmin = is_admin($user);

/** Admins und die Admin-Rolle fasst nur ein Admin an (sonst könnte sich jemand mit „Nutzer verwalten“ hochstufen) */
$guardAdmin = function (?array $target, ?string $newRole = null) use ($isAdmin): ?string {
    if ($isAdmin) {
        return null;
    }
    if (($target && $target['role'] === ADMIN_ROLE) || $newRole === ADMIN_ROLE) {
        return 'Admins und die Admin-Rolle kann nur ein Admin bearbeiten.';
    }
    return null;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);
    $target = $id > 0 ? (q('SELECT * FROM users WHERE id = ?', [$id])->fetch() ?: null) : null;

    $name     = trim((string) ($_POST['name'] ?? ''));
    $nickname = trim((string) ($_POST['nickname'] ?? ''));
    $email    = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
    $role     = (string) ($_POST['role'] ?? 'mitarbeiter');
    $farmId   = ($_POST['farm_id'] ?? '') === '' ? null : (int) $_POST['farm_id'];

    if (in_array($action, ['update', 'toggle', 'anonymize'], true) && $target === null) {
        $errors[] = 'Diesen Nutzer gibt es nicht.';
    }

    if (!$errors && in_array($action, ['create', 'update'], true)) {
        if ($name === '' || mb_strlen($name) > 100) {
            $errors[] = 'Name fehlt oder ist zu lang.';
        }
        if (!preg_match('/^[\p{L}\p{N}_\-\.\[\] ]{2,50}$/u', $nickname)) {
            $errors[] = 'Nickname: 2–50 Zeichen (Buchstaben, Zahlen, _ - . [ ]).';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Die E-Mail-Adresse ist ungültig.';
        }
        if (!isset($roles[$role])) {
            $errors[] = 'Unbekannte Rolle.';
        }
        if ($farmId !== null && !isset($farms[$farmId])) {
            $errors[] = 'Unbekannter Hof.';
        }
        if ($msg = $guardAdmin($target, $role)) {
            $errors[] = $msg;
        }
        // Sich selbst nicht aussperren, und der letzte aktive Admin bleibt Admin
        if ($action === 'update' && $target && $target['role'] === ADMIN_ROLE && $role !== ADMIN_ROLE) {
            if ($id === (int) $user['id']) {
                $errors[] = 'Du kannst dir die Admin-Rolle nicht selbst entziehen.';
            } elseif ((int) $target['is_active'] === 1 && active_admin_count() <= 1) {
                $errors[] = 'Das ist der letzte aktive Admin. Mach erst jemand anderen zum Admin.';
            }
        }
        $dupe = q('SELECT id FROM users WHERE (email = ? OR nickname = ?) AND id <> ?', [$email, $nickname, $id])->fetch();
        if ($dupe) {
            $errors[] = 'E-Mail oder Nickname ist schon vergeben.';
        }
    }

    if (!$errors && in_array($action, ['toggle', 'anonymize'], true)) {
        if ($msg = $guardAdmin($target)) {
            $errors[] = $msg;
        } elseif ($id === (int) $user['id']) {
            $errors[] = 'Das geht nicht mit dem eigenen Konto.';
        }
    }

    if (!$errors) {
        switch ($action) {
            case 'create':
                q('INSERT INTO users (name, nickname, email, role, farm_id) VALUES (?, ?, ?, ?, ?)', [$name, $nickname, $email, $role, $farmId]);
                flash('success', "{$nickname} wurde angelegt. Anmelden geht ab sofort per E-Mail-Link.");
                redirect('admin/nutzer');
            case 'update':
                // Mit neuer E-Mail ist ein anonymisierter Nutzer wieder ein normaler Nutzer
                q('UPDATE users SET name = ?, nickname = ?, email = ?, role = ?, farm_id = ?, anonymized_at = NULL WHERE id = ?', [$name, $nickname, $email, $role, $farmId, $id]);
                flash('success', "{$nickname} wurde gespeichert.");
                redirect('admin/nutzer');
            case 'toggle':
                if ((int) $target['is_active'] === 0 && $target['email'] === null) {
                    flash('error', 'Der Nutzer ist anonymisiert. Trag erst wieder eine E-Mail-Adresse ein.');
                } elseif ((int) $target['is_active'] === 1 && $target['role'] === ADMIN_ROLE && active_admin_count() <= 1) {
                    flash('error', 'Das ist der letzte aktive Admin, der bleibt aktiv.');
                } else {
                    q('UPDATE users SET is_active = 1 - is_active WHERE id = ?', [$id]);
                    // Offene Anmelde-Links sofort entwerten
                    q('UPDATE login_tokens SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL', [$id]);
                    flash('success', (int) $target['is_active'] === 1 ? "{$target['nickname']} ist gesperrt." : "{$target['nickname']} ist wieder freigegeben.");
                }
                redirect('admin/nutzer');
            case 'anonymize':
                if ((int) $target['is_active'] === 1) {
                    flash('error', 'Bitte den Nutzer erst sperren.');
                } elseif (($_POST['confirm'] ?? '') !== '1') {
                    flash('error', 'Bitte das Häkchen zur Bestätigung setzen.');
                    redirect('admin/nutzer?bearbeiten=' . $id);
                } else {
                    // Datensatz bleibt (Buchungen und Aufgaben behalten ihre Person), nur persönliche Daten fliegen raus
                    q("UPDATE users SET name = 'Ehemaliger Nutzer', email = NULL, farm_id = NULL, anonymized_at = NOW() WHERE id = ?", [$id]);
                    q('DELETE FROM login_tokens WHERE user_id = ?', [$id]);
                    if ($target['email'] !== null) {
                        q('DELETE FROM login_requests WHERE email = ?', [$target['email']]);
                    }
                    flash('success', "{$target['nickname']} wurde anonymisiert.");
                }
                redirect('admin/nutzer');
            case 'farm_name':
                $fid = (int) ($_POST['farm_id'] ?? 0);
                $fname = trim((string) ($_POST['farm_label'] ?? ''));
                if (isset($farms[$fid]) && $fname !== '' && mb_strlen($fname) <= 100) {
                    q('UPDATE farms SET name = ?, name_locked = 1 WHERE farm_id = ?', [$fname, $fid]);
                    flash('success', 'Hofname gespeichert.');
                }
                redirect('admin/nutzer');
        }
    }
}

$filter = in_array($_GET['zeigen'] ?? '', ['aktiv', 'gesperrt', 'alle'], true) ? $_GET['zeigen'] : 'aktiv';
$counts = q('SELECT SUM(is_active = 1) AS aktiv, SUM(is_active = 0) AS gesperrt, COUNT(*) AS alle FROM users')->fetch();
$where = ['aktiv' => 'WHERE u.is_active = 1', 'gesperrt' => 'WHERE u.is_active = 0', 'alle' => ''][$filter];
$users = q("SELECT u.*, f.name AS farm_name FROM users u LEFT JOIN farms f ON f.farm_id = u.farm_id {$where} ORDER BY u.is_active DESC, u.nickname")->fetchAll();
$edit = isset($_GET['bearbeiten']) ? (q('SELECT * FROM users WHERE id = ?', [(int) $_GET['bearbeiten']])->fetch() ?: null) : null;

// Wer kein Admin ist, bekommt die Admin-Rolle gar nicht erst angeboten
$roleChoices = $isAdmin ? $roles : array_diff_key($roles, [ADMIN_ROLE => true]);

render('users', compact('user', 'users', 'farms', 'errors', 'edit', 'roles', 'roleChoices', 'filter', 'counts', 'isAdmin') + ['title' => 'Nutzerverwaltung', 'nav' => 'nutzer', 'old' => $_POST]);
