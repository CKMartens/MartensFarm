<?php
declare(strict_types=1);

/**
 * Aufgaben-Board und Lohnaufträge.
 *
 * Aufgaben hängen am aktuellen Savegame. Automatische Aufgaben (Werkstatt, Tierpflege) legt der Import an
 * und schließt sie wieder, sobald das Problem im Spiel behoben ist (tasks_auto_sync).
 */

/** Spalten des Boards in dieser Reihenfolge */
const TASK_COLUMNS = [
    'backlog' => 'Backlog',
    'todo'    => 'To Do',
    'doing'   => 'In Arbeit',
    'review'  => 'Prüfung',
    'done'    => 'Erledigt',
];
/** Alle Status: Spalten plus „verworfen“ (taucht nicht mehr auf dem Board auf) */
const TASK_STATUSES = TASK_COLUMNS + ['dropped' => 'Verworfen'];

const TASK_PRIORITIES = [1 => 'niedrig', 2 => 'normal', 3 => 'hoch', 4 => 'dringend'];

const TASK_REF_TYPES = ['vehicle' => 'Fahrzeug', 'farmland' => 'Feld', 'pen' => 'Stall'];

const CONTRACT_STATUSES = [
    'open'      => 'Angefragt',
    'accepted'  => 'Angenommen',
    'done'      => 'Erledigt',
    'cancelled' => 'Storniert',
];

// ---------------------------------------------------------------- Spielkalender

/**
 * Spieltag als lesbares Datum. Gibt ['day', 'date' (z. B. „7. Oktober“ oder null), 'offset' (Tage ab heute oder null),
 * 'rel' (heute, morgen, in 3 Tagen, seit 2 Tagen fällig)] zurück.
 */
function game_day_info(int $day): array
{
    $today = current_game_day();
    $info = ['day' => $day, 'date' => null, 'offset' => null, 'rel' => ''];
    if ($today === null) {
        return $info;
    }
    $k = $day - $today;
    $info['offset'] = $k;
    $info['rel'] = match (true) {
        $k === 0  => 'heute',
        $k === 1  => 'morgen',
        $k === -1 => 'seit gestern fällig',
        $k > 1    => 'in ' . $k . ' Tagen',
        default   => 'seit ' . -$k . ' Tagen fällig',
    };

    $period = current_period_key();
    $n = max(1, (int) setting_get('days_per_period', '1'));
    $d = (int) setting_get('current_day_in_period', '1');
    if ($period !== null) {
        $idx = ($d - 1) + $k;
        $shift = intdiv($idx, $n) - ($idx < 0 && $idx % $n !== 0 ? 1 : 0);
        $dayIn = $idx - $shift * $n + 1;
        $p = PERIOD_ORDER[(((array_search($period, PERIOD_ORDER, true) + $shift) % 12) + 12) % 12];
        $info['date'] = ($n > 1 ? $dayIn . '. ' : '') . period_name($p);
    }
    return $info;
}

/** Kurztext für eine Fälligkeit, z. B. „7. Oktober · in 3 Tagen“ */
function game_day_label(?int $day): string
{
    if ($day === null) {
        return '';
    }
    $i = game_day_info($day);
    $parts = array_filter([$i['date'] ?? ('Tag ' . $day), $i['rel']]);
    return implode(' · ', $parts);
}

/** Auswahl für Fälligkeiten: Spieltag => Text. Die nächsten drei Spielmonate, mindestens 14 Tage. */
function game_day_options(?int $keep = null): array
{
    $today = current_game_day();
    if ($today === null) {
        return [];
    }
    $n = max(1, (int) setting_get('days_per_period', '1'));
    $span = min(90, max(14, $n * 3));
    $out = [];
    if ($keep !== null && ($keep < $today || $keep > $today + $span)) {
        $out[$keep] = game_day_label($keep);
    }
    for ($day = $today; $day <= $today + $span; $day++) {
        $out[$day] = game_day_label($day);
    }
    return $out;
}

// ---------------------------------------------------------------- Lesen

