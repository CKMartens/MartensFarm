<?php
declare(strict_types=1);
/** @var array $user */

$canManage = can($user, 'downloads.manage');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Zu große Uploads kommen ganz ohne POST-Daten an (post_max_size), dann fehlt auch das CSRF-Feld
    if (empty($_POST) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        flash('error', 'Die Datei ist zu groß (Grenze ' . fmt_num(mods_upload_limit() / 1048576) . ' MB).');
        redirect('downloads');
    }
    csrf_check();
    if (!$canManage) {
        http_response_code(403);
        render('error', ['title' => 'Kein Zugriff', 'message' => 'Dafür fehlt dir die Berechtigung.']);
        exit;
    }
    $name = (string) ($_POST['mod'] ?? '');
    $mod = mod_name_valid($name) ? (q('SELECT * FROM server_mods WHERE mod_name = ?', [$name])->fetch() ?: null) : null;
    if ($mod === null) {
        flash('error', 'Diese Mod gibt es nicht.');
        redirect('downloads');
    }
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'upload') {
            $upload = (array) ($_FILES['file'] ?? []);
            if (isset($_POST['force'])) {
                $upload['force'] = true;
            }
            flash('success', mod_store_upload($mod, $upload, $user));
        } elseif ($action === 'delete') {
            mod_delete_file($mod['mod_name']);
            flash('success', 'Datei von ' . $mod['mod_name'] . ' gelöscht.');
        } elseif ($action === 'toggle') {
            q('UPDATE server_mods SET is_hidden = 1 - is_hidden WHERE mod_name = ?', [$mod['mod_name']]);
            flash('success', 'Sichtbarkeit von ' . $mod['mod_name'] . ' geändert.');
        } elseif ($action === 'note') {
            $note = trim(str_replace("\r\n", "\n", (string) ($_POST['note'] ?? '')));
            q('UPDATE server_mods SET note = ? WHERE mod_name = ?', [$note !== '' ? mb_substr($note, 0, 5000) : null, $mod['mod_name']]);
            flash('success', 'Hinweis zu ' . $mod['mod_name'] . ' gespeichert.');
        }
    } catch (RuntimeException $e) {
        flash('error', $e->getMessage());
    }
    redirect('downloads');
}

$all = q('SELECT m.*, (SELECT COUNT(*) FROM mod_downloads d WHERE d.mod_name = m.mod_name) AS dl_count
          FROM server_mods m WHERE m.on_server = 1 OR m.file_sha256 IS NOT NULL
          ORDER BY COALESCE(m.title, m.mod_name)')->fetchAll();
$mods = [];   // für alle: nur Mods mit Datei (= die nicht aus dem ModHub)
$rest = [];   // nur Verwalter: alles ohne Datei
foreach ($all as $m) {
    $m['state'] = mod_file_state($m);
    if ($m['state'] !== 'missing' && ((int) $m['on_server'] === 1 || $canManage) && ((int) $m['is_hidden'] === 0 || $canManage)) {
        $mods[] = $m;
    } elseif ($canManage && $m['state'] === 'missing' && (int) $m['on_server'] === 1) {
        $rest[] = $m;
    }
}
$limitMb = mods_upload_limit() / 1048576;

render('downloads', compact('user', 'mods', 'rest', 'canManage', 'limitMb') + ['title' => 'Downloads', 'nav' => 'downloads']);
