<?php
/** @var array $user @var array $farms @var int $farmId @var ?array $farm @var array $savegames @var int $sgId @var ?int $currentSg @var bool $readOnly
 *  @var array $rec @var array $gameOpen @var array $suggestions @var array $bookings @var array $totals @var array $cats @var ?int $gameDay @var bool $hasGameData
 *  @var array $months @var array $deletions @var ?array $confirmDelete */
$sign = fn(int $v): string => ($v > 0 ? '+' : '') . fmt_money($v);
$cls = fn(int $v): string => $v > 0 ? 'ok' : ($v < 0 ? 'bad' : 'muted');
$open = (int) $rec['open'];
$q = '?hof=' . $farmId . ($sgId !== (int) $currentSg ? '&sg=' . $sgId : '');
$isLocked = fn(int $day): bool => $sgId > 0 && finance_day_locked($sgId, $farmId, $day) !== null;
$monthStatus = [
    'closed'  => ['abgeschlossen', ''],
    'ready'   => ['kann abgeschlossen werden', 'warn'],
    'waiting' => ['wartet auf den nächsten Save', ''],
    'running' => ['läuft noch', ''],
];
?>
<header class="page-head">
    <div>
        <h1>Finanzen</h1>
        <p class="muted">Kassenbuch und Abgleich mit dem Kontostand im Spiel<?= $gameDay !== null ? ' · heute ist Spieltag ' . (int) $gameDay : '' ?>.</p>
    </div>
    <div class="btn-row">
        <form method="get" action="<?= e(url('finanzen')) ?>" class="inline">
            <?php if (count($farms) > 1): ?>
                <select name="hof" aria-label="Hof">
                    <?php foreach ($farms as $id => $name): ?>
                        <option value="<?= (int) $id ?>"<?= (int) $id === $farmId ? ' selected' : '' ?>><?= e($name) ?></option>
                    <?php endforeach; ?>
                </select>
            <?php else: ?>
                <input type="hidden" name="hof" value="<?= (int) $farmId ?>">
            <?php endif; ?>
            <?php if (count($savegames) > 1): ?>
                <select name="sg" aria-label="Savegame">
                    <?php foreach ($savegames as $s): ?>
                        <option value="<?= (int) $s['id'] ?>"<?= (int) $s['id'] === $sgId ? ' selected' : '' ?>><?= e($s['savegame_name']) ?><?= (int) $s['id'] === (int) $currentSg ? ' (aktuell)' : ' (Archiv)' ?></option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>
            <?php if (count($farms) > 1 || count($savegames) > 1): ?><button class="btn btn-ghost btn-sm" type="submit">Anzeigen</button><?php endif; ?>
        </form>
        <?php if (!$readOnly): ?>
            <a class="btn btn-primary" href="<?= e(url('finanzen/buchen?hof=' . $farmId)) ?>">+ Buchen</a>
        <?php endif; ?>
    </div>
</header>

<?php if ($sgId !== (int) $currentSg): ?>
    <div class="alert alert-info">Archiv: Dieses Savegame läuft nicht mehr, hier kann nur noch geschaut werden.</div>
<?php elseif ($gameDay === null): ?>
    <div class="alert alert-warning">Der Spieltag ist noch unbekannt. Er kommt mit dem nächsten Save, dafür muss die Export-Mod ab Version 0.3 laufen.</div>
<?php endif; ?>

<?php if ($confirmDelete): $cd = $confirmDelete; ?>
    <section class="card" id="loeschen">
        <h2>Buchung endgültig löschen?</h2>
        <p>Tag <?= (int) $cd['game_day'] ?> · <?= e($cats[(int) $cd['category_id']]['name'] ?? '?') ?> · <strong><?= e($sign((int) $cd['amount'])) ?></strong>
            <?= $cd['text'] !== '' ? '· ' . e($cd['text']) : '' ?></p>
        <p class="muted small">Weg ist weg: Die Buchung verschwindet aus Kassenbuch und Abgleich. Im Löschprotokoll unten bleibt stehen, was es war und wer sie gelöscht hat.
            Für normale Fehlbuchungen reicht meist „Stornieren“.</p>
        <form method="post" action="<?= e(url('finanzen' . $q)) ?>" class="stack">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= (int) $cd['id'] ?>">
            <label>Grund (optional)
                <input type="text" name="reason" maxlength="255" placeholder="z. B. doppelt erfasst, Tippfehler">
            </label>
            <div class="btn-row">
                <button class="btn btn-primary" type="submit">Endgültig löschen</button>
                <a class="btn btn-ghost" href="<?= e(url('finanzen' . $q)) ?>">Abbrechen</a>
            </div>
        </form>
    </section>
