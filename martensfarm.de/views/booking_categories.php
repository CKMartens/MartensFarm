<?php
/** @var array $cats @var array $used @var array $stats @var array $seen @var array $map */
$groups = ['income' => 'Einnahmen', 'expense' => 'Ausgaben'];
?>
<header class="page-head">
    <div>
        <h1>Buchungssätze</h1>
        <p class="muted">Kategorien fürs Kassenbuch und welche Geldbewegung aus dem Spiel wohin gehört.</p>
    </div>
</header>

<div class="grid-2">
    <section class="card">
        <h2>Kategorien</h2>
        <p class="muted small">Löschen gibt es nicht, damit alte Buchungen ihre Kategorie behalten. Deaktivierte tauchen beim Buchen nicht mehr auf.</p>
        <form method="post" action="<?= e(url('admin/buchungssaetze')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="categories">
            <table class="table compact">
                <thead><tr><th>Aktiv</th><th>Name</th><th class="num">Reihenfolge</th><th class="num">Buchungen</th></tr></thead>
                <?php foreach ($groups as $kind => $label): ?>
                    <tbody>
                    <tr class="group-row"><th colspan="4"><?= e($label) ?></th></tr>
                    <?php foreach ($cats as $id => $c): if ($c['kind'] !== $kind) continue; ?>
                        <tr<?= (int) $c['is_active'] === 1 ? '' : ' class="inactive"' ?>>
                            <td><input class="check" type="checkbox" name="active[<?= (int) $id ?>]" value="1"<?= (int) $c['is_active'] === 1 ? ' checked' : '' ?> aria-label="<?= e($c['name']) ?> aktiv"></td>
                            <td><input name="name[<?= (int) $id ?>]" value="<?= e($c['name']) ?>" maxlength="100" required></td>
                            <td class="num"><input type="number" name="sort[<?= (int) $id ?>]" value="<?= (int) $c['sort_order'] ?>" min="0" max="999"></td>
                            <td class="num"><?= (int) ($used[$id] ?? 0) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                <?php endforeach; ?>
                <tbody>
                <tr class="group-row"><th colspan="4">Neu</th></tr>
                <tr>
                    <td>
                        <select name="new_kind" aria-label="Art">
                            <option value="income">Einnahme</option>
                            <option value="expense" selected>Ausgabe</option>
                        </select>
                    </td>
                    <td><input name="new_name" maxlength="100" placeholder="Name, leer = keine neue"></td>
                    <td class="num"><input type="number" name="new_sort" value="100" min="0" max="999"></td>
                    <td></td>
                </tr>
                </tbody>
            </table>
            <p><button class="btn btn-primary" type="submit">Speichern</button></p>
        </form>
    </section>

    <section class="card">
        <h2>Zuordnung Spiel → Buchungssatz</h2>
        <p class="muted small">Die Export-Mod meldet jede Geldbewegung mit der Art, die das Spiel intern verwendet. Hier legst du fest, in welchem Buchungssatz sie als Vorschlag auftaucht.
            „Automatisch“ heißt: Sonstiges, je nach Vorzeichen. Passt das Vorzeichen nicht zum Buchungssatz, landet der Betrag ebenfalls unter Sonstiges.</p>
        <form method="post" action="<?= e(url('admin/buchungssaetze')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="map">
            <div class="table-wrap">
            <table class="table compact">
                <thead><tr><th>Im Spiel</th><th class="num">Gemeldet</th><th>Buchungssatz</th></tr></thead>
                <tbody>
                <?php foreach ($stats as $stat): $m = $map[$stat] ?? null; $cur = $m === null ? 'auto' : ($m['is_ignored'] ? 'ignore' : ($m['category_id'] ?? 'auto')); ?>
                    <tr>
                        <td><?= e(stat_name($stat)) ?><br><code class="small"><?= e($stat) ?></code></td>
                        <td class="num"><?= isset($seen[$stat]) ? e(fmt_money((float) $seen[$stat]['amount'])) . '<br><span class="muted small">' . (int) $seen[$stat]['n'] . '×</span>' : '<span class="muted">–</span>' ?></td>
                        <td>
                            <select name="map[<?= e($stat) ?>]" aria-label="Buchungssatz für <?= e(stat_name($stat)) ?>">
                                <option value="auto"<?= $cur === 'auto' ? ' selected' : '' ?>>Automatisch (Sonstiges)</option>
                                <option value="ignore"<?= $cur === 'ignore' ? ' selected' : '' ?>>Nicht vorschlagen</option>
                                <?php foreach ($groups as $kind => $label): ?>
                                    <optgroup label="<?= e($label) ?>">
                                        <?php foreach ($cats as $id => $c): if ($c['kind'] !== $kind) continue; ?>
                                            <option value="<?= (int) $id ?>"<?= (string) $cur === (string) $id ? ' selected' : '' ?>><?= e($c['name']) ?><?= (int) $c['is_active'] === 1 ? '' : ' (inaktiv)' ?></option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <p><button class="btn btn-primary" type="submit">Zuordnung speichern</button></p>
        </form>
    </section>
</div>
