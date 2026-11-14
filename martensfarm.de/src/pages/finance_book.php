<?php
declare(strict_types=1);
/** @var array $user */

$sgId = current_savegame_id();
$farmIds = array_values(array_filter(finance_farm_ids($user), fn($id) => finance_can_book($user, $id)));
if ($sgId === null || !$farmIds) {
    render('error', ['title' => 'Buchen', 'message' => $sgId === null
        ? 'Es ist noch kein Savegame erkannt. Das passiert mit dem ersten Abruf nach einem Save.'
        : 'Dir ist noch kein Hof zugeordnet, für den du buchen darfst.']);
    return;
}
$farms = array_intersect_key(active_farms(), array_flip($farmIds));
$cats = array_filter(finance_categories(), fn($c) => (int) $c['is_active'] === 1);
$errors = [];

// Vorschlag aus einem Ereignis (Fahrzeug, Farmland)
$suggestion = null;
$sid = (int) ($_GET['vorschlag'] ?? $_POST['suggestion_id'] ?? 0);
if ($sid > 0) {
    $suggestion = q('SELECT * FROM booking_suggestions WHERE id = ? AND savegame_id = ? AND status = \'open\'', [$sid, $sgId])->fetch() ?: null;
    if ($suggestion && !in_array((int) $suggestion['farm_id'], $farmIds, true)) {
        $suggestion = null;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $farmId = (int) ($_POST['farm_id'] ?? 0);
    $catId = (int) ($_POST['category_id'] ?? 0);
    $amountRaw = str_replace(['.', ' ', '€'], '', trim((string) ($_POST['amount'] ?? '')));
    $amountRaw = str_replace(',', '.', $amountRaw);
    $amount = is_numeric($amountRaw) ? (int) round(abs((float) $amountRaw)) : 0;
    $day = (int) ($_POST['game_day'] ?? -1);
    $text = trim((string) ($_POST['text'] ?? ''));

    if (!in_array($farmId, $farmIds, true)) {
        $errors[] = 'Für diesen Hof darfst du nicht buchen.';
    }
    if (!isset($cats[$catId])) {
        $errors[] = 'Bitte einen Buchungssatz auswählen.';
    }
    if ($amount < 1 || $amount > 100_000_000_000) {
        $errors[] = 'Der Betrag muss eine Zahl über 0 sein.';
    }
    if ($day < 0 || $day > 1_000_000) {
        $errors[] = 'Der Spieltag passt nicht.';
    }
    if (mb_strlen($text) > 255) {
        $errors[] = 'Der Text ist zu lang (höchstens 255 Zeichen).';
    }
    if ($suggestion && (int) $suggestion['farm_id'] !== $farmId) {
        $suggestion = null;   // Hof geändert → kein Bezug mehr zum Vorschlag
    }
    if (!$errors) {
        finance_book($user, $sgId, $farmId, $catId, $amount, $day, $text,
            $suggestion ? 'suggestion' : 'manual', $suggestion['ref_type'] ?? null, $suggestion['ref_id'] ?? null,
            $suggestion ? (int) $suggestion['id'] : null);
        flash('success', 'Gebucht: ' . $cats[$catId]['name'] . ', ' . fmt_money($amount) . '.');
        redirect('finanzen?hof=' . $farmId);
    }
    $form = ['farm_id' => $farmId, 'category_id' => $catId, 'amount' => (string) ($_POST['amount'] ?? ''), 'game_day' => $day, 'text' => $text];
} else {
    $farmId = (int) ($suggestion['farm_id'] ?? $_GET['hof'] ?? 0);
    if (!in_array($farmId, $farmIds, true)) {
        $farmId = in_array((int) $user['farm_id'], $farmIds, true) ? (int) $user['farm_id'] : $farmIds[0];
    }
    $form = [
        'farm_id'     => $farmId,
        'category_id' => $suggestion ? (finance_category_id(SUGGESTION_KINDS[$suggestion['kind']][1] ?? '') ?? 0) : 0,
        'amount'      => $suggestion && $suggestion['amount'] !== null ? (string) $suggestion['amount'] : '',
        'game_day'    => $suggestion['game_day'] ?? current_game_day() ?? 0,
        'text'        => $suggestion ? (SUGGESTION_KINDS[$suggestion['kind']][0] ?? '') . ': ' . $suggestion['title'] : '',
    ];
}

render('finance_book', compact('user', 'farms', 'cats', 'form', 'errors', 'suggestion') + ['title' => 'Buchen', 'nav' => 'finanzen', 'gameDay' => current_game_day()]);
