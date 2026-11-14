<?php
/** Schlichter Rahmen für Anmeldeseiten. Erwartet $title und $body (HTML). */
$appName = (string) config('app.name', 'MartensFarm');
?><!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e(($title ?? '') . ' · ' . $appName) ?></title>
    <link rel="stylesheet" href="<?= e(url('assets/app.css')) ?>">
</head>
<body class="auth">
<div class="auth-card">
    <div class="brand brand-center"><span class="brand-mark">M</span><span>Martens<b>Farm</b></span></div>
    <?= $body ?>
</div>
</body>
</html>
