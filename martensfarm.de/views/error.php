<?php
/** @var bool $withLayout  (kommt aus render()) */
$inner = '<h1>' . e($title ?? 'Fehler') . '</h1><p class="muted">' . e($message ?? '') . '</p>';
$inner .= current_user() === null || !empty($loginLink)
    ? '<p><a class="btn btn-primary" href="' . e(url('login')) . '">Zur Anmeldung</a></p>'
    : '<p><a class="btn btn-ghost" href="' . e(url('')) . '">Zum Dashboard</a></p>';

if ($withLayout) {
    echo '<div class="card narrow">' . $inner . '</div>';
} else {
    $body = $inner;
    require __DIR__ . '/auth_layout.php';
}