/** Mitspieler, denen man Aufgaben geben kann: id => Nickname (aktive Nutzer mit Recht „Aufgaben bearbeiten“) */
function task_people(): array
{
    $out = [];
    foreach (q('SELECT id, nickname, role FROM users WHERE is_active = 1 ORDER BY nickname')->fetchAll() as $u) {
        if (can($u, 'tasks.work')) {
            $out[(int) $u['id']] = $u['nickname'];
        }
    }
    return $out;
}

/** Alle Nicknames (auch gesperrte), für Verlauf und Anzeige */
function user_names(): array
{
    static $names = null;
    if ($names === null) {
        $names = array_map('strval', q('SELECT id, nickname FROM users')->fetchAll(PDO::FETCH_KEY_PAIR));
    }
    return $names;
}

function task_get(int $id): ?array
{
    $t = q('SELECT * FROM tasks WHERE id = ? AND savegame_id <=> ?', [$id, current_savegame_id()])->fetch();
    return $t ?: null;
}

/** Aufgaben fürs Board. Filter: farm_id (0 = alle), mine (User-ID oder 0). Erledigte nur der letzten 30 Tage. */
function tasks_for_board(int $farmId, int $mine): array
{
    $sql = 'SELECT * FROM tasks WHERE savegame_id <=> ? AND status <> \'dropped\'
            AND (status <> \'done\' OR done_at > NOW() - INTERVAL 30 DAY)';
    $params = [current_savegame_id()];
    if ($farmId > 0) {
        $sql .= ' AND (farm_id = ? OR farm_id IS NULL)';
        $params[] = $farmId;
    }
    if ($mine > 0) {
        $sql .= ' AND assignee_id = ?';
        $params[] = $mine;
    }
    $sql .= ' ORDER BY priority DESC, due_day IS NULL, due_day, id';
    $cols = array_fill_keys(array_keys(TASK_COLUMNS), []);
    foreach (q($sql, $params)->fetchAll() as $t) {
        $cols[$t['status']][] = $t;
    }
    // Erledigt: die neuesten oben
    usort($cols['done'], fn($a, $b) => strcmp((string) $b['done_at'], (string) $a['done_at']));
    return $cols;
}

/** Offene Aufgaben eines Nutzers (Dashboard), überfällige und dringende zuerst */
function tasks_open_for_user(int $userId, int $limit = 8): array
{
    return q('SELECT * FROM tasks WHERE savegame_id <=> ? AND assignee_id = ? AND status IN (\'todo\', \'doing\', \'review\', \'backlog\')
              ORDER BY status = \'backlog\', due_day IS NULL, due_day, priority DESC, id LIMIT ' . max(1, $limit),
        [current_savegame_id(), $userId])->fetchAll();
}

function task_is_overdue(array $t): bool
{
    $today = current_game_day();
    return $t['due_day'] !== null && $today !== null && (int) $t['due_day'] < $today && !in_array($t['status'], ['done', 'dropped'], true);
}

/** Bezug lesbar machen: [Typ-Name, Name, Link oder null] */
function task_ref(array $t): ?array
{
    if (!$t['ref_type'] || $t['ref_id'] === null) {
        return null;
    }
    $sg = current_savegame_id();
    switch ($t['ref_type']) {
        case 'vehicle':
            $v = q('SELECT name, farm_id FROM vehicles WHERE unique_id = ?', [$t['ref_id']])->fetch();
            return ['Fahrzeug', $v['name'] ?? 'Fahrzeug', $v ? 'fuhrpark?hof=' . (int) $v['farm_id'] : null];
        case 'pen':
            $p = q('SELECT name, farm_id FROM husbandries WHERE savegame_id <=> ? AND pen_key = ?', [$sg, $t['ref_id']])->fetch();
            return ['Stall', $p['name'] ?? 'Stall', $p ? 'tiere?hof=' . (int) $p['farm_id'] : null];
        case 'farmland':
            return ['Feld', farmland_label((int) $t['ref_id']), null];
    }
    return null;
}

function farmland_label(int $id): string
{
    $name = q('SELECT name FROM farmlands WHERE savegame_id <=> ? AND farmland_id = ?', [current_savegame_id(), $id])->fetchColumn();
    return $name ? (string) $name : 'Feld ' . $id;
}

/** Auswahllisten für den Bezug: ['vehicle' => [id => Name], 'farmland' => [...], 'pen' => [...]] */
function task_ref_options(?int $farmId = null): array
{
    $sg = current_savegame_id();
    $out = ['vehicle' => [], 'farmland' => [], 'pen' => []];
    $farmSql = $farmId ? ' AND farm_id = ' . (int) $farmId : '';
    foreach (q('SELECT unique_id, name, farm_id FROM vehicles WHERE savegame_id <=> ? AND removed_at IS NULL' . $farmSql . ' ORDER BY name', [$sg])->fetchAll() as $v) {
        $out['vehicle'][$v['unique_id']] = $v['name'];
    }
    foreach (q('SELECT pen_key, name, animal_type FROM husbandries WHERE savegame_id <=> ? AND removed_at IS NULL' . $farmSql . ' ORDER BY name', [$sg])->fetchAll() as $p) {
        $out['pen'][$p['pen_key']] = $p['name'] . ' (' . animal_name($p['animal_type']) . ')';
    }
    foreach (q('SELECT farmland_id, name, owner_farm_id, area_ha FROM farmlands WHERE savegame_id <=> ? ORDER BY farmland_id', [$sg])->fetchAll() as $f) {
        $label = ($f['name'] ?: 'Feld ' . (int) $f['farmland_id']);
        if ((int) $f['owner_farm_id'] > 0) {
            $label .= ' · ' . farm_name((int) $f['owner_farm_id']);
        }
        if ($f['area_ha'] !== null) {
            $label .= ' · ' . fmt_num((float) $f['area_ha'], 2) . ' ha';
        }
        $out['farmland'][(string) $f['farmland_id']] = $label;
    }
    return $out;
}

function farm_name(int $farmId): string
{
    static $names = null;
    $names ??= q('SELECT farm_id, name FROM farms')->fetchAll(PDO::FETCH_KEY_PAIR);
    return (string) ($names[$farmId] ?? 'Hof ' . $farmId);
}

/** Verlauf einer Aufgabe, älteste zuerst */
function task_events(int $taskId): array
{
    return q('SELECT * FROM task_events WHERE task_id = ? ORDER BY id', [$taskId])->fetchAll();
}

// ---------------------------------------------------------------- Rechte

function tasks_can_see(?array $user): bool
{
    return $user !== null;   // das Board sehen alle Angemeldeten, auch Gäste (nur lesen)
}

/** Bearbeiten (Titel, Text, Fälligkeit …): Verteiler alles, sonst nur selbst angelegte */
function task_can_edit(?array $user, array $t): bool
{
    if (can($user, 'tasks.assign')) {
        return true;
    }
    return can($user, 'tasks.create') && $t['created_by'] !== null && (int) $t['created_by'] === (int) ($user['id'] ?? 0);
}

/** Muss ein Verteiler „Prüfung → Erledigt“ abhaken? (Einstellung) */
function tasks_review_required(): bool
{
    return setting_int('tasks_review_required') === 1;
}

/** In welche Status darf der Nutzer die Aufgabe schieben? (ohne den aktuellen) */
function task_allowed_moves(?array $user, array $t): array
{
    $all = array_keys(TASK_STATUSES);
    if (can($user, 'tasks.assign')) {
        $to = $all;
    } elseif (can($user, 'tasks.work')) {
        $uid = (int) ($user['id'] ?? 0);
        $mine = $t['assignee_id'] !== null && (int) $t['assignee_id'] === $uid;
        $free = $t['assignee_id'] === null;
        if (!$mine && !$free) {
            $to = [];
        } else {
            $to = ['backlog', 'todo', 'doing', 'review'];
            if (!tasks_review_required()) {
                $to[] = 'done';
            }
            if ($t['status'] === 'done') {
                $to = tasks_review_required() ? [] : $to;   // abgehakte Aufgaben öffnet nur ein Verteiler wieder
            }
        }
        if (task_can_edit($user, $t)) {
            $to[] = 'dropped';
        }
    } else {
        $to = [];
    }
    return array_values(array_diff(array_unique($to), [$t['status']]));
}

function task_can_take(?array $user, array $t): bool
{
    return can($user, 'tasks.work') && $t['assignee_id'] === null && !in_array($t['status'], ['done', 'dropped'], true);
}

function task_can_comment(?array $user): bool
{
    return can_any($user, ['tasks.work', 'tasks.create', 'tasks.assign']);
}

// ---------------------------------------------------------------- Schreiben

function task_log(int $taskId, ?int $userId, string $kind, string $text = ''): void
{
    q('INSERT INTO task_events (task_id, user_id, kind, text, created_at) VALUES (?, ?, ?, ?, ?)',
        [$taskId, $userId, $kind, mb_substr($text, 0, 1000), now()]);
}

/**
 * Aufgabe anlegen. $data: title, description, farm_id, priority, assignee_id, due_day, ref_type, ref_id, contract_id, status, auto_key.
 * $user null = automatisch.
 */
function task_create(?array $user, array $data): int
{
    $now = now();
    $status = isset(TASK_COLUMNS[$data['status'] ?? '']) ? $data['status'] : 'todo';
    q('INSERT INTO tasks (savegame_id, farm_id, title, description, status, priority, assignee_id, due_day, ref_type, ref_id, contract_id, auto_key,
                          created_by, created_at, updated_at, done_at, done_by)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
        $data['savegame_id'] ?? current_savegame_id(),
        ($data['farm_id'] ?? null) ?: null,
        mb_substr((string) $data['title'], 0, 150),
        ($data['description'] ?? '') !== '' ? mb_substr((string) $data['description'], 0, 5000) : null,
        $status,
        isset(TASK_PRIORITIES[(int) ($data['priority'] ?? 2)]) ? (int) $data['priority'] : 2,
        ($data['assignee_id'] ?? null) ?: null,
        $data['due_day'] ?? null,
        $data['ref_type'] ?? null,
        $data['ref_id'] ?? null,
        $data['contract_id'] ?? null,
        $data['auto_key'] ?? null,
        $user ? (int) $user['id'] : null,
        $now, $now,
        $status === 'done' ? $now : null,
        $status === 'done' && $user ? (int) $user['id'] : null,
    ]);
    $id = (int) db()->lastInsertId();
    task_log($id, $user ? (int) $user['id'] : null, 'created', $user ? '' : 'Automatisch angelegt');
    if (!empty($data['assignee_id'])) {
        task_log($id, $user ? (int) $user['id'] : null, 'assign', 'an ' . (user_names()[(int) $data['assignee_id']] ?? '?'));
    }
    return $id;
}

