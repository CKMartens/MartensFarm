<?php
declare(strict_types=1);
/** @var array $user */

$farms = active_farms();
$people = task_people();
$canAssign = can($user, 'tasks.assign');
$errors = [];

// Aus einem Lohnauftrag heraus: Bezug, Hof und Feld vorbelegen
$contract = null;
$cid = (int) ($_GET['auftrag'] ?? $_POST['contract_id'] ?? 0);
if ($cid > 0) {
    $contract = contract_get($cid);
    if ($contract && in_array($contract['status'], ['done', 'cancelled'], true)) {
        $contract = null;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    [$data, $errors] = task_form_read($_POST, $people, $farms);
    if (!$canAssign && $data['assignee_id'] !== 0 && $data['assignee_id'] !== (int) $user['id']) {
        $errors[] = 'Anderen zuweisen darf nur, wer Aufgaben verteilt. Lass sie offen oder nimm sie selbst.';
    }
    $status = (string) ($_POST['status'] ?? 'todo');
    if (!in_array($status, ['backlog', 'todo'], true)) {
        $status = 'todo';
    }
    if (!$errors) {
        $id = task_create($user, $data + ['status' => $status, 'contract_id' => $contract ? (int) $contract['id'] : null]);
        if ($contract && $contract['status'] === 'open') {
            q('UPDATE contracts SET status = \'accepted\', updated_at = ? WHERE id = ?', [now(), (int) $contract['id']]);
        }
        flash('success', 'Aufgabe angelegt: „' . $data['title'] . '“.');
        redirect($contract ? 'lohnauftraege/auftrag?id=' . (int) $contract['id'] : 'aufgaben' . ($data['farm_id'] ? '?hof=' . $data['farm_id'] : ''));
    }
    $form = $data + ['status' => $status];
} else {
    $farmId = (int) ($contract['farm_id'] ?? $_GET['hof'] ?? $user['farm_id'] ?? 0);
    $form = [
        'title' => $contract ? 'Lohnauftrag: ' . $contract['service'] : '',
        'description' => $contract ? 'Für ' . contract_client($contract) . ($contract['note'] ? "\n" . $contract['note'] : '') : '',
        'farm_id' => isset($farms[$farmId]) ? $farmId : 0,
        'priority' => 2,
        'assignee_id' => 0,
        'due_day' => $contract['due_day'] ?? null,
        'ref_type' => $contract && $contract['farmland_id'] !== null ? 'farmland' : null,
        'ref_id' => $contract && $contract['farmland_id'] !== null ? (string) $contract['farmland_id'] : null,
        'status' => 'todo',
    ];
}

render('task_form', compact('user', 'farms', 'people', 'canAssign', 'errors', 'form', 'contract') + [
    'title' => 'Neue Aufgabe', 'nav' => 'aufgaben', 'refOptions' => task_ref_options(), 'dayOptions' => game_day_options($form['due_day']),
    'action' => 'aufgaben/neu', 'task' => null,
]);
