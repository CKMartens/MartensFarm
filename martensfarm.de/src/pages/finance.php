<?php
declare(strict_types=1);
/** @var array $user */

$farmIds = finance_farm_ids($user);
if (!$farmIds) {
    render('error', ['title' => 'Finanzen', 'message' => 'Dir ist noch kein Hof zugeordnet. Das macht ein Admin unter „Nutzer & Höfe“.']);
    return;
}
$farms = array_intersect_key(active_farms(), array_flip($farmIds));
$farmId = (int) ($_GET['hof'] ?? $_POST['hof'] ?? 0);
if (!in_array($farmId, $farmIds, true)) {
    $own = (int) ($user['farm_id'] ?? 0);
    $farmId = in_array($own, $farmIds, true) ? $own : $farmIds[0];
}

$currentSg = current_savegame_id();
$savegames = q('SELECT id, savegame_name, map_title, map_id FROM savegames ORDER BY last_seen_at DESC')->fetchAll();
$sgId = (int) ($_GET['sg'] ?? 0);
if (!in_array($sgId, array_map('intval', array_column($savegames, 'id')), true)) {
    $sgId = (int) $currentSg;
}
$readOnly = $sgId !== (int) $currentSg || !finance_can_book($user, $farmId);
$back = 'finanzen?hof=' . $farmId;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');
    // Abschließen, Öffnen und Löschen prüfen ihre eigenen Rechte, der Rest braucht „Buchen“
    $ownRights = in_array($action, ['close_month', 'reopen_month', 'delete'], true);
    if ($sgId === 0 || $sgId !== (int) $currentSg || (!$ownRights && $readOnly)) {
        flash('error', 'Hier darfst du nicht buchen.');
        redirect($back);
    }
    $start = finance_reconcile($sgId, $farmId)['start']['day'] ?? null;

    if ($action === 'cancel') {
        $ok = finance_cancel($user, (int) ($_POST['id'] ?? 0));
        flash($ok ? 'success' : 'error', $ok ? 'Buchung storniert.' : 'Diese Buchung darfst du nicht stornieren.');
    } elseif ($action === 'delete') {
        $ok = finance_delete($user, (int) ($_POST['id'] ?? 0), trim((string) ($_POST['reason'] ?? '')));
        flash($ok ? 'success' : 'error', $ok ? 'Buchung gelöscht. Sie steht noch im Löschprotokoll.' : 'Diese Buchung darfst du nicht löschen.');
    } elseif ($action === 'close_month') {
        [$ok, $msg] = finance_close_month($user, $sgId, $farmId, (int) ($_POST['month'] ?? -1));
        flash($ok ? 'success' : 'error', $msg);
    } elseif ($action === 'reopen_month') {
        $ok = finance_reopen_month($user, $sgId, $farmId, (int) ($_POST['id'] ?? 0));
        flash($ok ? 'success' : 'error', $ok ? 'Monat wieder geöffnet, die Korrektur ist storniert.' : 'Den Monat darfst du nicht öffnen.');
    } elseif ($action === 'game' || $action === 'game_all') {
        $wantDay = (int) ($_POST['day'] ?? -1);
        $wantCat = (int) ($_POST['category'] ?? 0);
        $n = 0;
        $cats = finance_categories();
        foreach (finance_game_open($sgId, $farmId, $start) as $r) {
            if (!$r['fits'] || ($action === 'game' && ($r['day'] !== $wantDay || $r['category_id'] !== $wantCat))) {
                continue;
            }
            $text = 'Laut Spiel: ' . ($r['stats'] ? implode(', ', $r['stats']) : $cats[$r['category_id']]['name']);
            finance_book($user, $sgId, $farmId, $r['category_id'], abs($r['open']), $r['day'], $text, 'game');
            $n++;
        }
        flash($n ? 'success' : 'warning', $n ? "{$n} Buchung(en) aus dem Spiel übernommen." : 'Nichts zu übernehmen, vielleicht hat schon jemand gebucht.');
    } elseif ($action === 'dismiss') {
        q('UPDATE booking_suggestions SET status = \'dismissed\' WHERE id = ? AND savegame_id = ? AND farm_id = ? AND status = \'open\'',
            [(int) ($_POST['id'] ?? 0), $sgId, $farmId]);
        flash('success', 'Vorschlag verworfen.');
    }
    redirect($back);
}

// Kontostand: aktuelles Savegame aus farms, archivierte aus farms_archive
if ($sgId === (int) $currentSg) {
    $farm = q('SELECT * FROM farms WHERE farm_id = ?', [$farmId])->fetch() ?: null;
} else {
    $farm = q('SELECT * FROM farms_archive WHERE savegame_id = ? AND farm_id = ?', [$sgId, $farmId])->fetch() ?: null;
}

$rec = $sgId ? finance_reconcile($sgId, $farmId) : ['start' => null, 'rows' => [], 'open' => 0, 'booked_after' => 0];
$gameOpen = $sgId ? finance_game_open($sgId, $farmId, $rec['start']['day'] ?? null) : [];
$suggestions = $sgId ? q('SELECT * FROM booking_suggestions WHERE savegame_id = ? AND farm_id = ? AND status = \'open\' ORDER BY detected_at DESC LIMIT 50',
    [$sgId, $farmId])->fetchAll() : [];
$bookings = $sgId ? q('SELECT b.*, u.nickname, cu.nickname AS cancelled_nick FROM bookings b
                       LEFT JOIN users u ON u.id = b.created_by LEFT JOIN users cu ON cu.id = b.cancelled_by
                       WHERE b.savegame_id = ? AND b.farm_id = ? ORDER BY b.game_day DESC, b.id DESC LIMIT 200', [$sgId, $farmId])->fetchAll() : [];
$totals = $sgId ? q('SELECT COALESCE(SUM(GREATEST(amount, 0)), 0) AS income, COALESCE(SUM(LEAST(amount, 0)), 0) AS expense
                     FROM bookings WHERE savegame_id = ? AND farm_id = ? AND cancelled_at IS NULL', [$sgId, $farmId])->fetch()
                : ['income' => 0, 'expense' => 0];
$cats = finance_categories();
$gameDay = $sgId === (int) $currentSg ? current_game_day() : null;   // im Archiv gibt es kein „heute“
$hasGameData = $sgId && (bool) q('SELECT 1 FROM game_finances WHERE savegame_id = ? LIMIT 1', [$sgId])->fetchColumn();
$months = $sgId ? array_slice(finance_months($sgId, $farmId, $rec), 0, 12) : [];
$deletions = $sgId ? finance_deletions($sgId, $farmId) : [];

// Rückfrage vor dem Löschen (ohne JavaScript): ?loeschen=<id> zeigt die Buchung mit „Endgültig löschen“
$confirmDelete = null;
$delId = (int) ($_GET['loeschen'] ?? 0);
if ($delId > 0) {
    $confirmDelete = q('SELECT * FROM bookings WHERE id = ? AND savegame_id = ? AND farm_id = ?', [$delId, $sgId, $farmId])->fetch() ?: null;
    if ($confirmDelete && !finance_can_delete($user, $confirmDelete)) {
        $confirmDelete = null;
    }
}

render('finance', compact('user', 'farms', 'farmId', 'farm', 'savegames', 'sgId', 'currentSg', 'readOnly', 'rec', 'gameOpen',
    'suggestions', 'bookings', 'totals', 'cats', 'gameDay', 'hasGameData', 'months', 'deletions', 'confirmDelete') + ['title' => 'Finanzen', 'nav' => 'finanzen']);
