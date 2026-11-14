<?php
declare(strict_types=1);
/** @var array $user */

$cats = finance_categories();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'categories') {
        foreach ($cats as $id => $c) {
            $name = trim((string) ($_POST['name'][$id] ?? $c['name']));
            $sort = max(0, min(999, (int) ($_POST['sort'][$id] ?? $c['sort_order'])));
            $active = isset($_POST['active'][$id]) ? 1 : 0;
            if ($name === '' || mb_strlen($name) > 100) {
                continue;
            }
            q('UPDATE finance_categories SET name = ?, sort_order = ?, is_active = ? WHERE id = ?', [$name, $sort, $active, $id]);
        }
        $newName = trim((string) ($_POST['new_name'] ?? ''));
        $newKind = (string) ($_POST['new_kind'] ?? '');
        if ($newName !== '' && mb_strlen($newName) <= 100 && in_array($newKind, ['income', 'expense'], true)) {
            q('INSERT INTO finance_categories (kind, name, sort_order) VALUES (?, ?, ?)', [$newKind, $newName, max(0, min(999, (int) ($_POST['new_sort'] ?? 100)))]);
        }
        flash('success', 'Buchungssätze gespeichert.');
    } elseif ($action === 'map') {
        foreach ((array) ($_POST['map'] ?? []) as $stat => $choice) {
            $stat = (string) $stat;
            if ($stat === '' || mb_strlen($stat) > 50) {
                continue;
            }
            $choice = (string) $choice;
            $catId = ctype_digit($choice) && isset($cats[(int) $choice]) ? (int) $choice : null;
            q('INSERT INTO finance_stat_map (stat, category_id, is_ignored) VALUES (?, ?, ?)
               ON DUPLICATE KEY UPDATE category_id = VALUES(category_id), is_ignored = VALUES(is_ignored)', [$stat, $catId, $choice === 'ignore' ? 1 : 0]);
        }
        flash('success', 'Zuordnung gespeichert.');
    }
    redirect('admin/buchungssaetze');
}

$used = q('SELECT category_id, COUNT(*) FROM bookings WHERE cancelled_at IS NULL GROUP BY category_id')->fetchAll(PDO::FETCH_KEY_PAIR);

// Alle bekannten Spiel-Statistiken: mitgelieferte Namen, schon zugeordnete und tatsächlich gemeldete
$sgId = current_savegame_id();
$seen = $sgId ? q('SELECT stat, SUM(amount) AS amount, SUM(tx_count) AS n FROM game_finances WHERE savegame_id = ? GROUP BY stat', [$sgId])->fetchAll(PDO::FETCH_UNIQUE) : [];
$map = finance_stat_map();
$stats = array_unique(array_merge(array_keys(FINANCE_STAT_NAMES), array_keys($map), array_map('strval', array_keys($seen))));
usort($stats, fn($a, $b) => [isset($seen[$b]) ? 1 : 0, stat_name($a)] <=> [isset($seen[$a]) ? 1 : 0, stat_name($b)]);

render('booking_categories', compact('user', 'cats', 'used', 'stats', 'seen', 'map') + ['info' => finance_stat_info()] + ['title' => 'Buchungssätze', 'nav' => 'buchungssaetze']);
