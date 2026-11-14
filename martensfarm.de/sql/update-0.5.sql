-- Update auf Stand 0.5 (Mod-Downloads)
-- Kann mehrfach laufen. Ausführen mit:  keyhelp-php85 bin/update-db.php

-- Mods, die der Gameserver im Stats-Feed meldet. Wird bei jedem Abruf abgeglichen.
-- on_server = 0: Mod war mal da, ist jetzt nicht mehr auf dem Server (bleibt für die Statistik stehen).
CREATE TABLE IF NOT EXISTS server_mods (
    mod_name      VARCHAR(100) NOT NULL PRIMARY KEY,
    title         VARCHAR(200) NULL,
    version       VARCHAR(30)  NULL,
    author        VARCHAR(200) NULL,
    hash          VARCHAR(64)  NULL,
    on_server     TINYINT(1) NOT NULL DEFAULT 1,
    is_hidden     TINYINT(1) NOT NULL DEFAULT 0,
    note          TEXT NULL,
    first_seen_at DATETIME NOT NULL,
    last_seen_at  DATETIME NOT NULL,
    changed_at    DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Wer wann was geladen hat (nur für die Übersicht, Einträge älter als 180 Tage werden gelöscht)
CREATE TABLE IF NOT EXISTS mod_downloads (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    mod_name      VARCHAR(100) NOT NULL,
    version       VARCHAR(30) NULL,
    user_id       INT UNSIGNED NULL,
    downloaded_at DATETIME NOT NULL,
    KEY idx_mod (mod_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Startrechte für Downloads nur einmal setzen, damit spätere Läufe die Matrix des Admins nicht überschreiben
INSERT IGNORE INTO role_permissions (role_key, permission)
SELECT r.role_key, 'downloads.view' FROM roles r
WHERE r.role_key IN ('hofleiter', 'mitarbeiter')
  AND NOT EXISTS (SELECT 1 FROM settings WHERE name = 'seed_downloads_permissions');

INSERT IGNORE INTO settings (name, value) VALUES ('seed_downloads_permissions', '1');