<?php endif; ?>

<section class="tiles">
    <div class="tile">
        <div class="tile-label">Kontostand <?= e($farms[$farmId] ?? ('Hof ' . $farmId)) ?></div>
        <div class="tile-value money"><?= e(fmt_money(isset($farm['money']) ? (int) $farm['money'] : null)) ?></div>
        <div class="tile-sub"><?= ($farm['loan'] ?? 0) > 0 ? 'Kredit: ' . e(fmt_money((int) $farm['loan'])) : 'Kein Kredit' ?></div>
    </div>
    <div class="tile">
        <div class="tile-label">Nicht zugeordnet</div>
        <div class="tile-value <?= abs($open) >= 1 ? 'warn' : 'ok' ?>"><?= e($sign($open)) ?></div>
        <div class="tile-sub"><?= $rec['start'] ? 'seit Spieltag ' . (int) $rec['start']['day'] . ($rec['booked_after'] ? ' · ' . e(fmt_money($rec['booked_after'])) . ' gebucht, aber noch nicht gespeichert' : '') : 'Abgleich startet mit dem ersten Save' ?></div>
    </div>
    <div class="tile">
        <div class="tile-label">Gebucht</div>
        <div class="tile-value"><span class="ok"><?= e(fmt_money((int) $totals['income'])) ?></span></div>
        <div class="tile-sub">Einnahmen · Ausgaben <span class="bad"><?= e(fmt_money((int) $totals['expense'])) ?></span></div>
    </div>
    <div class="tile">
        <div class="tile-label">Vorschläge</div>
        <div class="tile-value"><?= count(array_filter($gameOpen, fn($r) => $r['fits'])) + count($suggestions) ?></div>
        <div class="tile-sub">aus dem Spiel und aus Fahrzeugen/Land</div>
    </div>
</section>

<?php if (abs($open) >= 1): ?>
    <div class="alert alert-warning">
        Der Kontostand hat sich um <strong><?= e($sign($open)) ?></strong> anders bewegt, als gebucht wurde. Da fehlen wohl Buchungen.
        <?= $gameOpen ? 'Die Vorschläge aus dem Spiel unten helfen beim Zuordnen.' : '' ?>
    </div>
<?php endif; ?>