/** Status ändern (ohne Rechteprüfung, die macht der Aufrufer). Setzt Erledigt-Zeitpunkt und hakt ggf. den Lohnauftrag ab. */
function task_set_status(array $t, string $status, ?array $user, string $note = ''): void
{
    if (!isset(TASK_STATUSES[$status]) || $status === $t['status']) {
        return;
    }
    $uid = $user ? (int) $user['id'] : null;
    $done = $status === 'done';
    q('UPDATE tasks SET status = ?, updated_at = ?, done_at = ?, done_by = ? WHERE id = ?',
        [$status, now(), $done ? now() : null, $done ? $uid : null, (int) $t['id']]);
    task_log((int) $t['id'], $uid, $user ? 'status' : 'auto', TASK_STATUSES[$t['status']] . ' → ' . TASK_STATUSES[$status] . ($note !== '' ? ' (' . $note . ')' : ''));

    // Wer eine freie Aufgabe in Arbeit nimmt, übernimmt sie
    if ($user && $t['assignee_id'] === null && in_array($status, ['doing', 'review'], true) && can($user, 'tasks.work')) {
        task_assign($t, $uid, $user);
    }
    if ($done && $t['contract_id'] !== null) {
        contract_check_done((int) $t['contract_id'], $user);
    }
}

