<?php
/** @var array $roles @var array $errors @var array $userCounts @var array $granted @var array $groups @var array $old */
$fromPost = $errors && ($old['action'] ?? '') === 'save';
$nameOf = fn (string $k) => $fromPost ? (string) ($old['name'][$k] ?? '') : $roles[$k]['name'];
$descOf = fn (string $k) => $fromPost ? (string) ($old['description'][$k] ?? '') : $roles[$k]['description'];
$has = fn (string $k, string $p) => $k === ADMIN_ROLE || ($fromPost ? in_array($p, (array) ($old['perm'][$k] ?? []), true) : in_array($p, $granted[$k], true));
?>
<header class="page-head">
    <div>
        <h1>Rollen &amp; Rechte</h1>
        <p class="muted">Jede Rolle ist ein Bündel von Rechten. Die App fragt nur Rechte ab, neue Rollen sind also reine Einstellungssache.
            <b><?= e($roles[ADMIN_ROLE]['name'] ?? 'Admin') ?></b> darf immer alles, damit sich niemand aussperrt.</p>
    </div>
</header>

<?php if ($errors): ?>
    <div class="alert alert-error"><?= implode('<br>', array_map('e', $errors)) ?></div>
<?php endif; ?>

<form method="post" action="<?= e(url('admin/rollen')) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <section class="card">
        <h2>Rollen</h2>
        <div class="table-wrap">
        <table class="table compact">
            <thead><tr><th>Rolle</th><th>Beschreibung</th><th class="num">Nutzer</th></tr></thead>
            <tbody>
            <?php foreach ($roles as $k => $r): ?>
                <tr>
                    <td><input name="name[<?= e($k) ?>]" value="<?= e($nameOf($k)) ?>" maxlength="50" required aria-label="Name der Rolle <?= e($k) ?>"></td>
                    <td><input name="description[<?= e($k) ?>]" value="<?= e($descOf($k)) ?>" maxlength="255" aria-label="Beschreibung der Rolle <?= e($k) ?>"></td>
                    <td class="num"><?= (int) ($userCounts[$k] ?? 0) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </section>

    <section class="card">
        <h2>Rechte</h2>
        <p class="muted small">Rechte mit dem Hinweis „kommt mit …“ wirken erst, wenn die Funktion da ist. Man kann sie aber schon jetzt vergeben.</p>
        <div class="table-wrap">
        <table class="table compact matrix">
            <thead>
            <tr>
                <th>Recht</th>
                <?php foreach ($roles as $k => $r): ?><th class="center"><?= e($r['name']) ?></th><?php endforeach; ?>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($groups as $group => $perms): ?>
                <tr class="group-row"><th colspan="<?= count($roles) + 1 ?>"><?= e($group) ?></th></tr>
                <?php foreach ($perms as $p => [$label, $hint]): ?>
                    <tr>
                        <td><?= e($label) ?><br><span class="muted small"><?= e($hint) ?></span></td>
                        <?php foreach ($roles as $k => $r): ?>
                            <td class="center">
                                <input class="check" type="checkbox" name="perm[<?= e($k) ?>][]" value="<?= e($p) ?>"<?= $has($k, $p) ? ' checked' : '' ?><?= $k === ADMIN_ROLE ? ' disabled' : '' ?> aria-label="<?= e($r['name'] . ': ' . $label) ?>">
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <p><button class="btn btn-primary" type="submit">Speichern</button></p>
    </section>
</form>

<div class="grid-2">
    <section class="card">
        <h2>Neue Rolle</h2>
        <form method="post" action="<?= e(url('admin/rollen')) ?>" class="stack">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create">
            <label>Name <input name="new_name" required maxlength="50" value="<?= e(($old['action'] ?? '') === 'create' ? ($old['new_name'] ?? '') : '') ?>" placeholder="z. B. Lohnunternehmer"></label>
            <label>Beschreibung <input name="new_description" maxlength="255" value="<?= e(($old['action'] ?? '') === 'create' ? ($old['new_description'] ?? '') : '') ?>"></label>
            <label>Rechte übernehmen von
                <select name="copy_from">
                    <option value="">– keine Rechte –</option>
                    <?php foreach ($roles as $k => $r): if ($k === ADMIN_ROLE) { continue; } ?>
                        <option value="<?= e($k) ?>"><?= e($r['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <div class="row"><button class="btn btn-primary" type="submit">Anlegen</button></div>
        </form>
    </section>

    <section class="card">
        <h2>Rolle löschen</h2>
        <p class="muted small">Geht nur für selbst angelegte Rollen, die niemand (auch kein gesperrter Nutzer) mehr hat. Die mitgelieferten Rollen kann man nur umbenennen.</p>
        <?php $deletable = array_filter($roles, fn ($r) => (int) $r['is_system'] === 0); ?>
        <?php if (!$deletable): ?>
            <p class="muted">Noch keine eigenen Rollen angelegt.</p>
        <?php endif; ?>
        <?php foreach ($deletable as $k => $r): ?>
            <form method="post" action="<?= e(url('admin/rollen')) ?>" class="row">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="role_key" value="<?= e($k) ?>">
                <span><?= e($r['name']) ?> <span class="muted small">(<?= (int) ($userCounts[$k] ?? 0) ?> Nutzer)</span></span>
                <button class="btn btn-ghost btn-sm" type="submit"<?= ($userCounts[$k] ?? 0) > 0 ? ' disabled' : '' ?>>Löschen</button>
            </form>
        <?php endforeach; ?>
    </section>
</div>
