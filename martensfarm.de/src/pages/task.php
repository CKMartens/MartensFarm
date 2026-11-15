<?php
declare(strict_types=1);
/** @var array $user */

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$task = task_get($id);
if ($task === null) {
    http_response_code(404);
    render('error', ['title' => 'Nicht gefunden', 'message' => 'Diese Aufgabe gibt es nicht (oder sie gehört zu einem anderen Savegame).']);
    return;
}

$farms = active_farms();
$people = task_people();
$canAssign = can($user, 'tasks.assign');
$canEdit = task_can_edit($user, $task);
$errors = [];
$form = null;
$self = 'aufgaben/aufgabe?id=' . $id;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');
    $uid = (int) $user['id'];

    switch ($action) {
        case 'status':
            [$ok, $msg] = task_move_request($user, $id, (string) ($_POST['status'] ?? ''));
            if ($msg !== '') {
                flash($ok ? 'success' : 'error', $msg);
            }
            redirect($self);

        case 'take':
            if (task_can_take($user, $task)) {
                task_assign($task, $uid, $user);
                flash('success', 'Du hast die Aufgabe übernommen.');
            }
            redirect($self);

        case 'release':
            // Eigene Aufgabe zurückgeben
            if ((int) $task['assignee_id'] === $uid && can($user, 'tasks.work') && !in_array($task['status'], ['done', 'dropped'], true)) {
                task_assign($task, null, $user);
                if ($task['status'] === 'doing') {
                    task_set_status(array_merge($task, ['assignee_id' => null]), 'todo', $user);
                }
                flash('success', 'Aufgabe zurückgegeben, sie ist wieder offen.');
            }
            redirect($self);

        case 'assign':
            if ($canAssign) {
                $to = (int) ($_POST['assignee_id'] ?? 0);
                if ($to === 0 || isset($people[$to])) {
                    task_assign($task, $to ?: null, $user);
                    flash('success', $to ? 'Zugewiesen an ' . $people[$to] . '.' : 'Zuweisung entfernt.');
                }
            }
            redirect($self);

        case 'delete':
            if (!task_can_delete($user, $task)) {
                flash('error', 'Diese Aufgabe darfst du nicht löschen.');
                redirect($self);
            }
            task_delete($id);
            flash('success', 'Aufgabe „' . $task['title'] . '“ gelöscht.');
            redirect('aufgaben' . ($task['farm_id'] ? '?hof=' . (int) $task['farm_id'] : ''));

        case 'comment':
            $text = trim((string) ($_POST['text'] ?? ''));
            if (task_can_comment($user) && $text !== '' && mb_strlen($text) <= 1000) {
                task_log($id, $uid, 'comment', $text);
                q('UPDATE tasks SET updated_at = ? WHERE id = ?', [now(), $id]);
            } elseif ($text !== '') {
                flash('error', 'Der Kommentar ist zu lang (höchstens 1000 Zeichen).');
            }
            redirect($self);

        case 'edit':
            if (!$canEdit) {
                flash('error', 'Diese Aufgabe darfst du nicht bearbeiten.');
                redirect($self);
            }
            [$data, $errors] = task_form_read($_POST, $people + ($task['assignee_id'] !== null ? [(int) $task['assignee_id'] => '?'] : []), $farms);
            if (!$canAssign && $data['assignee_id'] !== (int) ($task['assignee_id'] ?? 0) && $data['assignee_id'] !== 0 && $data['assignee_id'] !== $uid) {
                $errors[] = 'Anderen zuweisen darf nur, wer Aufgaben verteilt.';
            }
            if (!$errors) {
                $changes = [];
                foreach (['title' => 'Titel', 'description' => 'Beschreibung', 'priority' => 'Priorität', 'due_day' => 'Fälligkeit', 'farm_id' => 'Hof'] as $f => $label) {
                    if ((string) ($task[$f] ?? '') !== (string) ($data[$f] ?: '')) {
                        $changes[] = $label;
                    }
                }
                if ((string) $task['ref_type'] . ':' . (string) $task['ref_id'] !== (string) $data['ref_type'] . ':' . (string) $data['ref_id']) {
                    $changes[] = 'Bezug';
                }
                q('UPDATE tasks SET title = ?, description = ?, farm_id = ?, priority = ?, due_day = ?, ref_type = ?, ref_id = ?, updated_at = ? WHERE id = ?', [
                    $data['title'], $data['description'] !== '' ? $data['description'] : null, $data['farm_id'] ?: null, $data['priority'],
                    $data['due_day'], $data['ref_type'], $data['ref_id'], now(), $id,
                ]);
                if ($changes) {
                    task_log($id, $uid, 'edit', 'geändert: ' . implode(', ', $changes));
                }
                task_assign($task, $data['assignee_id'] ?: null, $user);
                flash('success', 'Gespeichert.');
                redirect($self);
            }
            $form = $data;
            break;
    }
}

$form ??= [
    'title' => $task['title'], 'description' => (string) $task['description'], 'farm_id' => (int) $task['farm_id'],
    'priority' => (int) $task['priority'], 'assignee_id' => (int) $task['assignee_id'],
    'due_day' => $task['due_day'] !== null ? (int) $task['due_day'] : null, 'ref_type' => $task['ref_type'], 'ref_id' => $task['ref_id'],
];

$contract = $task['contract_id'] !== null ? contract_get((int) $task['contract_id']) : null;

render('task', compact('user', 'task', 'farms', 'people', 'canAssign', 'canEdit', 'errors', 'form', 'contract') + [
    'title' => $task['title'], 'nav' => 'aufgaben', 'events' => task_events($id), 'names' => user_names(), 'ref' => task_ref($task),
    'moves' => task_allowed_moves($user, $task), 'refOptions' => task_ref_options(), 'dayOptions' => game_day_options($form['due_day']),
    'action' => 'aufgaben/aufgabe', 'openEdit' => (bool) $errors,
    'canDelete' => task_can_delete($user, $task), 'confirmDelete' => isset($_GET['loeschen']) && task_can_delete($user, $task),
]);
