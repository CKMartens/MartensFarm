<?php
declare(strict_types=1);
/** @var array $user */

$kind = (string) ($_GET['art'] ?? '');
if ($kind !== '' && !isset(BOARD_KINDS[$kind])) {
    $kind = '';
}
$canModerate = can($user, 'board.moderate');
$showHidden = $canModerate && isset($_GET['ausgeblendet']);
$errors = [];

// Wer was schreiben darf
$kinds = array_filter(BOARD_KINDS, fn($k) => board_can_post($user, $k), ARRAY_FILTER_USE_KEY);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!$kinds) {
        flash('error', 'Du darfst hier nur lesen.');
        redirect('nachrichten');
    }
    [$data, $errors] = board_form_read($_POST, $user);
    if (!$errors && !board_rate_ok($user)) {
        $errors[] = 'Du hast in der letzten Stunde schon sehr viel geschrieben. Bitte etwas später noch mal.';
    }
    if (!$errors) {
        $id = board_create($user, $data);
        flash('success', BOARD_KINDS[$data['kind']][0] . ' veröffentlicht.');
        redirect('nachrichten/beitrag?id=' . $id);
    }
    $form = $data + ['ref' => (string) ($_POST['ref'] ?? ''), 'mod_url' => (string) ($_POST['mod_url'] ?? ''), 'expires_at' => (string) ($_POST['expires_at'] ?? '')];
} else {
    // Aus einer Aufgabe, einem Lohnauftrag oder Fahrzeug heraus: ?bezug=task:12
    $ref = (string) ($_GET['bezug'] ?? '');
    $form = [
        'kind' => $ref !== '' ? 'note' : (isset($kinds[$kind]) ? $kind : (string) array_key_first($kinds)),
        'title' => '', 'body' => '', 'ref' => $ref, 'is_pinned' => 0, 'is_sticky' => 0, 'expires_at' => '', 'mod_url' => '',
    ];
}

// Auswahl für den Bezug: offene Aufgaben, laufende Lohnaufträge, Fahrzeuge des aktuellen Savegames
$sg = current_savegame_id();
$refOptions = ['task' => [], 'contract' => [], 'vehicle' => []];
foreach (q('SELECT id, title FROM tasks WHERE savegame_id <=> ? AND status NOT IN (\'dropped\') ORDER BY status = \'done\', id DESC LIMIT 100', [$sg])->fetchAll() as $t) {
    $refOptions['task']['task:' . $t['id']] = $t['title'];
}
foreach (q('SELECT * FROM contracts WHERE savegame_id <=> ? AND status IN (\'open\', \'accepted\') ORDER BY id DESC LIMIT 50', [$sg])->fetchAll() as $c) {
    $refOptions['contract']['contract:' . $c['id']] = contract_title($c);
}
foreach (q('SELECT unique_id, name, farm_id FROM vehicles WHERE savegame_id <=> ? AND removed_at IS NULL ORDER BY farm_id, name', [$sg])->fetchAll() as $v) {
    $refOptions['vehicle']['vehicle:' . $v['unique_id']] = $v['name'] . ' (' . farm_name((int) $v['farm_id']) . ')';
}
// Vorbelegter Bezug, der nicht in der Liste ist (z. B. erledigte Aufgabe)
if ($form['ref'] !== '' && !isset($refOptions['task'][$form['ref']]) && !isset($refOptions['contract'][$form['ref']]) && !isset($refOptions['vehicle'][$form['ref']])) {
    [$t, $i] = array_pad(explode(':', $form['ref'], 2), 2, '');
    $r = isset(BOARD_REF_TYPES[$t]) && board_ref_valid($t, $i) ? board_ref(['ref_type' => $t, 'ref_id' => $i]) : null;
    if ($r) {
        $refOptions[$t][$form['ref']] = $r[1];
    } else {
        $form['ref'] = '';
    }
}

$lastRead = board_last_read($user);
$posts = board_list($kind, $showHidden);
board_mark_read($user);

render('board', compact('user', 'kind', 'kinds', 'posts', 'form', 'errors', 'refOptions', 'canModerate', 'showHidden', 'lastRead')
    + ['title' => 'Nachrichten', 'nav' => 'nachrichten', 'openForm' => (bool) $errors || $form['ref'] !== '' || isset($_GET['neu'])]);
