<?php
declare(strict_types=1);

/**
 * Nachrichtenboard: Hinweise, Ankündigungen und Mod-Wünsche.
 *
 * Gilt über Savegames hinweg. Text ohne HTML (wird beim Ausgeben escaped), ausblenden statt löschen.
 * Rechte: board.post (schreiben), board.announce (ankündigen, anheften), board.moderate (fremde ausblenden, Mod-Status).
 */

const BOARD_KINDS = [
    'announcement' => ['Ankündigung', '📣'],
    'mod_wish'     => ['Mod-Wunsch', '🧩'],
    'note'         => ['Hinweis', '💬'],
];

const MOD_WISH_STATUSES = [
    'open'      => 'offen',
    'accepted'  => 'angenommen',
    'rejected'  => 'abgelehnt',
    'installed' => 'installiert',
];

/** Worauf sich ein Hinweis beziehen kann (mit eigener Detailseite) */
const BOARD_REF_TYPES = ['task' => 'Aufgabe', 'contract' => 'Lohnauftrag', 'vehicle' => 'Fahrzeug'];

/** Höchstens so viele Beiträge plus Antworten pro Nutzer und Stunde */
const BOARD_RATE_PER_HOUR = 30;

function board_post_get(int $id): ?array
{
    $p = q('SELECT p.*, u.nickname FROM board_posts p LEFT JOIN users u ON u.id = p.created_by WHERE p.id = ?', [$id])->fetch();
    return $p ?: null;
}

/** Ist die Ankündigung abgelaufen? (zählt bis einschließlich Ablauftag) */
function board_expired(array $p): bool
{
    return $p['expires_at'] !== null && $p['expires_at'] < date('Y-m-d');
}

/**
 * Beiträge für die Liste. $kind = '' für alle. Ausgeblendete nur mit $withHidden.
 * Reihenfolge: angeheftete (nicht abgelaufene) Ankündigungen zuerst, dann nach letzter Aktivität.
 */
function board_list(string $kind, bool $withHidden, int $limit = 100): array
{
    $where = [];
    $args = [];
    if ($kind !== '' && isset(BOARD_KINDS[$kind])) {
        $where[] = 'p.kind = ?';
        $args[] = $kind;
    }
    if (!$withHidden) {
        $where[] = 'p.hidden_at IS NULL';
    }
    $sql = 'SELECT p.*, u.nickname,
                   (SELECT COUNT(*) FROM board_replies r WHERE r.post_id = p.id AND r.hidden_at IS NULL) AS replies,
                   GREATEST(p.created_at, COALESCE((SELECT MAX(r.created_at) FROM board_replies r WHERE r.post_id = p.id AND r.hidden_at IS NULL), p.created_at)) AS last_activity
            FROM board_posts p LEFT JOIN users u ON u.id = p.created_by'
        . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
        . ' ORDER BY (p.is_pinned = 1 AND (p.expires_at IS NULL OR p.expires_at >= CURDATE())) DESC, last_activity DESC LIMIT ' . max(1, $limit);
    return q($sql, $args)->fetchAll();
}