<div class="grid-2">
    <section class="card">
        <div class="page-head">
            <h2>Vorschläge aus dem Spiel</h2>
            <?php if (!$readOnly && array_filter($gameOpen, fn($r) => $r['fits'])): ?>
                <form method="post" action="<?= e(url('finanzen' . $q)) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="game_all">
                    <button class="btn btn-ghost btn-sm" type="submit">Alle übernehmen</button>
                </form>
            <?php endif; ?>
        </div>
        <?php if ($gameOpen): ?>
            <div class="table-wrap">
            <table class="table compact">
                <thead><tr><th>Tag</th><th>Buchungssatz</th><th class="num">Laut Spiel</th><th class="num">Gebucht</th><th class="num">Offen</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($gameOpen as $r): $c = $cats[$r['category_id']] ?? null; ?>
                    <tr>
                        <td><?= (int) $r['day'] ?></td>
                        <td><?= e($c['name'] ?? '?') ?><br><span class="muted small"><?= e(implode(', ', $r['stats'])) ?></span></td>
                        <td class="num <?= $cls($r['game']) ?>"><?= e(fmt_money($r['game'])) ?></td>
                        <td class="num"><?= e(fmt_money($r['booked'])) ?></td>
                        <td class="num <?= $cls($r['open']) ?>"><?= e($sign($r['open'])) ?></td>
                        <td class="actions">
                            <?php if (!$readOnly && $r['fits']): ?>
                                <form method="post" action="<?= e(url('finanzen' . $q)) ?>">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="game">
                                    <input type="hidden" name="day" value="<?= (int) $r['day'] ?>">
                                    <input type="hidden" name="category" value="<?= (int) $r['category_id'] ?>">
                                    <button class="btn btn-ghost btn-sm" type="submit">Übernehmen</button>
                                </form>
                            <?php elseif (!$r['fits']): ?>
                                <span class="badge warn" title="In diesem Buchungssatz wurde an dem Tag mehr gebucht, als laut Spiel passiert ist">zu viel gebucht</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        <?php elseif ($hasGameData): ?>
            <p class="muted">Alles zugeordnet, was das Spiel gemeldet hat. 👍</p>
        <?php else: ?>
            <p class="muted">Noch keine Daten. Die kommen mit der Export-Mod ab Version 0.3: Sie zählt jede Geldbewegung im Spiel mit (Fahrzeugkauf, Treibstoff, Ernteverkauf, Lohn …) und meldet sie beim Speichern.</p>
        <?php endif; ?>
        <p class="muted small">Pro Spieltag und Buchungssatz: was das Spiel gemeldet hat, abzüglich dem, was schon gebucht ist. Zuordnung unter Verwaltung → Buchungssätze.</p>
    </section>

    <section class="card">
        <h2>Erkannte Ereignisse</h2>
        <?php if ($suggestions): ?>
            <ul class="plain">
                <?php foreach ($suggestions as $s): ?>
                    <li class="row">
                        <span><span class="badge"><?= e(SUGGESTION_KINDS[$s['kind']][0] ?? $s['kind']) ?></span>
                            <?= e($s['title']) ?><?= $s['amount'] !== null ? ' · ' . e(fmt_money((int) $s['amount'])) : '' ?>
                            <span class="muted small"><?= $s['game_day'] !== null ? '· Tag ' . (int) $s['game_day'] : '' ?></span></span>
                        <?php if (!$readOnly): ?>
                            <span class="actions">
                                <a class="btn btn-ghost btn-sm" href="<?= e(url('finanzen/buchen?vorschlag=' . (int) $s['id'])) ?>">Buchen</a>
                                <form method="post" action="<?= e(url('finanzen' . $q)) ?>" class="inline">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="dismiss">
                                    <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                                    <button class="btn btn-ghost btn-sm" type="submit" title="Nicht buchen, z. B. weil es schon über die Spiel-Vorschläge gebucht ist">Verwerfen</button>
                                </form>
                            </span>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <p class="muted">Nichts Neues. Hier tauchen gekaufte und verkaufte Fahrzeuge sowie Farmland-Wechsel auf.</p>
        <?php endif; ?>
        <p class="muted small">Tipp: Wer einen Fahrzeugkauf hier bucht, sieht ihn bei den Spiel-Vorschlägen nicht mehr doppelt, solange Tag und Buchungssatz passen.</p>
    </section>
</div>

