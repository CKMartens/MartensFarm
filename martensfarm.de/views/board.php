<?php
/** @var array $user @var string $kind @var array $kinds @var array $posts @var array $form @var array $errors @var array $refOptions
 *  @var bool $canModerate @var bool $showHidden @var ?string $lastRead @var bool $openForm */
$tabs = ['' => 'Alle', 'announcement' => 'Ankündigungen', 'mod_wish' => 'Mod-Wünsche', 'note' => 'Hinweise'];
$modCls = ['open' => '', 'accepted' => 'ok', 'rejected' => 'bad', 'installed' => 'ok'];
?>
<header class="page-head">
    <div>
        <h1>Nachrichten</h1>
        <p class="muted">Ankündigungen, Mod-Wünsche und Hinweise, damit nichts im Chat untergeht.</p>
    </div>
</header>

<div class="tabs">
    <?php foreach ($tabs as $k => $label): ?>
        <a class="tab<?= $kind === $k ? ' active' : '' ?>" href="<?= e(url('nachrichten' . ($k !== '' ? '?art=' . $k : ''))) ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
    <?php if ($canModerate): ?>
        <a class="tab small" href="<?= e(url('nachrichten?' . ($kind !== '' ? 'art=' . $kind . '&' : '') . ($showHidden ? '' : 'ausgeblendet=1'))) ?>"><?= $showHidden ? 'Ausgeblendete verbergen' : 'Ausgeblendete zeigen' ?></a>
    <?php endif; ?>
</div>

<?php if ($kinds): ?>
    <section class="card" id="neu">
        <details<?= $openForm ? ' open' : '' ?>>
            <summary><h2>Neuer Beitrag</h2></summary>
            <?php if ($errors): ?><div class="alert alert-error"><?= implode('<br>', array_map('e', $errors)) ?></div><?php endif; ?>
            <form method="post" action="<?= e(url('nachrichten')) ?>" class="stack">
                <?= csrf_field() ?>
                <label>Art
                    <select name="kind">
                        <?php foreach ($kinds as $k => [$label, $icon]): ?>
                            <option value="<?= e($k) ?>"<?= $form['kind'] === $k ? ' selected' : '' ?>><?= $icon ?> <?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Titel
                    <input name="title" maxlength="150" required value="<?= e($form['title']) ?>" placeholder="z. B. Server-Neustart heute 20 Uhr, Mod „Courseplay“, Häcksler braucht Messer">
                </label>
                <label>Text
                    <textarea name="body" rows="4" maxlength="5000"><?= e($form['body']) ?></textarea>
                </label>
                <fieldset class="stack board-extra">
                    <legend class="muted small">Je nach Art</legend>
                    <label>Mod-Wunsch: Link zum ModHub oder zur Quelle
                        <input name="mod_url" type="url" maxlength="255" value="<?= e($form['mod_url'] ?? '') ?>" placeholder="https://www.farming-simulator.com/mod.php?…">
                    </label>
                    <label>Hinweis: Bezug (optional)
                        <select name="ref">
                            <option value="">– ohne Bezug –</option>
                            <?php foreach (['task' => 'Aufgaben', 'contract' => 'Lohnaufträge', 'vehicle' => 'Fahrzeuge'] as $t => $label): if (!$refOptions[$t]) continue; ?>
                                <optgroup label="<?= e($label) ?>">
                                    <?php foreach ($refOptions[$t] as $val => $name): ?>
                                        <option value="<?= e($val) ?>"<?= $form['ref'] === $val ? ' selected' : '' ?>><?= e($name) ?></option>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <?php if (isset($kinds['announcement'])): ?>
                        <div class="row">
                            <label class="check-label"><input class="check" type="checkbox" name="is_pinned" value="1"<?= !empty($form['is_pinned']) ? ' checked' : '' ?>> Ankündigung oben anheften</label>
                            <label>bis einschließlich
                                <input type="date" name="expires_at" value="<?= e($form['expires_at'] ?? '') ?>">
                            </label>
                        </div>
                    <?php endif; ?>
                </fieldset>
                <p class="muted small">Nur Text, kein HTML. Was nicht zur Art passt, wird ignoriert.</p>
                <div><button class="btn btn-primary" type="submit">Veröffentlichen</button></div>
            </form>
        </details>
    </section>
<?php endif; ?>

<section class="card">
    <?php if ($posts): ?>
        <ul class="plain board-list">
            <?php foreach ($posts as $p):
                [$kLabel, $kIcon] = BOARD_KINDS[$p['kind']] ?? ['?', '•'];
                $pinned = (int) $p['is_pinned'] === 1 && !board_expired($p);
                $isNew = ($lastRead === null || $p['last_activity'] > $lastRead) && (int) $p['created_by'] !== (int) $user['id'];
            ?>
                <li class="board-item<?= $p['hidden_at'] !== null ? ' inactive' : '' ?><?= $pinned ? ' pinned' : '' ?>">
                    <div>
                        <span class="board-icon" title="<?= e($kLabel) ?>"><?= $kIcon ?></span>
                        <a href="<?= e(url('nachrichten/beitrag?id=' . (int) $p['id'])) ?>"><strong><?= e($p['title']) ?></strong></a>
                        <?= $pinned ? ' <span class="badge warn">📌 angeheftet</span>' : '' ?>
                        <?= $p['kind'] === 'mod_wish' && $p['mod_status'] ? ' <span class="badge ' . $modCls[$p['mod_status']] . '">' . e(MOD_WISH_STATUSES[$p['mod_status']] ?? $p['mod_status']) . '</span>' : '' ?>
                        <?= $p['kind'] === 'announcement' && board_expired($p) ? ' <span class="badge">abgelaufen</span>' : '' ?>
                        <?= $p['hidden_at'] !== null ? ' <span class="badge">ausgeblendet</span>' : '' ?>
                        <?= $isNew ? ' <span class="badge ok">neu</span>' : '' ?>
                    </div>
                    <div class="muted small">
                        <?= e($kLabel) ?> von <?= e($p['nickname'] ?? '–') ?> · <?= e(fmt_datetime($p['created_at'])) ?>
                        <?= (int) $p['replies'] ? ' · ' . (int) $p['replies'] . ' Antwort' . ((int) $p['replies'] === 1 ? '' : 'en') . ', zuletzt ' . e(fmt_ago($p['last_activity'])) : '' ?>
                        <?php if ($r = board_ref($p)): ?> · <?= e($r[0]) ?>: <?= e($r[1]) ?><?php endif; ?>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php else: ?>
        <p class="muted">Noch nichts da<?= $kind !== '' ? ' in dieser Rubrik' : '' ?>.</p>
    <?php endif; ?>
</section>