/** Beiträge zu einer Aufgabe, einem Lohnauftrag oder Fahrzeug (für deren Detailseite) */
function board_for_ref(string $refType, string $refId): array
{
    return q('SELECT p.*, u.nickname FROM board_posts p LEFT JOIN users u ON u.id = p.created_by
              WHERE p.ref_type = ? AND p.ref_id = ? AND p.hidden_at IS NULL ORDER BY p.created_at DESC LIMIT 20', [$refType, $refId])->fetchAll();
}

function board_replies(int $postId, bool $withHidden): array
{
    return q('SELECT r.*, u.nickname FROM board_replies r LEFT JOIN users u ON u.id = r.created_by
              WHERE r.post_id = ?' . ($withHidden ? '' : ' AND r.hidden_at IS NULL') . ' ORDER BY r.id', [$postId])->fetchAll();
}

/** Bezug lesbar machen: [Art, Name, Link] oder null */
function board_ref(array $p): ?array
{
    if (!$p['ref_type'] || $p['ref_id'] === null || !isset(BOARD_REF_TYPES[$p['ref_type']])) {
        return null;
    }
    $label = BOARD_REF_TYPES[$p['ref_type']];
    switch ($p['ref_type']) {
        case 'task':
            $t = q('SELECT title FROM tasks WHERE id = ?', [(int) $p['ref_id']])->fetchColumn();
            return [$label, $t !== false ? (string) $t : 'gelöscht', $t !== false ? 'aufgaben/aufgabe?id=' . (int) $p['ref_id'] : null];
        case 'contract':
            $c = q('SELECT * FROM contracts WHERE id = ?', [(int) $p['ref_id']])->fetch();
            return [$label, $c ? contract_title($c) : 'gelöscht', $c ? 'lohnauftraege/auftrag?id=' . (int) $p['ref_id'] : null];
        case 'vehicle':
            $v = q('SELECT name FROM vehicles WHERE unique_id = ?', [$p['ref_id']])->fetchColumn();
            return [$label, $v !== false ? (string) $v : 'unbekannt', $v !== false ? 'fuhrpark/fahrzeug?id=' . rawurlencode((string) $p['ref_id']) : null];
    }
    return null;
}

/** Prüft, ob es das Bezugsobjekt im aktuellen Savegame gibt */
function board_ref_valid(string $type, string $id): bool
{
    $sg = current_savegame_id();
    return match ($type) {
        'task'     => (bool) q('SELECT 1 FROM tasks WHERE id = ? AND savegame_id <=> ?', [(int) $id, $sg])->fetchColumn(),
        'contract' => (bool) q('SELECT 1 FROM contracts WHERE id = ? AND savegame_id <=> ?', [(int) $id, $sg])->fetchColumn(),
        'vehicle'  => vehicle_get($id) !== null,
        default    => false,
    };
}

// ---------------------------------------------------------------- Rechte

function board_can_post(?array $user, string $kind): bool
{
    return $kind === 'announcement' ? can($user, 'board.announce') : can($user, 'board.post');
}

function board_can_reply(?array $user, array $p): bool
{
    return can($user, 'board.post') && $p['hidden_at'] === null;
}

/** Eigene Beiträge darf man selbst ausblenden, fremde nur mit board.moderate */
function board_can_hide(?array $user, array $p): bool
{
    if (can($user, 'board.moderate')) {
        return true;
    }
    return can($user, 'board.post') && $p['created_by'] !== null && (int) $p['created_by'] === (int) ($user['id'] ?? 0);
}

function board_can_edit(?array $user, array $p): bool
{
    if ($p['kind'] === 'announcement') {
        return can($user, 'board.announce');
    }
    return can($user, 'board.moderate')
        || (can($user, 'board.post') && $p['created_by'] !== null && (int) $p['created_by'] === (int) ($user['id'] ?? 0));
}

/** Rate-Limit: Beiträge plus Antworten in der letzten Stunde */
function board_rate_ok(array $user): bool
{
    $n = (int) q('SELECT (SELECT COUNT(*) FROM board_posts WHERE created_by = ? AND created_at > NOW() - INTERVAL 1 HOUR)
                       + (SELECT COUNT(*) FROM board_replies WHERE created_by = ? AND created_at > NOW() - INTERVAL 1 HOUR)',
        [(int) $user['id'], (int) $user['id']])->fetchColumn();
    return $n < BOARD_RATE_PER_HOUR;
}

// ---------------------------------------------------------------- Formular

/**
 * Formular prüfen. Gibt [Daten, Fehler] zurück. $existing = bearbeiteter Beitrag (Art bleibt dann).
 */
function board_form_read(array $post, array $user, ?array $existing = null): array
{
    $errors = [];
    $kind = $existing['kind'] ?? (string) ($post['kind'] ?? 'note');
    if (!isset(BOARD_KINDS[$kind])) {
        $kind = 'note';
    }
    $title = trim((string) ($post['title'] ?? ''));
    $body = trim(str_replace("\r\n", "\n", (string) ($post['body'] ?? '')));
    $data = [
        'kind' => $kind, 'title' => $title, 'body' => $body, 'ref_type' => null, 'ref_id' => null,
        'is_pinned' => 0, 'is_sticky' => 0, 'expires_at' => null, 'mod_url' => null,
    ];
    if ($title === '' || mb_strlen($title) > 150) {
        $errors[] = 'Bitte einen Titel angeben (höchstens 150 Zeichen).';
    }
    if (mb_strlen($body) > 5000) {
        $errors[] = 'Der Text ist zu lang (höchstens 5000 Zeichen).';
    }
    if (!board_can_post($user, $kind)) {
        $errors[] = 'Diese Art von Beitrag darfst du nicht schreiben.';
    }
    if ($kind === 'announcement') {
        $data['is_pinned'] = isset($post['is_pinned']) ? 1 : 0;
        // Nicht ausblendbar gilt nur fürs Dashboard, also nur zusammen mit „anheften“
        $data['is_sticky'] = $data['is_pinned'] && isset($post['is_sticky']) ? 1 : 0;
        $exp = trim((string) ($post['expires_at'] ?? ''));
        if ($exp !== '') {
            $d = DateTimeImmutable::createFromFormat('!Y-m-d', $exp);
            if (!$d || $d->format('Y-m-d') !== $exp) {
                $errors[] = 'Das Ablaufdatum passt nicht.';
            } else {
                $data['expires_at'] = $exp;
            }
        }
    }
    if ($kind === 'mod_wish') {
        $u = trim((string) ($post['mod_url'] ?? ''));
        if ($u !== '') {
            $scheme = strtolower((string) parse_url($u, PHP_URL_SCHEME));
            if (mb_strlen($u) > 255 || !filter_var($u, FILTER_VALIDATE_URL) || !in_array($scheme, ['http', 'https'], true)) {
                $errors[] = 'Der Link muss mit http:// oder https:// anfangen.';
            } else {
                $data['mod_url'] = $u;
            }
        }
    }
    if ($kind === 'note') {
        $ref = (string) ($post['ref'] ?? '');
        if ($ref !== '') {
            [$type, $id] = array_pad(explode(':', $ref, 2), 2, '');
            if (!isset(BOARD_REF_TYPES[$type]) || $id === '' || !board_ref_valid($type, $id)) {
                $errors[] = 'Den Bezug gibt es nicht (mehr).';
            } else {
                $data['ref_type'] = $type;
                $data['ref_id'] = mb_substr($id, 0, 80);
            }
        }
    }
    return [$data, $errors];
}

function board_create(array $user, array $d): int
{
    $now = now();
    q('INSERT INTO board_posts (kind, title, body, ref_type, ref_id, is_pinned, is_sticky, expires_at, mod_url, mod_status, created_by, created_at, updated_at)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
        $d['kind'], $d['title'], $d['body'] !== '' ? $d['body'] : null, $d['ref_type'], $d['ref_id'], $d['is_pinned'], $d['is_sticky'], $d['expires_at'],
        $d['mod_url'], $d['kind'] === 'mod_wish' ? 'open' : null, (int) $user['id'], $now, $now,
    ]);
    return (int) db()->lastInsertId();
}

// ---------------------------------------------------------------- Dashboard

/**
 * Nachrichten fürs Dashboard: angeheftete Ankündigungen, die noch laufen (Anzeigedatum nicht vorbei).
 * Was der Nutzer als gelesen ausgeblendet hat, fehlt, außer der Beitrag ist „nicht ausblendbar“.
 */
function board_dashboard_posts(array $user, int $limit = 6): array
{
    return q('SELECT p.*, u.nickname FROM board_posts p LEFT JOIN users u ON u.id = p.created_by
              WHERE p.kind = \'announcement\' AND p.is_pinned = 1 AND p.hidden_at IS NULL
                AND (p.expires_at IS NULL OR p.expires_at >= CURDATE())
                AND (p.is_sticky = 1 OR NOT EXISTS (SELECT 1 FROM board_dismissals d WHERE d.post_id = p.id AND d.user_id = ?))
              ORDER BY p.is_sticky DESC, p.created_at DESC LIMIT ' . max(1, $limit), [(int) $user['id']])->fetchAll();
}

/** Darf der Nutzer die Nachricht auf seinem Dashboard ausblenden? */
function board_can_dismiss(?array $user, array $p): bool
{
    return $user !== null && $p['kind'] === 'announcement' && (int) $p['is_sticky'] === 0;
}

function board_dismiss(array $user, int $postId): void
{
    q('INSERT IGNORE INTO board_dismissals (user_id, post_id, dismissed_at) VALUES (?, ?, ?)', [(int) $user['id'], $postId, now()]);
}

// ---------------------------------------------------------------- Ungelesen

/** Neue Beiträge und Antworten anderer seit dem letzten Besuch des Boards */
function board_unread_count(array $user): int
{
    static $cache = [];
    $uid = (int) $user['id'];
    if (!isset($cache[$uid])) {
        $last = q('SELECT last_read_at FROM board_reads WHERE user_id = ?', [$uid])->fetchColumn();
        // Wer das Board noch nie geöffnet hat, sieht nur die letzten 14 Tage als neu
        $since = $last !== false ? (string) $last : date('Y-m-d H:i:s', time() - 14 * 86400);
        $cache[$uid] = (int) q('SELECT (SELECT COUNT(*) FROM board_posts WHERE hidden_at IS NULL AND created_at > ? AND (created_by IS NULL OR created_by <> ?))
                                     + (SELECT COUNT(*) FROM board_replies r JOIN board_posts p ON p.id = r.post_id
                                        WHERE r.hidden_at IS NULL AND p.hidden_at IS NULL AND r.created_at > ? AND (r.created_by IS NULL OR r.created_by <> ?))',
            [$since, $uid, $since, $uid])->fetchColumn();
    }
    return $cache[$uid];
}

function board_mark_read(array $user): void
{
    q('INSERT INTO board_reads (user_id, last_read_at) VALUES (?, ?) ON DUPLICATE KEY UPDATE last_read_at = VALUES(last_read_at)', [(int) $user['id'], now()]);
}

/** Zeitpunkt des letzten Besuchs (für „neu“-Markierungen), vor dem Aktualisieren lesen */
function board_last_read(array $user): ?string
{
    $last = q('SELECT last_read_at FROM board_reads WHERE user_id = ?', [(int) $user['id']])->fetchColumn();
    return $last !== false ? (string) $last : null;
}