function task_assign(array $t, ?int $assigneeId, ?array $user): void
{
    if (($t['assignee_id'] === null ? null : (int) $t['assignee_id']) === $assigneeId) {
        return;
    }
    q('UPDATE tasks SET assignee_id = ?, updated_at = ? WHERE id = ?', [$assigneeId, now(), (int) $t['id']]);
    $uid = $user ? (int) $user['id'] : null;
    if ($assigneeId === null) {
        task_log((int) $t['id'], $uid, 'assign', 'wieder offen (niemand zugewiesen)');
    } elseif ($assigneeId === $uid) {
        task_log((int) $t['id'], $uid, 'assign', 'übernommen');
    } else {
        task_log((int) $t['id'], $uid, 'assign', 'an ' . (user_names()[$assigneeId] ?? '?'));
    }
}

/**
 * Formular einer Aufgabe prüfen. Gibt [Daten, Fehler] zurück.
 */
function task_form_read(array $post, array $people, array $farms): array
{
    $errors = [];
    $title = trim((string) ($post['title'] ?? ''));
    $desc = trim((string) ($post['description'] ?? ''));
    $farmId = (int) ($post['farm_id'] ?? 0);
    $prio = (int) ($post['priority'] ?? 2);
    $assignee = (int) ($post['assignee_id'] ?? 0);
    $dueRaw = trim((string) ($post['due_day'] ?? ''));
    // Bezug kommt als „typ:id“ aus einer Auswahl mit Gruppen (Fahrzeuge, Ställe, Felder)
    [$refType, $refId] = array_pad(explode(':', (string) ($post['ref'] ?? ''), 2), 2, '');

    if ($title === '' || mb_strlen($title) > 150) {
        $errors[] = 'Bitte einen Titel angeben (höchstens 150 Zeichen).';
    }
    if (mb_strlen($desc) > 5000) {
        $errors[] = 'Die Beschreibung ist zu lang (höchstens 5000 Zeichen).';
    }
    if ($farmId !== 0 && !isset($farms[$farmId])) {
        $errors[] = 'Diesen Hof gibt es nicht.';
    }
    if (!isset(TASK_PRIORITIES[$prio])) {
        $prio = 2;
    }
    if ($assignee !== 0 && !isset($people[$assignee])) {
        $errors[] = 'Diese Person kann keine Aufgaben übernehmen.';
    }
    $due = null;
    if ($dueRaw !== '') {
        if (!ctype_digit($dueRaw) || (int) $dueRaw > 1_000_000) {
            $errors[] = 'Die Fälligkeit passt nicht.';
        } else {
            $due = (int) $dueRaw;
        }
    }
    if (!isset(TASK_REF_TYPES[$refType]) || $refId === '') {
        $refType = null;
        $refId = null;
    } elseif (mb_strlen($refId) > 80 || ($refType === 'farmland' && !ctype_digit($refId))) {
        $errors[] = 'Der Bezug passt nicht.';
    }
    return [[
        'title' => $title, 'description' => $desc, 'farm_id' => $farmId, 'priority' => $prio,
        'assignee_id' => $assignee, 'due_day' => $due, 'ref_type' => $refType, 'ref_id' => $refId,
    ], $errors];
}