<section class="card">
    <h2>Monatsabschluss</h2>
    <?php if ($months): ?>
        <div class="table-wrap">
        <table class="table compact">
            <thead><tr><th>Spielmonat</th><th class="num">Nicht zugeordnet</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($months as $m): [$stLabel, $stCls] = $monthStatus[$m['status']]; $c = $m['close']; ?>
                <tr>
                    <td><?= e($m['label']) ?></td>
                    <td class="num"><?php if ($c): ?>
                            <?= (int) $c['open_amount'] !== 0 ? 'Korrektur ' . e($sign((int) $c['open_amount'])) : '<span class="ok">✓</span>' ?>
                        <?php else: ?>
                            <?= abs($m['open']) >= 1 ? '<span class="badge warn">' . e($sign($m['open'])) . '</span>' : '<span class="ok">✓</span>' ?>
                        <?php endif; ?></td>
                    <td><span class="badge <?= $stCls ?>"><?= $c ? '🔒 ' : '' ?><?= e($stLabel) ?></span>
                        <?php if ($c): ?><br><span class="muted small">von <?= e($c['closed_nick'] ?? '?') ?>, <?= e(fmt_datetime($c['closed_at'])) ?></span><?php endif; ?></td>
                    <td class="actions">
                        <?php if ($m['status'] === 'ready' && can($user, 'finance.close')): ?>
                            <form method="post" action="<?= e(url('finanzen' . $q)) ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="close_month">
                                <input type="hidden" name="month" value="<?= (int) $m['start'] ?>">
                                <button class="btn btn-ghost btn-sm" type="submit"
                                        title="<?= abs($m['open']) >= 1 ? 'Bucht ' . e($sign($m['open'])) . ' als Korrektur (Abgleich) und sperrt die Tage' : 'Sperrt die Tage, es ist nichts zu korrigieren' ?>">Abschließen</button>
                            </form>
                        <?php elseif ($c && $sgId === (int) $currentSg && can($user, 'finance.manage')): ?>
                            <form method="post" action="<?= e(url('finanzen' . $q)) ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="reopen_month">
                                <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                                <button class="btn btn-ghost btn-sm" type="submit" title="Storniert die Korrektur, die Tage sind wieder buchbar">Wieder öffnen</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php else: ?>
        <p class="muted">Noch kein Monat zum Abschließen. Dafür braucht es den Spielkalender aus der Export-Mod und mindestens zwei Saves.</p>
    <?php endif; ?>
    <p class="muted small">Ist ein Spielmonat vorbei und hat der Server danach gespeichert, lässt sich der Rest, der sich nicht zuordnen lässt
        (Kleinbeträge, „Einkauf, ohne Angabe“ …), mit einer Buchung auf <em>Korrektur (Abgleich)</em> ausgleichen. Danach steht der Monat auf 0
        und ist gesperrt: keine neuen Buchungen, Stornos oder Löschungen mehr für seine Tage. Wieder öffnen kann, wer Buchungssätze verwalten darf.</p>
</section>

<section class="card">
    <h2>Abgleich je Spieltag</h2>
    <?php if ($rec['rows']): ?>
        <div class="table-wrap">
        <table class="table compact">
            <thead><tr><th>Spieltag</th><th class="num">Kontostand</th><th class="num">Änderung</th><th class="num">davon Kredit</th><th class="num">Gebucht</th><th class="num">Laut Spiel</th><th class="num">Nicht zugeordnet</th></tr></thead>
            <tbody>
            <?php foreach (array_slice($rec['rows'], 0, 40) as $r): ?>
                <tr>
                    <td><?= $r['from_day'] < $r['day'] ? (int) $r['from_day'] . '–' : '' ?><?= (int) $r['day'] ?><?= $gameDay !== null && $r['day'] === $gameDay ? ' <span class="badge">läuft</span>' : '' ?><?= $isLocked((int) $r['day']) ? ' <span title="Monat abgeschlossen">🔒</span>' : '' ?></td>
                    <td class="num"><?= e(fmt_money($r['money'])) ?></td>
                    <td class="num <?= $cls($r['delta']) ?>"><?= e($sign($r['delta'])) ?></td>
                    <td class="num"><?= $r['loan_delta'] ? e($sign($r['loan_delta'])) : '–' ?></td>
                    <td class="num"><?= e($sign($r['booked'])) ?></td>
                    <td class="num"><?= $r['game'] !== null ? e($sign($r['game'])) : '–' ?></td>
                    <td class="num"><?= abs($r['open']) >= 1 ? '<span class="badge warn">' . e($sign($r['open'])) . '</span>' : '<span class="ok">✓</span>' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php else: ?>
        <p class="muted">Noch nichts abzugleichen. Dafür braucht es mindestens zwei Saves mit Spieltag<?= $rec['start'] ? ' (Start: Spieltag ' . (int) $rec['start']['day'] . ', ' . e(fmt_money($rec['start']['money'])) . ')' : '' ?>.</p>
    <?php endif; ?>
    <p class="muted small">Kontostand = letzter Save des Tages. Nicht zugeordnet = Änderung − Kredit − Buchungen. Der Kontostand ist nur so frisch wie der letzte Server-Save,
        Buchungen für den laufenden Tag können deshalb kurz als „zu viel“ erscheinen.</p>
