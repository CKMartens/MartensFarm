<?php
/** @var array $farms @var array $cats @var array $form @var array $errors @var ?array $suggestion @var ?int $gameDay */
$groups = ['income' => 'Einnahmen', 'expense' => 'Ausgaben'];
?>
<header class="page-head">
    <div>
        <h1>Buchen</h1>
        <p class="muted">Einnahme oder Ausgabe ins Kassenbuch eintragen. Im Spiel passiert dadurch nichts, die App führt nur Buch.</p>
    </div>
</header>

<?php if ($errors): ?>
    <div class="alert alert-error"><?= implode('<br>', array_map('e', $errors)) ?></div>
<?php endif; ?>

<div class="grid-2">
    <section class="card">
        <?php if ($suggestion): ?>
            <p class="alert alert-info small">Aus Vorschlag: <?= e(SUGGESTION_KINDS[$suggestion['kind']][0] ?? '') ?> · <?= e($suggestion['title']) ?>.
                <?= $suggestion['amount'] === null ? 'Den Betrag kennt die App nicht, bitte aus dem Spiel nehmen (Finanzen → Tagesübersicht).' : 'Der Betrag ist der Listenpreis, bitte kurz prüfen.' ?></p>
        <?php endif; ?>
        <form method="post" action="<?= e(url('finanzen/buchen')) ?>" class="stack">
            <?= csrf_field() ?>
            <?php if ($suggestion): ?><input type="hidden" name="suggestion_id" value="<?= (int) $suggestion['id'] ?>"><?php endif; ?>
            <label>Hof
                <select name="farm_id">
                    <?php foreach ($farms as $id => $name): ?>
                        <option value="<?= (int) $id ?>"<?= (int) $id === (int) $form['farm_id'] ? ' selected' : '' ?>><?= e($name) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Buchungssatz
                <select name="category_id" required>
                    <option value="">– bitte wählen –</option>
                    <?php foreach ($groups as $kind => $label): ?>
                        <optgroup label="<?= e($label) ?>">
                            <?php foreach ($cats as $id => $c): if ($c['kind'] !== $kind) continue; ?>
                                <option value="<?= (int) $id ?>"<?= (int) $id === (int) $form['category_id'] ? ' selected' : '' ?>><?= e($c['name']) ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Betrag in € (ohne Vorzeichen, Einnahme oder Ausgabe ergibt sich aus dem Buchungssatz)
                <input name="amount" inputmode="decimal" required maxlength="20" value="<?= e($form['amount']) ?>" placeholder="z. B. 12500">
            </label>
            <label>Spieltag<?= $gameDay !== null ? ' (heute: ' . (int) $gameDay . ')' : '' ?>
                <input type="number" name="game_day" min="0" required value="<?= (int) $form['game_day'] ?>">
            </label>
            <label>Text
                <input name="text" maxlength="255" value="<?= e($form['text']) ?>" placeholder="z. B. Weizen an die Mühle, 30.000 l">
            </label>
            <div class="row">
                <button class="btn btn-primary" type="submit">Buchen</button>
                <a class="btn btn-ghost" href="<?= e(url('finanzen?hof=' . (int) $form['farm_id'])) ?>">Abbrechen</a>
            </div>
        </form>
    </section>
    <section class="card">
        <h2>Wie zählt das?</h2>
        <ul class="small">
            <li>Der <strong>Spieltag</strong> entscheidet, zu welchem Abschnitt im Abgleich die Buchung gehört, nicht wann du sie einträgst.</li>
            <li>Der Kontostand kommt nur beim Server-Save. Bis dahin kann eine frische Buchung im Abgleich als „zu viel“ erscheinen, das gleicht sich aus.</li>
            <li>Vertippt? Einfach im Kassenbuch <strong>stornieren</strong> und neu buchen. Gelöscht wird nichts.</li>
            <li>Laufende Kosten wie Treibstoff, Helferlohn und Wartung musst du nicht einzeln buchen: Die kommen als Vorschlag aus dem Spiel.</li>
        </ul>
    </section>
</div>
