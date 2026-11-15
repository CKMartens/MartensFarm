-- Update auf Stand 0.8 (Finanzen: Monatsabschluss mit Korrekturbuchung, Buchungen löschen)
-- Kann mehrfach laufen. Ausführen mit:  keyhelp-php85 bin/update-db.php

-- Eigener Buchungssatz für Korrekturen beim Monatsabschluss (je Richtung einer, weil Buchungssätze Einnahme oder Ausgabe sind).
-- Taucht beim normalen Buchen nicht auf, nur der Monatsabschluss bucht darauf.
INSERT IGNORE INTO finance_categories (kind, code, name, sort_order) VALUES
    ('income',  'correction_income',  'Korrektur (Abgleich)', 95),
    ('expense', 'correction_expense', 'Korrektur (Abgleich)', 95);

-- Abgeschlossene Spielmonate je Hof. month_start/month_end = Spieltage des Monats,
-- lock_from/lock_to = Spieltage, deren Buchungen in den Abgleich dieses Monats eingehen (dort ist danach nichts mehr änderbar).
CREATE TABLE IF NOT EXISTS finance_month_closes (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    savegame_id  INT UNSIGNED NOT NULL,
    farm_id      TINYINT UNSIGNED NOT NULL,
    month_start  INT NOT NULL,
    month_end    INT NOT NULL,
    period       VARCHAR(20) NOT NULL,
    lock_from    INT NOT NULL,
    lock_to      INT NOT NULL,
    open_amount  BIGINT NOT NULL DEFAULT 0,
    booking_id   INT UNSIGNED NULL,
    closed_by    INT UNSIGNED NULL,
    closed_at    DATETIME NOT NULL,
    UNIQUE KEY uq_month_close (savegame_id, farm_id, month_start),
    KEY idx_month_lock (savegame_id, farm_id, lock_from, lock_to)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Löschprotokoll: Was gelöscht wurde, bleibt hier mit Urheber, Löschendem und Zeitpunkt stehen
CREATE TABLE IF NOT EXISTS booking_deletions (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    booking_id   INT UNSIGNED NOT NULL,
    savegame_id  INT UNSIGNED NOT NULL,
    farm_id      TINYINT UNSIGNED NOT NULL,
    category_id  INT UNSIGNED NULL,
    amount       BIGINT NOT NULL,
    game_day     INT NOT NULL,
    text         VARCHAR(255) NOT NULL DEFAULT '',
    source       VARCHAR(20) NOT NULL DEFAULT 'manual',
    was_cancelled TINYINT(1) NOT NULL DEFAULT 0,
    created_by   INT UNSIGNED NULL,
    created_at   DATETIME NOT NULL,
    deleted_by   INT UNSIGNED NULL,
    deleted_at   DATETIME NOT NULL,
    reason       VARCHAR(255) NOT NULL DEFAULT '',
    KEY idx_deletions (savegame_id, farm_id, deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Neues Recht „Monat abschließen“ für den Farmmanager (nur einmal, damit Änderungen des Admins bleiben).
-- „Buchungen löschen“ hat nach dem Update nur der Admin.
INSERT IGNORE INTO role_permissions (role_key, permission)
    SELECT 'hofleiter', 'finance.close' FROM roles
    WHERE role_key = 'hofleiter' AND NOT EXISTS (SELECT 1 FROM settings WHERE name = 'seed_finance_close_permissions');
INSERT IGNORE INTO settings (name, value) VALUES ('seed_finance_close_permissions', '1');
