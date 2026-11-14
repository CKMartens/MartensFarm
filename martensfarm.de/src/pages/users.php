<?php
declare(strict_types=1);
/** @var array $user */

$farms = q('SELECT farm_id, name FROM farms ORDER BY farm_id')->fetchAll(PDO::FETCH_KEY_PAIR);
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);

    $name     = trim((string) ($_POST['name'] ?? ''));
    $nickname = trim((string) ($_POST['nickname'] ?? ''));
    $email    = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
    $role     = (string) ($_POST['role'] ?? 'mitarbeiter');
    $farmId   = ($_POST['farm_id'] ?? '') === '' ? null : (int) $_POST['farm_id'];

    if (in_array($action, ['create', 'update'], true)) {
        if ($name === '' || mb_strlen($name) > 100) {
            $errors[] = 'Name fehlt oder ist zu lang.';
        }
        if (!preg_match('/^[\p{L}\p{N}_\-\.\[\] ]{2,50}$/u', $nickname)) {
            $errors[] = 'Nickname: 2–50 Zeichen (Buchstaben, Zahlen, _ - . [ ]).';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Die E-Mail-Adresse ist ungültig.';
        }
        if (!isset(ROLE_NAMES[$role])) {
            $errors[] = 'Unbekannte Rolle.';
        }
        if ($farmId !== null && !isset($farms[$farmId])) {
            $errors[] = 'Unbekannter Hof.';
        }
        // Sich selbst nicht aussperren
        if ($action === 'update' && $id === (int) $user['id'] && $role !== 'admin') {
            $errors[] = 'Du kannst dir die Admin-Rolle nicht selbst entziehen.';
        }
        $dupe = q('SELECT id FROM users WHERE (email = ? OR nickname = ?) AND id <> ?', [$email, $nickname, $id])->fetch();
        if ($dupe) {
            $errors[] = 'E-Mail oder Nickname ist schon vergeben.';
        }
    }

    if (!$errors) {
        switch ($action) {
            case 'create':
                q('INSERT INTO users (name, nickname, email, role, farm_id) VALUES (?, ?, ?, ?, ?)', [$name, $nickname, $email, $role, $farmId]);
                flash('success', "{$nickname} wurde angelegt. Anmelden geht ab sofort per E-Mail-Link.");
                redirect('admin/nutzer');
            case 'update':
                q('UPDATE users SET name = ?, nickname = ?, email = ?, role = ?, farm_id = ? WHERE id = ?', [$name, $nickname, $email, $role, $farmId, $id]);
                flash('success', "{$nickname} wurde gespeichert.");
                redirect('admin/nutzer');
            case 'toggle':
                if ($id === (int) $user['id']) {
                    flash('error', 'Du kannst dich nicht selbst deaktivieren.');
                } else {
                    q('UPDATE users SET is_active = 1 - is_active WHERE id = ?', [$id]);
                    // Offene Anmelde-Links sofort entwerten
                    q('UPDATE login_tokens SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL', [$id]);
                    flash('success', 'Status geändert.');
                }
                redirect('admin/nutzer');
            case 'farm_name':
                $fid = (int) ($_POST['farm_id'] ?? 0);
                $fname = trim((string) ($_POST['farm_label'] ?? ''));
                if (isset($farms[$fid]) && $fname !== '' && mb_strlen($fname) <= 100) {
                    q('UPDATE farms SET name = ? WHERE farm_id = ?', [$fname, $fid]);
                    flash('success', 'Hofname gespeichert.');
                }
                redirect('admin/nutzer');
        }
    }
}

$users = q('SELECT u.*, f.name AS farm_name FROM users u LEFT JOIN farms f ON f.farm_id = u.farm_id ORDER BY u.is_active DESC, u.nickname')->fetchAll();
$edit = isset($_GET['bearbeiten']) ? q('SELECT * FROM users WHERE id = ?', [(int) $_GET['bearbeiten']])->fetch() : null;

render('users', compact('user', 'users', 'farms', 'errors', 'edit') + ['title' => 'Nutzerverwaltung', 'nav' => 'nutzer', 'old' => $_POST]);
