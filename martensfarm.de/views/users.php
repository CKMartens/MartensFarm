<?php
/** @var array $users @var array $farms @var array $errors @var ?array $edit @var array $old @var array $roles @var array $roleChoices @var string $filter @var array $counts @var bool $isAdmin */
$form = $errors ? $old : ($edit ?: []);
$isEdit = !empty($form['id']);
?>
<header class="page-head">
    <div>
        <h1>Nutzer &amp; Höfe</h1>
        <p class="muted">Wer hier angelegt ist, kann sich per E-Mail-Link anmelden. Ein offenes Registrieren gibt es bewusst nicht.</p>
    </div>
</header>

<?php if ($errors): ?>
    <div class="alert alert-error"><?= implode('<br>', array_map('e', $errors)) ?></div>
<?php endif; ?>

<div class="grid-2">
    <section class="card">
        <h2><?= $isEdit ? 'Nutzer bearbeiten' : 'Neuen Nutzer anlegen' ?></h2>
        <form method="post" action="<?= e(url('admin/nutzer')) ?>" class="stack">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="<?= $isEdit ? 'update' : 'create' ?>">
            <?php if ($isEdit): ?><input type="hidden" name="id" value="<?= (int) $form['id'] ?>"><?php endif; ?>
            <label>Name <input name="name" required maxlength="100" value="<?= e($form['name'] ?? '') ?>"></label>
            <label>Nickname (wie im Spiel) <input name="nickname" required maxlength="50" value="<?= e($form['nickname'] ?? '') ?>"></label>
            <label>E-Mail <input type="email" name="email" required maxlength="190" value="<?= e($form['email'] ?? '') ?>"></label>
            <label>Rolle
                <select name="role">
                    <?php foreach ($roleChoices as $key => $r): ?>
                        <option value="<?= e($key) ?>"<?= ($form['role'] ?? 'mitarbeiter') === $key ? ' selected' : '' ?>><?= e($r['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Hof
                <select name="farm_id">
                    <option value="">– keiner –</option>
                    <?php foreach ($farms as $id => $name): ?>
                        <option value="<?= (int) $id ?>"<?= (string) ($form['farm_id'] ?? '') === (string) $id ? ' selected' : '' ?>><?= e($name) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <div class="row">
                <button class="btn btn-primary" type="submit"><?= $isEdit ? 'Speichern' : 'Anlegen' ?></button>
                <?php if ($isEdit): ?><a class="btn btn-ghost" href="<?= e(url('admin/nutzer')) ?>">Abbrechen</a><?php endif; ?>
            </div>
        </form>
        <ul class="plain small muted">
            <?php foreach ($roleChoices as $r): ?>
                <li><b><?= e($r['name']) ?></b>: <?= e($r['description'] ?: '–') ?></li>
            <?php endforeach; ?>
        </ul>
        <?php if (can($user, 'admin.roles')): ?>
            <p class="small"><a href="<?= e(url('admin/rollen')) ?>">Rollen &amp; Rechte anpassen</a></p>
        <?php endif; ?>
    </section>

    <?php if ($edit && (int) $edit['is_active'] === 0 && $edit['email'] !== null && (int) $edit['id'] !== (int) $user['id']): ?>
    <section class="card">
        <h2>Anonymisieren</h2>
        <p class="muted small">Entfernt Name, E-Mail und Hofzuordnung von <b><?= e($edit['nickname']) ?></b>. Der Nickname bleibt, damit Buchungen und Aufgaben weiter zugeordnet sind. Das lässt sich nicht rückgängig machen.</p>
        <form method="post" action="<?= e(url('admin/nutzer')) ?>" class="stack">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="anonymize">
            <input type="hidden" name="id" value="<?= (int) $edit['id'] ?>">
            <label class="check-label"><input class="check" type="checkbox" name="confirm" value="1" required> Ja, persönliche Daten endgültig entfernen</label>
            <div class="row"><button class="btn btn-ghost" type="submit">Anonymisieren</button></div>
        </form>
    </section>
    <?php endif; ?>

    <section class="card">
        <h2>Höfe</h2>
        <?php if (!$farms): ?>
            <p class="muted">Noch keine Höfe – die kommen mit dem ersten Abruf vom Server.</p>
        <?php endif; ?>
        <?php foreach ($farms as $id => $name): ?>
            <form method="post" action="<?= e(url('admin/nutzer')) ?>" class="row">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="farm_name">
                <input type="hidden" name="farm_id" value="<?= (int) $id ?>">
                <span class="badge">Hof <?= (int) $id ?></span>
                <input name="farm_label" value="<?= e($name) ?>" maxlength="100">
                <button class="btn btn-ghost" type="submit">Umbenennen</button>
            </form>
        <?php endforeach; ?>
    </section>
</div>

<section class="card">
    <div class="page-head">
        <h2>Nutzer</h2>
        <div class="row">
            <?php foreach (['aktiv' => 'Aktiv', 'gesperrt' => 'Gesperrt', 'alle' => 'Alle'] as $key => $label): ?>
                <a class="btn btn-sm <?= $filter === $key ? 'btn-primary' : 'btn-ghost' ?>" href="<?= e(url('admin/nutzer?zeigen=' . $key)) ?>"><?= e($label) ?> (<?= (int) ($counts[$key] ?? 0) ?>)</a>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="table-wrap">
    <table class="table">
        <thead><tr><th>Nickname</th><th>Name</th><th>E-Mail</th><th>Rolle</th><th>Hof</th><th>Letzte Anmeldung</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($users as $u): ?>
            <tr class="<?= $u['is_active'] ? '' : 'inactive' ?>">
                <td><strong><?= e($u['nickname']) ?></strong></td>
                <td><?= e($u['name']) ?></td>
                <td><?= $u['email'] !== null ? e($u['email']) : '<span class="muted">anonymisiert</span>' ?></td>
                <td><?= e(role_name($u['role'])) ?></td>
                <td><?= e($u['farm_name'] ?? '–') ?></td>
                <td><?= e(fmt_datetime($u['last_login_at'])) ?></td>
                <td class="actions">
                    <?php if ($isAdmin || $u['role'] !== ADMIN_ROLE): ?>
                    <a class="btn btn-ghost btn-sm" href="<?= e(url('admin/nutzer?bearbeiten=' . (int) $u['id'])) ?>">Bearbeiten</a>
                    <form method="post" action="<?= e(url('admin/nutzer')) ?>" class="inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="toggle">
                        <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                        <button class="btn btn-ghost btn-sm" type="submit"><?= $u['is_active'] ? 'Sperren' : 'Freigeben' ?></button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$users): ?><tr><td colspan="7" class="muted">Keine Nutzer in dieser Ansicht.</td></tr><?php endif; ?>
        </tbody>
    </table>
    </div>
</section>
