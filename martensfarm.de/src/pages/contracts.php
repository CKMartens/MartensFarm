<?php
declare(strict_types=1);
/** @var array $user */

$all = isset($_GET['alle']);
$archived = isset($_GET['archiv']);
$contracts = contracts_list($all, $archived);
$suggestions = [];
foreach ($contracts as $c) {
    foreach (contract_suggestions($c) as $s) {
        if ((int) $s['farm_id'] === (int) $c['farm_id']) {
            $suggestions[(int) $c['id']] = $s;
        }
    }
}

render('contracts', compact('user', 'contracts', 'all', 'archived', 'suggestions') + ['title' => 'Lohnaufträge', 'nav' => 'lohnauftraege']);
