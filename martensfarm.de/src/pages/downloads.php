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
    $action = (string) ($_POST['action'] ?? '');
    // FTP-Eingang: Datei übernehmen oder löschen
    if (str_starts_with($action, 'inbox_')) {
        try {
            $path = mods_inbox_path((string) ($_POST['file'] ?? ''));
            if ($path === null) {
                throw new RuntimeException('Diese Datei liegt nicht (mehr) im Eingang.');
            }
            if ($action === 'inbox_delete') {
                mod_inbox_delete($path);
                flash('success', basename($path) . ' aus dem Eingang gelöscht.');
            } elseif ($action === 'inbox_take') {
                $target = (string) ($_POST['mod'] ?? '');
                $mod = mod_name_valid($target) ? (q('SELECT * FROM server_mods WHERE mod_name = ?', [$target])->fetch() ?: null) : null;
                if ($mod === null) {
                    throw new RuntimeException('Bitte eine Mod aus der Liste auswählen.');
                }
                flash('success', mod_take_from_inbox($mod, $path, (int) $user['id'], true, isset($_POST['private'])));
            }
        } catch (RuntimeException $e) {
            flash('error', $e->getMessage());
        }
        redirect('downloads');
    }
    $name = (string) ($_POST['mod'] ?? '');
    $mod = mod_name_valid($name) ? (q('SELECT * FROM server_mods WHERE mod_name = ?', [$name])->fetch() ?: null) : null;
    if ($mod === null) {
        flash('error', 'Diese Mod gibt es nicht.');
        redirect('downloads');
    }
    try {
        if ($action === 'upload') {
            $upload = (array) ($_FILES['file'] ?? []);
            if (isset($_POST['force'])) {
                $upload['force'] = true;
            }
            $upload['private'] = isset($_POST['private']);
            flash('success', mod_store_upload($mod, $upload, $user));
        } elseif ($action === 'delete') {
            mod_delete_file($mod['mod_name']);
            flash('success', 'Datei von ' . $mod['mod_name'] . ' gelöscht.');
        } elseif ($action === 'approve') {
            q('UPDATE server_mods SET is_approved = 1, is_private = ? WHERE mod_name = ?', [isset($_POST['private']) ? 1 : 0, $mod['mod_name']]);
            flash('success', $mod['mod_name'] . ' freigegeben' . (isset($_POST['private']) ? ' (private Mod)' : '') . ', die Mitspieler sehen sie jetzt.');
        } elseif ($action === 'private') {
            q('UPDATE server_mods SET is_private = 1 - is_private WHERE mod_name = ?', [$mod['mod_name']]);
            flash('success', $mod['mod_name'] . ((int) $mod['is_private'] === 1 ? ' ist keine private Mod mehr.' : ' ist jetzt als private Mod markiert.'));
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
    if ((int) $m['is_approved'] !== 1 && $m['state'] !== 'missing') {
        continue;   // wartet auf Freigabe: nur im Abschnitt „Per FTP bereitgestellt“ für Verwalter
    }
    if ($m['state'] !== 'missing' && ((int) $m['on_server'] === 1 || $canManage) && ((int) $m['is_hidden'] === 0 || $canManage)) {
        $mods[] = $m;
    } elseif ($canManage && $m['state'] === 'missing' && (int) $m['on_server'] === 1) {
        $rest[] = $m;
    }
}
$limitMb = mods_upload_limit() / 1048576;

// FTP-Eingang nur für Verwalter: was liegt dort, passt es zu einer Mod, ist die ZIP in Ordnung?
$inbox = [];
$inboxError = null;
$modChoices = [];
$pending = $canManage ? mods_pending() : [];
if ($canManage) {
    try {
        $inbox = mods_inbox_list();
        foreach ($inbox as &$f) {
            $f['problem'] = null;
            $f['zip'] = null;
            if ($f['ready']) {
                try {
                    $f['zip'] = mod_inspect_zip($f['path']);
                } catch (RuntimeException $e) {
                    $f['problem'] = $e->getMessage();
                }
            }
        }
        unset($f);
    } catch (RuntimeException $e) {
        $inboxError = $e->getMessage();
    }
    $modChoices = q('SELECT mod_name, title, on_server FROM server_mods ORDER BY on_server DESC, COALESCE(title, mod_name)')->fetchAll();
}

render('downloads', compact('user', 'mods', 'rest', 'canManage', 'limitMb', 'inbox', 'inboxError', 'modChoices', 'pending') + ['title' => 'Downloads', 'nav' => 'downloads']);
