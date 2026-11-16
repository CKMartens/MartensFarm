<?php
declare(strict_types=1);
/** @var array $user @var string $path */

$isNew = $path === 'lohnauftraege/neu';
$canManage = can($user, 'contracts.manage');
if ($isNew && !$canManage) {
    http_response_code(403);
    render('error', ['title' => 'Kein Zugriff', 'message' => 'Dafür fehlt dir die Berechtigung.']);
    return;
}

$farms = active_farms();
$farmlands = task_ref_options()['farmland'];
$errors = [];
$contract = null;

if (!$isNew) {
    $contract = contract_get((int) ($_GET['id'] ?? $_POST['id'] ?? 0));
    if ($contract === null) {
        http_response_code(404);
        render('error', ['title' => 'Nicht gefunden', 'message' => 'Diesen Lohnauftrag gibt es nicht (oder er gehört zu einem anderen Savegame).']);
        return;
    }
}
$self = $contract ? 'lohnauftraege/auftrag?id=' . (int) $contract['id'] : 'lohnauftraege/neu';
$form = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!$canManage) {
        flash('error', 'Lohnaufträge verwaltet nur, wer das Recht dazu hat.');
        redirect($self);
    }
    $action = $isNew ? 'create' : (string) ($_POST['action'] ?? '');

    if ($action === 'create' || $action === 'edit') {
        [$data, $errors] = contract_form_read($_POST, $farms);
        if (!$errors && $action === 'create') {
            $now = now();
            $withTask = isset($_POST['create_task']);
            q('INSERT INTO contracts (savegame_id, farm_id, client_farm_id, client_name, service, farmland_id, price, due_day, note, ai_helper, status, created_by, created_at, updated_at)
               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
                current_savegame_id(), $data['farm_id'], $data['client_farm_id'], $data['client_name'] !== '' ? $data['client_name'] : null,
                $data['service'], $data['farmland_id'], $data['price'], $data['due_day'], $data['note'] !== '' ? $data['note'] : null, $data['ai_helper'],
                $withTask ? 'accepted' : 'open', (int) $user['id'], $now, $now,
            ]);
            $newId = (int) db()->lastInsertId();
            if ($withTask) {
                $c = contract_get($newId);
                task_create($user, [
                    'title' => 'Lohnauftrag: ' . $data['service'], 'description' => 'Für ' . contract_client($c) . ($data['note'] !== '' ? "\n" . $data['note'] : ''),
                    'farm_id' => $data['farm_id'], 'priority' => 2, 'assignee_id' => 0, 'due_day' => $data['due_day'],
                    'ref_type' => $data['farmland_id'] !== null ? 'farmland' : null, 'ref_id' => $data['farmland_id'] !== null ? (string) $data['farmland_id'] : null,
                    'contract_id' => $newId, 'status' => 'todo', 'ai_helper' => $data['ai_helper'],
                ]);
            }
            flash('success', 'Lohnauftrag angelegt' . ($withTask ? ', die Aufgabe steht auf dem Board.' : '.'));
            redirect('lohnauftraege/auftrag?id=' . $newId);
        }
        if (!$errors && $action === 'edit') {
            if ($contract['status'] === 'done') {
                // Nach dem Abschluss sind Preis und Höfe in den Vorschlägen, deshalb nur noch Text ändern
                q('UPDATE contracts SET service = ?, note = ?, updated_at = ? WHERE id = ?', [$data['service'], $data['note'] !== '' ? $data['note'] : null, now(), (int) $contract['id']]);
            } else {
                q('UPDATE contracts SET farm_id = ?, client_farm_id = ?, client_name = ?, service = ?, farmland_id = ?, price = ?, due_day = ?, note = ?, ai_helper = ?, updated_at = ? WHERE id = ?', [
                    $data['farm_id'], $data['client_farm_id'], $data['client_name'] !== '' ? $data['client_name'] : null, $data['service'], $data['farmland_id'],
                    $data['price'], $data['due_day'], $data['note'] !== '' ? $data['note'] : null, $data['ai_helper'], now(), (int) $contract['id'],
                ]);
                // KI-Helfer an/aus gilt auch für die offenen Aufgaben, die niemandem aus der Runde gehören
                if ($data['ai_helper'] !== (int) $contract['ai_helper']) {
                    foreach (q('SELECT * FROM tasks WHERE contract_id = ? AND status NOT IN (\'done\', \'dropped\') AND assignee_id IS NULL', [(int) $contract['id']])->fetchAll() as $t) {
                        task_set_ai($t, (bool) $data['ai_helper'], $user);
                    }
                }
            }
            flash('success', 'Gespeichert.');
            redirect($self);
        }
        $form = $data;
    } elseif ($action === 'accept' && $contract['status'] === 'open') {
        q('UPDATE contracts SET status = \'accepted\', updated_at = ? WHERE id = ?', [now(), (int) $contract['id']]);
        flash('success', 'Angenommen.');
        redirect($self);
    } elseif ($action === 'finish' && in_array($contract['status'], ['open', 'accepted'], true)) {
        contract_finish($contract, $user);
        flash('success', 'Lohnauftrag erledigt. Die Einnahme steht jetzt als Vorschlag unter Finanzen.');
        redirect($self);
    } elseif ($action === 'cancel' && in_array($contract['status'], ['open', 'accepted'], true)) {
        q('UPDATE contracts SET status = \'cancelled\', done_day = ?, updated_at = ? WHERE id = ?', [current_game_day(), now(), (int) $contract['id']]);
        foreach (q('SELECT * FROM tasks WHERE contract_id = ? AND status NOT IN (\'done\', \'dropped\')', [(int) $contract['id']])->fetchAll() as $t) {
            task_set_status($t, 'dropped', $user, 'Lohnauftrag storniert');
        }
        flash('success', 'Lohnauftrag storniert, offene Aufgaben sind verworfen.');
        redirect($self);
    } elseif ($action === 'reopen' && $contract['status'] === 'cancelled') {
        q('UPDATE contracts SET status = \'open\', done_day = NULL, archived_at = NULL, archived_by = NULL, updated_at = ? WHERE id = ?', [now(), (int) $contract['id']]);
        flash('success', 'Wieder geöffnet.');
        redirect($self);
    } elseif (($action === 'archive' || $action === 'unarchive') && contract_can_archive($user, $contract)) {
        contract_archive($contract, $user, $action === 'archive');
        flash('success', $action === 'archive' ? 'Lohnauftrag archiviert (mit seinen erledigten Aufgaben).' : 'Lohnauftrag ist wieder in der Liste.');
        redirect($self);
    } else {
        redirect($self);
    }
}

