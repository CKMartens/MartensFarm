<?php
/** @var array $users @var array $farms @var array $errors @var ?array $edit @var array $old */
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
                    <?php foreach (ROLE_NAMES as $key => $label): ?>
                        <option value="<?= e($key) ?>"<?= ($form['role'] ?? 'mitarbeiter') === $key ? ' selected' : '' ?>><?= e($label) ?></option>
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
        <p class="muted small">Rollen: <b>Admin</b> darf alles · <b>Hofleiter</b> verwaltet seinen Hof · <b>Mitarbeiter</b> arbeitet Aufgaben ab · <b>Gast</b> darf nur schauen.</p>
    </section>

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
    <h2>Alle Nutzer</h2>
    <div class="table-wrap">
    <table class="table">
        <thead><tr><th>Nickname</th><th>Name</th><th>E-Mail</th><th>Rolle</th><th>Hof</th><th>Letzte Anmeldung</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($users as $u): ?>
            <tr class="<?= $u['is_active'] ? '' : 'inactive' ?>">
                <td><strong><?= e($u['nickname']) ?></strong></td>
                <td><?= e($u['name']) ?></td>
                <td><?= e($u['email']) ?></td>
                <td><?= e(ROLE_NAMES[$u['role']] ?? $u['role']) ?></td>
                <td><?= e($u['farm_name'] ?? '–') ?></td>
                <td><?= e(fmt_datetime($u['last_login_at'])) ?></td>
                <td class="actions">
                    <a class="btn btn-ghost btn-sm" href="<?= e(url('admin/nutzer?bearbeiten=' . (int) $u['id'])) ?>">Bearbeiten</a>
                    <form method="post" action="<?= e(url('admin/nutzer')) ?>" class="inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="toggle">
                        <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                        <button class="btn btn-ghost btn-sm" type="submit"><?= $u['is_active'] ? 'Sperren' : 'Freigeben' ?></button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</section>
