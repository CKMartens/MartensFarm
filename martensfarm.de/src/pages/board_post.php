<?php
declare(strict_types=1);
/** @var array $user */

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$post = board_post_get($id);
$canModerate = can($user, 'board.moderate');
if ($post === null || ($post['hidden_at'] !== null && !$canModerate && (int) $post['created_by'] !== (int) $user['id'])) {
    http_response_code(404);
    render('error', ['title' => 'Nicht gefunden', 'message' => 'Diesen Beitrag gibt es nicht (mehr).']);
    return;
}
$self = 'nachrichten/beitrag?id=' . $id;
$errors = [];
$form = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');
    $uid = (int) $user['id'];

    switch ($action) {
        case 'reply':
            $text = trim(str_replace("\r\n", "\n", (string) ($_POST['body'] ?? '')));
            if (!board_can_reply($user, $post)) {
                flash('error', 'Antworten darfst du hier nicht.');
            } elseif ($text === '' || mb_strlen($text) > 3000) {
                flash('error', 'Die Antwort ist leer oder zu lang (höchstens 3000 Zeichen).');
            } elseif (!board_rate_ok($user)) {
                flash('error', 'Du hast in der letzten Stunde schon sehr viel geschrieben. Bitte etwas später noch mal.');
            } else {
                q('INSERT INTO board_replies (post_id, body, created_by, created_at) VALUES (?, ?, ?, ?)', [$id, $text, $uid, now()]);
                q('UPDATE board_posts SET updated_at = ? WHERE id = ?', [now(), $id]);
            }
            redirect($self . '#antworten');

        case 'hide':
        case 'unhide':
            if (board_can_hide($user, $post) && ($action === 'hide' || $canModerate)) {
                q('UPDATE board_posts SET hidden_at = ?, hidden_by = ? WHERE id = ?', $action === 'hide' ? [now(), $uid, $id] : [null, null, $id]);
                flash('success', $action === 'hide' ? 'Beitrag ausgeblendet.' : 'Beitrag wieder sichtbar.');
            }
            redirect($action === 'hide' && !$canModerate ? 'nachrichten' : $self);

        case 'hide_reply':
        case 'unhide_reply':
            $r = q('SELECT * FROM board_replies WHERE id = ? AND post_id = ?', [(int) ($_POST['reply_id'] ?? 0), $id])->fetch();
            if ($r && board_can_hide($user, $r) && ($action === 'hide_reply' || $canModerate)) {
                q('UPDATE board_replies SET hidden_at = ?, hidden_by = ? WHERE id = ?', $action === 'hide_reply' ? [now(), $uid, (int) $r['id']] : [null, null, (int) $r['id']]);
            }
            redirect($self . '#antworten');

        case 'pin':
            if ($post['kind'] === 'announcement' && can($user, 'board.announce')) {
                q('UPDATE board_posts SET is_pinned = 1 - is_pinned WHERE id = ?', [$id]);
            }
            redirect($self);

        case 'mod_status':
            $st = (string) ($_POST['mod_status'] ?? '');
            $modName = trim((string) ($_POST['mod_name'] ?? ''));
            if ($post['kind'] === 'mod_wish' && $canModerate && isset(MOD_WISH_STATUSES[$st])) {
                $known = $modName !== '' && q('SELECT 1 FROM server_mods WHERE mod_name = ?', [$modName])->fetchColumn();
                q('UPDATE board_posts SET mod_status = ?, mod_name = ?, updated_at = ? WHERE id = ?', [$st, $st === 'installed' && $known ? $modName : null, now(), $id]);
                q('INSERT INTO board_replies (post_id, body, created_by, created_at) VALUES (?, ?, ?, ?)',
                    [$id, 'Status: ' . MOD_WISH_STATUSES[$st] . ($st === 'installed' && $known ? ' (' . $modName . ')' : ''), $uid, now()]);
                flash('success', 'Status gesetzt: ' . MOD_WISH_STATUSES[$st] . '.');
            }
            redirect($self);

        case 'edit':
            if (!board_can_edit($user, $post)) {
                flash('error', 'Diesen Beitrag darfst du nicht bearbeiten.');
                redirect($self);
            }
            [$data, $errors] = board_form_read($_POST + ['ref' => $post['ref_type'] ? $post['ref_type'] . ':' . $post['ref_id'] : ''], $user, $post);
            if (!$errors) {
                q('UPDATE board_posts SET title = ?, body = ?, is_pinned = ?, expires_at = ?, mod_url = ?, updated_at = ? WHERE id = ?', [
                    $data['title'], $data['body'] !== '' ? $data['body'] : null,
                    $post['kind'] === 'announcement' ? $data['is_pinned'] : 0, $data['expires_at'], $data['mod_url'], now(), $id,
                ]);
                flash('success', 'Gespeichert.');
                redirect($self);
            }
            $form = $data + ['mod_url' => (string) ($_POST['mod_url'] ?? ''), 'expires_at' => (string) ($_POST['expires_at'] ?? '')];
            break;
    }
}

$form ??= [
    'title' => $post['title'], 'body' => (string) $post['body'], 'is_pinned' => (int) $post['is_pinned'],
    'expires_at' => (string) $post['expires_at'], 'mod_url' => (string) $post['mod_url'],
];
$replies = board_replies($id, $canModerate);
$serverMods = $post['kind'] === 'mod_wish' && $canModerate
    ? q('SELECT mod_name, title FROM server_mods ORDER BY on_server DESC, title')->fetchAll(PDO::FETCH_KEY_PAIR) : [];
$installedMod = $post['mod_name'] !== null ? (q('SELECT * FROM server_mods WHERE mod_name = ?', [$post['mod_name']])->fetch() ?: null) : null;

render('board_post', compact('user', 'post', 'replies', 'canModerate', 'errors', 'form', 'serverMods', 'installedMod')
    + ['title' => $post['title'], 'nav' => 'nachrichten', 'ref' => board_ref($post), 'openEdit' => (bool) $errors]);