// ---------------------------------------------------------------- Automatische Aufgaben

/** Wann der letzte Save eingelesen wurde (Uhr des Webservers), als Unix-Zeit oder null */
function last_save_ts(): ?int
{
    $ts = strtotime((string) setting_get('mf_imported_at', ''));
    return $ts ?: null;
}

/**
 * Soll-Liste der automatischen Aufgaben gegen die offenen abgleichen:
 * neue anlegen, bestehende aktualisieren, behobene schließen.
 * Hat jemand eine Auto-Aufgabe selbst abgehakt, kommt sie erst nach dem nächsten Save wieder (falls das Problem dann noch besteht).
 */
function tasks_auto_sync(int $sgId, string $now, array &$log): void
{
    $want = [];   // auto_key => Daten

    if (setting_int('tasks_auto_repair') === 1) {
        $threshold = setting_int('maintenance_threshold_pct') / 100;
        foreach (q('SELECT unique_id, farm_id, name, damage FROM vehicles WHERE savegame_id = ? AND removed_at IS NULL AND damage >= ?', [$sgId, $threshold])->fetchAll() as $v) {
            if (!farm_is_active((int) $v['farm_id'])) {
                continue;
            }
            $dmg = (float) $v['damage'];
            $want['repair:' . $v['unique_id']] = [
                'title' => 'Werkstatt: ' . $v['name'],
                'description' => 'Schaden ' . fmt_num($dmg * 100) . ' % (Schwelle ' . setting_int('maintenance_threshold_pct') . ' %). Die Aufgabe schließt sich nach der Reparatur von selbst, sobald der Server gespeichert hat.',
                'farm_id' => (int) $v['farm_id'],
                'priority' => $dmg >= min(1, $threshold + 0.1) ? 3 : 2,
                'ref_type' => 'vehicle', 'ref_id' => $v['unique_id'],
            ];
        }
    }

    if (setting_int('tasks_auto_animals') === 1) {
        $titles = ['food' => 'Futter nachfüllen', 'water' => 'Wasser auffüllen', 'straw' => 'Stroh einstreuen'];
        foreach (q('SELECT * FROM husbandries WHERE savegame_id = ? AND removed_at IS NULL AND animals > 0', [$sgId])->fetchAll() as $pen) {
            if (!farm_is_active((int) $pen['farm_id'])) {
                continue;
            }
            foreach (pen_alerts($pen) as $a) {
                $what = $titles[$a['key']] ?? (filltype_name((string) ($a['type'] ?? '')) . ' abholen');
                $want['pen:' . $pen['pen_key'] . ':' . $a['key']] = [
                    'title' => $what . ': ' . $pen['name'],
                    'description' => $a['text'] . ' (' . animal_name($pen['animal_type']) . '). Schließt sich von selbst, sobald es nach dem nächsten Save wieder passt.',
                    'farm_id' => (int) $pen['farm_id'],
                    'priority' => $a['level'] === 'bad' ? 4 : (str_starts_with($a['key'], 'product_') ? 2 : 3),
                    'ref_type' => 'pen', 'ref_id' => $pen['pen_key'],
                ];
            }
        }
    }

    // Offene Auto-Aufgaben
    $open = [];
    foreach (q('SELECT * FROM tasks WHERE savegame_id = ? AND auto_key IS NOT NULL AND status NOT IN (\'done\', \'dropped\')', [$sgId])->fetchAll() as $t) {
        $open[$t['auto_key']] = $t;
    }
    $saveTs = last_save_ts();
    $created = $closed = 0;

    foreach ($want as $key => $d) {
        if (isset($open[$key])) {
            $t = $open[$key];
            if ($t['description'] !== $d['description'] || (int) $t['priority'] < $d['priority']) {
                q('UPDATE tasks SET description = ?, priority = GREATEST(priority, ?), updated_at = ? WHERE id = ?', [$d['description'], $d['priority'], $now, (int) $t['id']]);
            }
            continue;
        }
        // Selbst abgehakt oder verworfen, seitdem aber noch kein neuer Save? Dann nicht gleich wieder anlegen.
        $last = q('SELECT done_at, updated_at, status FROM tasks WHERE savegame_id = ? AND auto_key = ? ORDER BY id DESC LIMIT 1', [$sgId, $key])->fetch();
        if ($last) {
            $closedAt = strtotime((string) ($last['done_at'] ?? $last['updated_at']));
            if ($saveTs === null || $closedAt >= $saveTs) {
                continue;
            }
        }
        task_create(null, $d + ['savegame_id' => $sgId, 'status' => 'todo', 'auto_key' => $key]);
        $created++;
    }

    foreach ($open as $key => $t) {
        if (!isset($want[$key])) {
            task_set_status($t, 'done', null, 'automatisch: im Spiel behoben');
            $closed++;
        }
    }
    if ($created || $closed) {
        $log[] = "Aufgaben: {$created} neu, {$closed} automatisch erledigt.";
    }
}