</section>

<section class="card">
    <h2>Kassenbuch</h2>
    <?php if ($bookings): ?>
        <div class="table-wrap">
        <table class="table compact">
            <thead><tr><th>Tag</th><th>Buchungssatz</th><th>Text</th><th class="num">Betrag</th><th>Von</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($bookings as $b): $cancelled = $b['cancelled_at'] !== null; ?>
                <tr<?= $cancelled ? ' class="inactive"' : '' ?>>
                    <td><?= (int) $b['game_day'] ?></td>
                    <td><?= e($cats[(int) $b['category_id']]['name'] ?? '?') ?><?= $b['source'] === 'game' ? ' <span class="badge">Spiel</span>' : '' ?><?= $b['source'] === 'correction' ? ' <span class="badge">Monatsabschluss</span>' : '' ?><?= $isLocked((int) $b['game_day']) ? ' <span title="Monat abgeschlossen">🔒</span>' : '' ?></td>
                    <td><?= e($b['text']) ?><?= $cancelled ? '<br><span class="small">storniert von ' . e($b['cancelled_nick'] ?? '?') . ', ' . e(fmt_datetime($b['cancelled_at'])) . '</span>' : '' ?></td>
                    <td class="num <?= $cancelled ? '' : $cls((int) $b['amount']) ?>"><?= e($sign((int) $b['amount'])) ?></td>
                    <td><?= e($b['nickname'] ?? '–') ?><br><span class="muted small"><?= e(fmt_datetime($b['created_at'])) ?></span></td>
                    <td class="actions">
                        <?php if (!$readOnly && finance_can_cancel($user, $b)): ?>
                            <form method="post" action="<?= e(url('finanzen' . $q)) ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="cancel">
                                <input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
                                <button class="btn btn-ghost btn-sm" type="submit">Stornieren</button>
                            </form>
                        <?php endif; ?>
                        <?php if (finance_can_delete($user, $b)): ?>
                            <a class="btn btn-ghost btn-sm" href="<?= e(url('finanzen' . $q . '&loeschen=' . (int) $b['id'] . '#loeschen')) ?>">Löschen</a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php else: ?>
        <p class="muted">Noch keine Buchungen.</p>
    <?php endif; ?>
</section>

<?php if ($deletions): ?>
    <section class="card">
        <h2>Löschprotokoll</h2>
        <div class="table-wrap">
        <table class="table compact">
            <thead><tr><th>Tag</th><th>Buchungssatz</th><th>Text</th><th class="num">Betrag</th><th>Angelegt</th><th>Gelöscht</th></tr></thead>
            <tbody>
            <?php foreach ($deletions as $d): ?>
                <tr class="inactive">
                    <td><?= (int) $d['game_day'] ?></td>
                    <td><?= e($cats[(int) $d['category_id']]['name'] ?? '?') ?><?= (int) $d['was_cancelled'] === 1 ? ' <span class="badge">war storniert</span>' : '' ?></td>
                    <td><?= e($d['text']) ?><?= $d['reason'] !== '' ? '<br><span class="small">Grund: ' . e($d['reason']) . '</span>' : '' ?></td>
                    <td class="num"><?= e($sign((int) $d['amount'])) ?></td>
                    <td><?= e($d['created_nick'] ?? '–') ?><br><span class="muted small"><?= e(fmt_datetime($d['created_at'])) ?></span></td>
                    <td><?= e($d['deleted_nick'] ?? '–') ?><br><span class="muted small"><?= e(fmt_datetime($d['deleted_at'])) ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </section>
<?php endif; ?>
