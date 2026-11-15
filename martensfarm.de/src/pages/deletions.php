<?php
declare(strict_types=1);
/** @var array $user */

// Löschprotokoll der Buchungen über alle Höfe, die der Nutzer in den Finanzen sehen darf
$farmIds = finance_farm_ids($user);
$farms = array_intersect_key(active_farms(), array_flip($farmIds));
$savegames = q('SELECT id, savegame_name FROM savegames ORDER BY last_seen_at DESC')->fetchAll(PDO::FETCH_KEY_PAIR);
$currentSg = current_savegame_id();

$farmId = (int) ($_GET['hof'] ?? 0);
if (!isset($farms[$farmId])) {
    $farmId = 0;   // alle sichtbaren Höfe
}
$sgId = (int) ($_GET['sg'] ?? $currentSg ?? 0);
if (!isset($savegames[$sgId])) {
    $sgId = 0;     // alle Savegames
}

$rows = [];
if ($farmIds) {
    $where = ['d.farm_id IN (' . implode(',', array_map('intval', $farmId ? [$farmId] : $farmIds)) . ')'];
    $args = [];
    if ($sgId) {
        $where[] = 'd.savegame_id = ?';
        $args[] = $sgId;
    }
    $rows = q('SELECT d.*, cu.nickname AS created_nick, du.nickname AS deleted_nick FROM booking_deletions d
               LEFT JOIN users cu ON cu.id = d.created_by LEFT JOIN users du ON du.id = d.deleted_by
               WHERE ' . implode(' AND ', $where) . ' ORDER BY d.deleted_at DESC, d.id DESC LIMIT 300', $args)->fetchAll();
}

render('deletions', compact('user', 'farms', 'farmId', 'savegames', 'sgId', 'currentSg', 'rows') + [
    'title' => 'Löschprotokoll', 'nav' => 'loeschprotokoll', 'cats' => finance_categories(),
]);