// ---------------------------------------------------------------- Lohnaufträge

function contract_get(int $id): ?array
{
    $c = q('SELECT * FROM contracts WHERE id = ? AND savegame_id <=> ?', [$id, current_savegame_id()])->fetch();
    return $c ?: null;
}

function contract_client(array $c): string
{
    if ($c['client_farm_id'] !== null) {
        return farm_name((int) $c['client_farm_id']);
    }
    return (string) ($c['client_name'] ?: 'extern');
}

function contract_title(array $c): string
{
    return $c['service'] . ($c['farmland_id'] !== null ? ' · ' . farmland_label((int) $c['farmland_id']) : '') . ' für ' . contract_client($c);
}

/** Vorschläge (Einnahme beim Auftragnehmer, Ausgabe beim Auftraggeber im Spiel) mit Status */
function contract_suggestions(array $c): array
{
    return q('SELECT * FROM booking_suggestions WHERE savegame_id <=> ? AND ref_type = \'contract\' AND ref_id = ?',
        [$c['savegame_id'], (string) $c['id']])->fetchAll();
}

/** Lohnauftrag abschließen: Status erledigt und Buchungsvorschläge anlegen */
function contract_finish(array $c, ?array $user): void
{
    if (in_array($c['status'], ['done', 'cancelled'], true)) {
        return;
    }
    $day = current_game_day();
    q('UPDATE contracts SET status = \'done\', done_at = ?, done_day = ?, updated_at = ? WHERE id = ?', [now(), $day, now(), (int) $c['id']]);
    if ($c['savegame_id'] === null) {
        return;
    }
    $sg = (int) $c['savegame_id'];
    $title = $c['service'] . ($c['farmland_id'] !== null ? ' · ' . farmland_label((int) $c['farmland_id']) : '');
    $price = (int) $c['price'] > 0 ? (int) $c['price'] : null;
    add_suggestion($sg, (int) $c['farm_id'], 'contract_income', 'contract', (string) $c['id'], $title . ' für ' . contract_client($c), $price, $day, now());
    if ($c['client_farm_id'] !== null && (int) $c['client_farm_id'] !== (int) $c['farm_id']) {
        add_suggestion($sg, (int) $c['client_farm_id'], 'contract_cost', 'contract', (string) $c['id'], $title . ' von ' . farm_name((int) $c['farm_id']), $price, $day, now());
    }
}

