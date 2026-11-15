<?php
declare(strict_types=1);

/**
 * Rollen und Rechte.
 *
 * Die Rechte stehen hier im Code, weil jede Funktion genau weiß, welches Recht sie prüft.
 * Welche Rolle welche Rechte hat, steht in der Datenbank (roles, role_permissions) und
 * wird unter „Rollen & Rechte“ gepflegt. Neue Rollen sind damit reine Konfiguration.
 *
 * Neue Funktionen fragen immer ein Recht ab, nie eine Rolle:  can($user, 'finance.book')
 */

/** Schlüssel der Rolle, die immer alles darf (lässt sich nicht aussperren) */
const ADMIN_ROLE = 'admin';

/** Rechte-Katalog: Schlüssel => [Gruppe, Bezeichnung, Erklärung] */
const PERMISSIONS = [
    'admin.users'    => ['Verwaltung', 'Nutzer & Höfe verwalten', 'Nutzer anlegen, bearbeiten, sperren, Höfe umbenennen'],
    'admin.roles'    => ['Verwaltung', 'Rollen & Rechte verwalten', 'Wer das darf, kann sich selbst weitere Rechte geben'],
    'admin.settings' => ['Verwaltung', 'Einstellungen ändern', 'Höfe, Schwellen, Savegame und „Jetzt abrufen“'],
    'game.calendar'  => ['Spiel', 'Spielmonat setzen', 'Monat und Tag auf der Preise-Seite einstellen'],
    'finance.view'      => ['Finanzen', 'Finanzen sehen', 'Kassenbuch und Abgleich des eigenen Hofs ansehen'],
    'finance.book'      => ['Finanzen', 'Buchen', 'Einnahmen und Ausgaben erfassen, Vorschläge übernehmen, eigene Buchungen stornieren'],
    'finance.all_farms' => ['Finanzen', 'Alle Höfe', 'Finanzen aller Höfe sehen (und mit „Buchen“ auch buchen), nicht nur den eigenen'],
    'finance.manage'    => ['Finanzen', 'Buchungssätze verwalten', 'Kategorien und Zuordnung der Spiel-Statistik pflegen, fremde Buchungen stornieren, abgeschlossene Monate wieder öffnen'],
    'finance.close'     => ['Finanzen', 'Monat abschließen', 'Offenen Rest eines Spielmonats per Korrekturbuchung ausgleichen und den Monat schließen'],
    'finance.delete'    => ['Finanzen', 'Buchungen löschen', 'Buchungen endgültig löschen, z. B. Fehleingaben (bleibt im Löschprotokoll stehen)'],
    'downloads.view'   => ['Downloads', 'Mods herunterladen', 'Mods des Gameservers über die App laden'],
    'downloads.manage' => ['Downloads', 'Downloads verwalten', 'Mods aus- und einblenden, Hinweise/Changelog pflegen, Download-Zahlen sehen'],
    'tasks.create'   => ['Aufgaben', 'Aufgaben anlegen', 'Neue Aufgaben aufs Board stellen, eigene bearbeiten und verwerfen'],
    'tasks.assign'   => ['Aufgaben', 'Aufgaben verteilen', 'Aufgaben zuweisen, alle bearbeiten und verschieben, Prüfung abhaken'],
    'tasks.work'     => ['Aufgaben', 'Aufgaben bearbeiten', 'Offene Aufgaben übernehmen, eigene verschieben und kommentieren'],
    'contracts.manage' => ['Aufgaben', 'Lohnaufträge verwalten', 'Lohnaufträge anlegen, ändern, abschließen und stornieren'],
];

/** Alle Rollen, sortiert: role_key => Zeile */
function roles_all(bool $refresh = false): array
{
    static $roles = null;
    if ($roles === null || $refresh) {
        $roles = [];
        foreach (q('SELECT * FROM roles ORDER BY sort_order, name')->fetchAll() as $r) {
            $roles[$r['role_key']] = $r;
        }
    }
    return $roles;
}

function role_name(?string $key): string
{
    return roles_all()[$key]['name'] ?? (string) $key;
}

/** Rechte einer Rolle als Liste von Schlüsseln */
function role_permissions(string $role, bool $refresh = false): array
{
    static $cache = null;
    if ($cache === null || $refresh) {
        $cache = [];
        foreach (q('SELECT role_key, permission FROM role_permissions')->fetchAll() as $r) {
            $cache[$r['role_key']][] = $r['permission'];
        }
    }
    if ($role === ADMIN_ROLE) {
        return array_keys(PERMISSIONS);
    }
    return array_values(array_intersect($cache[$role] ?? [], array_keys(PERMISSIONS)));
}

function is_admin(?array $user): bool
{
    return $user !== null && ($user['role'] ?? '') === ADMIN_ROLE;
}

/** Darf der Nutzer das? Unbekannte Rechte sind immer verboten (Tippfehler fallen so auf). */
function can(?array $user, string $permission): bool
{
    if ($user === null || !isset(PERMISSIONS[$permission])) {
        return false;
    }
    return in_array($permission, role_permissions((string) $user['role']), true);
}

/** Mindestens eines der Rechte? (z. B. für den Menüblock „Verwaltung“) */
function can_any(?array $user, array $permissions): bool
{
    foreach ($permissions as $p) {
        if (can($user, $p)) {
            return true;
        }
    }
    return false;
}

function require_permission(string $permission): array
{
    $user = require_login();
    if (!can($user, $permission)) {
        http_response_code(403);
        render('error', ['title' => 'Kein Zugriff', 'message' => 'Dafür fehlt dir die Berechtigung.']);
        exit;
    }
    return $user;
}

/** Anzahl aktiver Admins (der letzte darf weder gesperrt noch herabgestuft werden) */
function active_admin_count(): int
{
    return (int) q('SELECT COUNT(*) FROM users WHERE role = ? AND is_active = 1', [ADMIN_ROLE])->fetchColumn();
}
