<?php
declare(strict_types=1);
/** @var array $user */

$sgId = current_savegame_id();
$vehicle = vehicle_get((string) ($_GET['id'] ?? ''));
if ($vehicle === null) {
    http_response_code(404);
    render('error', ['title' => 'Nicht gefunden', 'message' => 'Dieses Fahrzeug gibt es nicht (oder es gehört zu einem anderen Savegame).']);
    return;
}
$farmId = (int) $vehicle['farm_id'];
$uid = (string) $vehicle['unique_id'];

// Buchungen mit Bezug auf das Fahrzeug, nur wenn man die Finanzen des Hofs sehen darf
$canFinance = $sgId !== null && finance_can_view($user, $farmId);
$bookings = $canFinance ? q('SELECT b.*, u.nickname FROM bookings b LEFT JOIN users u ON u.id = b.created_by
                             WHERE b.savegame_id = ? AND b.ref_type = \'vehicle\' AND b.ref_id = ? AND b.farm_id = ?
                             ORDER BY b.game_day DESC, b.id DESC LIMIT 100', [$sgId, $uid, $farmId])->fetchAll() : [];
$costs = $canFinance ? (vehicle_costs($user, $sgId, [$uid])[$uid] ?? ['income' => 0, 'expense' => 0, 'n' => 0]) : null;
$openSuggestions = $canFinance ? q('SELECT * FROM booking_suggestions WHERE savegame_id = ? AND ref_type = \'vehicle\' AND ref_id = ? AND status = \'open\'',
    [$sgId, $uid])->fetchAll() : [];

$tasks = q('SELECT * FROM tasks WHERE savegame_id <=> ? AND ref_type = \'vehicle\' AND ref_id = ? AND status <> \'dropped\'
            ORDER BY status = \'done\', id DESC LIMIT 30', [$sgId, $uid])->fetchAll();

$threshold = setting_int('maintenance_threshold_pct') / 100;
$point = map_point($vehicle['pos_x'] !== null ? (float) $vehicle['pos_x'] : null, $vehicle['pos_z'] !== null ? (float) $vehicle['pos_z'] : null);
$hasMap = gs_base_url() !== '' && gs_code() !== '';

render('vehicle', compact('user', 'vehicle', 'farmId', 'canFinance', 'bookings', 'costs', 'openSuggestions', 'tasks', 'threshold', 'point', 'hasMap')
    + ['title' => $vehicle['name'], 'nav' => 'fuhrpark', 'cats' => finance_categories(), 'names' => user_names()]);