$form ??= $contract ? [
    'farm_id' => (int) $contract['farm_id'],
    'client' => $contract['client_farm_id'] !== null ? (string) $contract['client_farm_id'] : 'extern',
    'client_name' => (string) $contract['client_name'], 'service' => $contract['service'],
    'farmland_id' => $contract['farmland_id'] !== null ? (int) $contract['farmland_id'] : null, 'price' => (int) $contract['price'],
    'due_day' => $contract['due_day'] !== null ? (int) $contract['due_day'] : null, 'note' => (string) $contract['note'],
    'ai_helper' => (int) $contract['ai_helper'],
] : [
    'farm_id' => isset($farms[(int) ($user['farm_id'] ?? 0)]) ? (int) $user['farm_id'] : (int) array_key_first($farms),
    'client' => '', 'client_name' => '', 'service' => '', 'farmland_id' => null, 'price' => '', 'due_day' => null, 'note' => '', 'ai_helper' => 0,
];

$tasks = $contract ? q('SELECT * FROM tasks WHERE contract_id = ? ORDER BY FIELD(status, \'doing\', \'todo\', \'review\', \'backlog\', \'done\', \'dropped\'), id', [(int) $contract['id']])->fetchAll() : [];
$suggestions = $contract ? contract_suggestions($contract) : [];

render('contract', compact('user', 'contract', 'farms', 'farmlands', 'errors', 'form', 'tasks', 'suggestions', 'canManage') + [
    'title' => $contract ? $contract['service'] : 'Neuer Lohnauftrag', 'nav' => 'lohnauftraege', 'names' => user_names(),
    'dayOptions' => game_day_options($form['due_day']), 'openEdit' => (bool) $errors,
]);
