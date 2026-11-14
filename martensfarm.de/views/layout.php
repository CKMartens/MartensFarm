<?php
/** @var string $content */
$u = current_user();
$nav = $nav ?? '';
$appName = (string) config('app.name', 'MartensFarm');
$items = [
    'dashboard' => ['', 'Dashboard', '▦'],
    'fuhrpark'  => ['fuhrpark', 'Fuhrpark', '🚜'],
    'tiere'     => ['tiere', 'Tiere', '🐄'],
    'lager'     => ['lager', 'Lager', '🌾'],
    'preise'    => ['preise', 'Preise', '€'],
];
?><!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e(($title ?? '') . ' · ' . $appName) ?></title>
    <link rel="stylesheet" href="<?= e(url('assets/app.css')) ?>">
</head>
<body>
<div class="shell">
    <aside class="sidebar">
        <a class="brand" href="<?= e(url('')) ?>"><span class="brand-mark">M</span><span>Martens<b>Farm</b></span></a>
        <?php if ($u): ?>
            <div class="farm-chip">
                <div class="farm-chip-name"><?= e($u['farm_name'] ?? 'Kein Hof zugeordnet') ?></div>
                <div class="farm-chip-sub"><?= e($u['nickname']) ?> · <?= e(role_name($u['role'])) ?></div>
            </div>
            <nav>
                <div class="nav-title">Betrieb</div>
                <?php foreach ($items as $key => [$path, $label, $icon]): ?>
                    <a class="nav-item<?= $nav === $key ? ' active' : '' ?>" href="<?= e(url($path)) ?>"><span class="nav-icon"><?= $icon ?></span><?= e($label) ?></a>
                <?php endforeach; ?>
                <?php if (can($u, 'finance.view')): ?>
                    <a class="nav-item<?= $nav === 'finanzen' ? ' active' : '' ?>" href="<?= e(url('finanzen')) ?>"><span class="nav-icon">📒</span>Finanzen</a>
                <?php endif; ?>
                <div class="nav-title">Organisation</div>
                <span class="nav-item disabled" title="Kommt in Phase 2"><span class="nav-icon">☑</span>Aufgaben</span>
                <span class="nav-item disabled" title="Kommt in Phase 3"><span class="nav-icon">🧾</span>Rechnungen</span>
                <?php if (can_any($u, ['admin.users', 'admin.roles', 'admin.settings', 'finance.manage'])): ?>
                    <div class="nav-title">Verwaltung</div>
                    <?php if (can($u, 'admin.users')): ?>
                        <a class="nav-item<?= $nav === 'nutzer' ? ' active' : '' ?>" href="<?= e(url('admin/nutzer')) ?>"><span class="nav-icon">👥</span>Nutzer &amp; Höfe</a>
                    <?php endif; ?>
                    <?php if (can($u, 'admin.roles')): ?>
                        <a class="nav-item<?= $nav === 'rollen' ? ' active' : '' ?>" href="<?= e(url('admin/rollen')) ?>"><span class="nav-icon">🔑</span>Rollen &amp; Rechte</a>
                    <?php endif; ?>
                    <?php if (can($u, 'finance.manage')): ?>
                        <a class="nav-item<?= $nav === 'buchungssaetze' ? ' active' : '' ?>" href="<?= e(url('admin/buchungssaetze')) ?>"><span class="nav-icon">🗂</span>Buchungssätze</a>
                    <?php endif; ?>
                    <?php if (can($u, 'admin.settings')): ?>
                        <a class="nav-item<?= $nav === 'einstellungen' ? ' active' : '' ?>" href="<?= e(url('admin/einstellungen')) ?>"><span class="nav-icon">⚙</span>Einstellungen</a>
                    <?php endif; ?>
                <?php endif; ?>
            </nav>
            <form method="post" action="<?= e(url('logout')) ?>" class="logout">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-ghost btn-block">Abmelden</button>
            </form>
        <?php endif; ?>
    </aside>
    <main class="main">
        <?php foreach (take_flashes() as $f): ?>
            <div class="alert alert-<?= e($f['type']) ?>"><?= e($f['message']) ?></div>
        <?php endforeach; ?>
        <?= $content ?>
    </main>
</div>
</body>
</html>
