<?php
declare(strict_types=1);
/** @var array $user */

$roles = roles_all();
$errors = [];

/** Aus „Lohnunternehmer Nord“ wird „lohnunternehmer_nord“ */
function role_slug(string $name): string
{
    $s = mb_strtolower($name);
    $s = strtr($s, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
    $s = trim((string) preg_replace('/[^a-z0-9]+/', '_', $s), '_');
    return substr($s, 0, 26) ?: 'rolle';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');

    switch ($action) {
        case 'save':
            $names = (array) ($_POST['name'] ?? []);
            $descs = (array) ($_POST['description'] ?? []);
            $perms = (array) ($_POST['perm'] ?? []);
            foreach ($roles as $key => $r) {
                $n = trim((string) ($names[$key] ?? $r['name']));
                if ($n === '' || mb_strlen($n) > 50) {
                    $errors[] = "Der Name für „{$r['name']}“ fehlt oder ist zu lang (max. 50 Zeichen).";
                }
                if (mb_strlen(trim((string) ($descs[$key] ?? ''))) > 255) {
                    $errors[] = "Die Beschreibung für „{$r['name']}“ ist zu lang (max. 255 Zeichen).";
                }
            }
            $lower = array_map(fn ($k) => mb_strtolower(trim((string) ($names[$k] ?? $roles[$k]['name']))), array_keys($roles));
            if (count($lower) !== count(array_unique($lower))) {
                $errors[] = 'Zwei Rollen dürfen nicht gleich heißen.';
            }
            if ($errors) {
                break;
            }
            db()->beginTransaction();
            try {
                foreach ($roles as $key => $r) {
                    q('UPDATE roles SET name = ?, description = ? WHERE role_key = ?', [
                        trim((string) ($names[$key] ?? $r['name'])), trim((string) ($descs[$key] ?? '')), $key,
                    ]);
                    if ($key === ADMIN_ROLE) {
                        continue; // Admin darf immer alles
                    }
                    q('DELETE FROM role_permissions WHERE role_key = ?', [$key]);
                    $chosen = array_intersect(array_map('strval', (array) ($perms[$key] ?? [])), array_keys(PERMISSIONS));
                    foreach (array_unique($chosen) as $p) {
                        q('INSERT INTO role_permissions (role_key, permission) VALUES (?, ?)', [$key, $p]);
                    }
                }
                db()->commit();
            } catch (Throwable $e) {
                db()->rollBack();
                throw $e;
            }
            flash('success', 'Rollen und Rechte gespeichert.');
            redirect('admin/rollen');

        case 'create':
            $n = trim((string) ($_POST['new_name'] ?? ''));
            $d = trim((string) ($_POST['new_description'] ?? ''));
            $from = (string) ($_POST['copy_from'] ?? '');
            if ($n === '' || mb_strlen($n) > 50) {
                $errors[] = 'Die neue Rolle braucht einen Namen (max. 50 Zeichen).';
            }
            if (mb_strlen($d) > 255) {
                $errors[] = 'Die Beschreibung ist zu lang (max. 255 Zeichen).';
            }
            foreach ($roles as $r) {
                if (mb_strtolower($r['name']) === mb_strtolower($n)) {
                    $errors[] = 'Eine Rolle mit dem Namen gibt es schon.';
                }
            }
            if ($from !== '' && !isset($roles[$from])) {
                $errors[] = 'Unbekannte Vorlage.';
            }
            if ($errors) {
                break;
            }
            $key = role_slug($n);
            for ($i = 2; isset($roles[$key]); $i++) {
                $key = role_slug($n) . '_' . $i;
            }
            $sort = (int) q('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM roles')->fetchColumn();
            q('INSERT INTO roles (role_key, name, description, sort_order, is_system) VALUES (?, ?, ?, ?, 0)', [$key, $n, $d, $sort]);
            if ($from !== '') {
                foreach (role_permissions($from) as $p) {
                    q('INSERT INTO role_permissions (role_key, permission) VALUES (?, ?)', [$key, $p]);
                }
            }
            flash('success', "Rolle „{$n}“ angelegt. Jetzt noch die Häkchen setzen und speichern.");
            redirect('admin/rollen');

        case 'delete':
            $key = (string) ($_POST['role_key'] ?? '');
            $inUse = (int) q('SELECT COUNT(*) FROM users WHERE role = ?', [$key])->fetchColumn();
            if (!isset($roles[$key])) {
                flash('error', 'Diese Rolle gibt es nicht.');
            } elseif ((int) $roles[$key]['is_system'] === 1) {
                flash('error', 'Mitgelieferte Rollen lassen sich nur umbenennen, nicht löschen.');
            } elseif ($inUse > 0) {
                flash('error', "„{$roles[$key]['name']}“ hat noch {$inUse} Nutzer (auch gesperrte zählen). Gib ihnen erst eine andere Rolle.");
            } else {
                q('DELETE FROM roles WHERE role_key = ?', [$key]);
                flash('success', "Rolle „{$roles[$key]['name']}“ gelöscht.");
            }
            redirect('admin/rollen');
    }
}

$userCounts = q('SELECT role, COUNT(*) AS n FROM users GROUP BY role')->fetchAll(PDO::FETCH_KEY_PAIR);
$granted = [];
foreach ($roles as $key => $r) {
    $granted[$key] = role_permissions($key);
}
$groups = [];
foreach (PERMISSIONS as $p => [$group, $label, $hint]) {
    $groups[$group][$p] = [$label, $hint];
}

render('roles', compact('user', 'roles', 'errors', 'userCounts', 'granted', 'groups') + ['title' => 'Rollen & Rechte', 'nav' => 'rollen', 'old' => $_POST]);
