<?php
declare(strict_types=1);
/** @var array $user */

$canManage = can($user, 'downloads.manage');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!$canManage) {
        http_response_code(403);
        render('error', ['title' => 'Kein Zugriff', 'message' => 'Dafür fehlt dir die Berechtigung.']);
        exit;
    }
    $name = (string) ($_POST['mod'] ?? '');
    $mod = mod_name_valid($name) ? (q('SELECT mod_name FROM server_mods WHERE mod_name = ?', [$name])->fetchColumn() ?: null) : null;
    if ($mod === null) {
        flash('error', 'Diese Mod gibt es nicht.');
        redirect('downloads');
    }
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'toggle') {
        q('UPDATE server_mods SET is_hidden = 1 - is_hidden WHERE mod_name = ?', [$mod]);
        flash('success', 'Sichtbarkeit von ' . $mod . ' geändert.');
    } elseif ($action === 'note') {
        $note = trim(str_replace("\r\n", "\n", (string) ($_POST['note'] ?? '')));
        q('UPDATE server_mods SET note = ? WHERE mod_name = ?', [$note !== '' ? mb_substr($note, 0, 5000) : null, $mod]);
        flash('success', 'Hinweis zu ' . $mod . ' gespeichert.');
    }
    redirect('downloads');
}

$mods = q('SELECT m.*, (SELECT COUNT(*) FROM mod_downloads d WHERE d.mod_name = m.mod_name) AS dl_count
           FROM server_mods m WHERE m.on_server = 1' . ($canManage ? '' : ' AND m.is_hidden = 0') . '
           ORDER BY COALESCE(m.title, m.mod_name)')->fetchAll();
$gone = $canManage ? q('SELECT mod_name, title, version, last_seen_at FROM server_mods WHERE on_server = 0 ORDER BY last_seen_at DESC LIMIT 20')->fetchAll() : [];
$configured = mods_base_url() !== '';

render('downloads', compact('user', 'mods', 'gone', 'canManage', 'configured') + ['title' => 'Downloads', 'nav' => 'downloads']);
