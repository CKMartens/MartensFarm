<?php
declare(strict_types=1);
/** @var array $user */

$all = isset($_GET['alle']);
$contracts = contracts_list($all);
$suggestions = [];
foreach ($contracts as $c) {
    foreach (contract_suggestions($c) as $s) {
        if ((int) $s['farm_id'] === (int) $c['farm_id']) {
            $suggestions[(int) $c['id']] = $s;
        }
    }
}

render('contracts', compact('user', 'contracts', 'all', 'suggestions') + ['title' => 'Lohnaufträge', 'nav' => 'lohnauftraege']);
