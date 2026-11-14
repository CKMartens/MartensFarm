-- Update auf Stand 0.3 (Userverwaltung: Rollen und Rechte)
-- Kann mehrfach laufen. Ausführen mit:  keyhelp-php85 bin/update-db.php
-- Benötigt MariaDB 10.6+.

-- Rollen sind jetzt Daten: Anzeigename und Beschreibung pflegt der Admin unter „Rollen & Rechte“.
-- is_system = mitgelieferte Rolle, kann umbenannt, aber nicht gelöscht werden.
CREATE TABLE IF NOT EXISTS roles (
    role_key     VARCHAR(30)  NOT NULL PRIMARY KEY,
    name         VARCHAR(50)  NOT NULL,
    description  VARCHAR(255) NOT NULL DEFAULT '',
    sort_order   SMALLINT     NOT NULL DEFAULT 100,
    is_system    TINYINT(1)   NOT NULL DEFAULT 0,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Welche Rolle welches Recht hat. Die Rechte selbst stehen im Code (src/permissions.php).
-- Die Rolle "admin" darf immer alles, für sie gibt es hier keine Zeilen.
CREATE TABLE IF NOT EXISTS role_permissions (
    role_key    VARCHAR(30) NOT NULL,
    permission  VARCHAR(50) NOT NULL,
    PRIMARY KEY (role_key, permission),
    CONSTRAINT fk_roleperm_role FOREIGN KEY (role_key) REFERENCES roles (role_key) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Mitgelieferte Rollen (INSERT IGNORE: umbenannte Rollen bleiben, wie der Admin sie gesetzt hat)
INSERT IGNORE INTO roles (role_key, name, description, sort_order, is_system) VALUES
    ('admin',       'Admin',       'Darf alles, auch Nutzer, Rollen und Einstellungen verwalten.', 10, 1),
    ('hofleiter',   'Farmmanager', 'Organisiert den Betrieb: bucht, verteilt Aufgaben, stellt den Spielmonat ein.', 20, 1),
    ('mitarbeiter', 'Spieler',     'Spielt mit: sieht die Finanzen, bucht und arbeitet Aufgaben ab.', 30, 1),
    ('gast',        'Gast',        'Darf nur schauen.', 40, 1);

-- Startrechte nur beim allerersten Mal setzen, damit spätere Läufe die Matrix des Admins nicht überschreiben
INSERT INTO role_permissions (role_key, permission)
SELECT r.role_key, r.permission FROM (
    SELECT 'hofleiter' AS role_key, 'game.calendar' AS permission
    UNION ALL SELECT 'hofleiter', 'finance.view'
    UNION ALL SELECT 'hofleiter', 'finance.book'
    UNION ALL SELECT 'hofleiter', 'tasks.create'
    UNION ALL SELECT 'hofleiter', 'tasks.assign'
    UNION ALL SELECT 'hofleiter', 'tasks.work'
    UNION ALL SELECT 'mitarbeiter', 'finance.view'
    UNION ALL SELECT 'mitarbeiter', 'finance.book'
    UNION ALL SELECT 'mitarbeiter', 'tasks.create'
    UNION ALL SELECT 'mitarbeiter', 'tasks.work'
) r
WHERE NOT EXISTS (SELECT 1 FROM role_permissions);

-- Rolle ist kein ENUM mehr, sondern verweist auf roles. Neue Rollen = nur Konfiguration.
ALTER TABLE users MODIFY role VARCHAR(30) NOT NULL DEFAULT 'mitarbeiter';
ALTER TABLE users ADD CONSTRAINT fk_users_role FOREIGN KEY IF NOT EXISTS (role) REFERENCES roles (role_key) ON UPDATE CASCADE;

-- Anonymisieren statt löschen: E-Mail darf leer sein, der Zeitpunkt wird festgehalten
ALTER TABLE users MODIFY email VARCHAR(190) NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS anonymized_at DATETIME NULL AFTER last_login_at;
