<?php
declare(strict_types=1);
/** @var array $user */

// Verschieben ohne JavaScript (Pfeil-Knöpfe auf den Karten)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $back = 'aufgaben' . (isset($_POST['back']) && preg_match('/^[a-z0-9=&_]*$/', (string) $_POST['back']) && $_POST['back'] !== '' ? '?' . $_POST['back'] : '');
    $id = (int) ($_POST['id'] ?? 0);
    if (($_POST['action'] ?? '') === 'take') {
        $t = task_get($id);
        if ($t && task_can_take($user, $t)) {
            task_assign($t, (int) $user['id'], $user);
            if (in_array($t['status'], ['backlog', 'todo'], true)) {
                task_set_status(array_merge($t, ['assignee_id' => $user['id']]), 'doing', $user);
            }
            flash('success', 'Übernommen: „' . $t['title'] . '“.');
        } else {
            flash('error', 'Die Aufgabe kannst du nicht übernehmen.');
        }
    } else {
        [$ok, $msg] = task_move_request($user, $id, (string) ($_POST['status'] ?? ''));
        if ($msg !== '') {
            flash($ok ? 'success' : 'error', $msg);
        }
    }
    redirect($back);
}

$farms = active_farms();
$farmId = isset($_GET['hof']) ? (int) $_GET['hof'] : (int) ($user['farm_id'] ?? 0);
if ($farmId !== 0 && !isset($farms[$farmId])) {
    $farmId = 0;
}
$mine = isset($_GET['meine']) ? (int) $user['id'] : 0;
$columns = tasks_for_board($farmId, $mine);
$names = user_names();
$query = http_build_query(array_filter(['hof' => $farmId ?: null, 'meine' => $mine ? 1 : null]));

// Bezüge in einem Rutsch auflösen wäre schöner, bei den paar Karten reicht das hier
$refs = [];
$contracts = [];
foreach ($columns as $list) {
    foreach ($list as $t) {
        $refs[(int) $t['id']] = task_ref($t);
        if ($t['contract_id'] !== null && !isset($contracts[(int) $t['contract_id']])) {
            $contracts[(int) $t['contract_id']] = contract_get((int) $t['contract_id']);
        }
    }
}

render('tasks', compact('user', 'farms', 'farmId', 'mine', 'columns', 'names', 'query', 'refs', 'contracts') + [
    'title' => 'Aufgaben', 'nav' => 'aufgaben', 'scripts' => ['tasks.js'], 'gameDay' => current_game_day(),
]);