/** Nach einer erledigten Aufgabe: sind alle Aufgaben des Auftrags durch, ist der Auftrag erledigt */
function contract_check_done(int $contractId, ?array $user): void
{
    $c = q('SELECT * FROM contracts WHERE id = ?', [$contractId])->fetch();
    if (!$c || !in_array($c['status'], ['open', 'accepted'], true)) {
        return;
    }
    $left = (int) q('SELECT COUNT(*) FROM tasks WHERE contract_id = ? AND status NOT IN (\'done\', \'dropped\')', [$contractId])->fetchColumn();
    $done = (int) q('SELECT COUNT(*) FROM tasks WHERE contract_id = ? AND status = \'done\'', [$contractId])->fetchColumn();
    if ($left === 0 && $done > 0) {
        contract_finish($c, $user);
    }
}

/** Lohnaufträge des aktuellen Savegames, offene zuerst */
function contracts_list(bool $withClosed): array
{
    $sql = 'SELECT c.*, (SELECT COUNT(*) FROM tasks t WHERE t.contract_id = c.id AND t.status NOT IN (\'done\', \'dropped\')) AS tasks_open,
                   (SELECT COUNT(*) FROM tasks t WHERE t.contract_id = c.id AND t.status <> \'dropped\') AS tasks_all
            FROM contracts c WHERE c.savegame_id <=> ?';
    if (!$withClosed) {
        $sql .= ' AND (c.status IN (\'open\', \'accepted\') OR c.done_at > NOW() - INTERVAL 30 DAY)';
    }
    $sql .= ' ORDER BY FIELD(c.status, \'open\', \'accepted\', \'done\', \'cancelled\'), c.due_day IS NULL, c.due_day, c.id DESC';
    return q($sql, [current_savegame_id()])->fetchAll();
}

/** Formular eines Lohnauftrags prüfen. Gibt [Daten, Fehler] zurück. */
function contract_form_read(array $post, array $farms): array
{
    $errors = [];
    $farmId = (int) ($post['farm_id'] ?? 0);
    $client = (string) ($post['client'] ?? '');
    $clientName = trim((string) ($post['client_name'] ?? ''));
    $service = trim((string) ($post['service'] ?? ''));
    $farmland = trim((string) ($post['farmland_id'] ?? ''));
    $priceRaw = str_replace(['.', ' ', '€'], '', trim((string) ($post['price'] ?? '')));
    $priceRaw = str_replace(',', '.', $priceRaw);
    $dueRaw = trim((string) ($post['due_day'] ?? ''));
    $note = trim((string) ($post['note'] ?? ''));

    if (!isset($farms[$farmId])) {
        $errors[] = 'Bitte den Hof wählen, der die Arbeit macht.';
    }
    $clientFarm = null;
    if ($client === 'extern') {
        if ($clientName === '' || mb_strlen($clientName) > 100) {
            $errors[] = 'Bitte den Namen des externen Auftraggebers angeben (höchstens 100 Zeichen).';
        }
    } elseif (ctype_digit($client) && isset($farms[(int) $client])) {
        $clientFarm = (int) $client;
        $clientName = '';
    } else {
        $errors[] = 'Bitte einen Auftraggeber wählen.';
    }
    if ($clientFarm !== null && $clientFarm === $farmId) {
        $errors[] = 'Auftraggeber und unser Hof sind derselbe. Für eigene Arbeit reicht eine normale Aufgabe.';
    }
    if ($service === '' || mb_strlen($service) > 150) {
        $errors[] = 'Bitte die Leistung angeben (höchstens 150 Zeichen).';
    }
    if ($farmland !== '' && !ctype_digit($farmland)) {
        $errors[] = 'Das Feld passt nicht.';
    }
    if ($priceRaw === '') {
        $priceRaw = '0';
    }
    if (!is_numeric($priceRaw) || (float) $priceRaw < 0 || (float) $priceRaw > 100_000_000_000) {
        $errors[] = 'Der Preis muss eine Zahl ab 0 sein.';
    }
    $due = null;
    if ($dueRaw !== '') {
        if (!ctype_digit($dueRaw) || (int) $dueRaw > 1_000_000) {
            $errors[] = 'Die Fälligkeit passt nicht.';
        } else {
            $due = (int) $dueRaw;
        }
    }
    if (mb_strlen($note) > 5000) {
        $errors[] = 'Die Notiz ist zu lang.';
    }
    return [[
        'farm_id' => $farmId, 'client' => $client, 'client_farm_id' => $clientFarm, 'client_name' => $clientName,
        'service' => $service, 'farmland_id' => $farmland === '' ? null : (int) $farmland,
        'price' => is_numeric($priceRaw) ? (int) round((float) $priceRaw) : 0, 'due_day' => $due, 'note' => $note,
    ], $errors];
}

// ---------------------------------------------------------------- Aktionen aus Formularen

/** Aufgabe verschieben mit Rechteprüfung. Gibt [ok, Meldung] zurück. */
function task_move_request(array $user, int $taskId, string $status): array
{
    $t = task_get($taskId);
    if ($t === null) {
        return [false, 'Die Aufgabe gibt es nicht (mehr).'];
    }
    if ($status === $t['status']) {
        return [true, ''];
    }
    if (!isset(TASK_STATUSES[$status]) || !in_array($status, task_allowed_moves($user, $t), true)) {
        return [false, $status === 'done' && tasks_review_required() && !can($user, 'tasks.assign')
            ? 'Abhaken darf nur, wer Aufgaben verteilt. Schieb sie in „Prüfung“.'
            : 'Dorthin darfst du diese Aufgabe nicht schieben.'];
    }
    task_set_status($t, $status, $user);
    return [true, '„' . $t['title'] . '“ → ' . TASK_STATUSES[$status] . '.'];
}
