-- Update auf Stand 0.11 (Nachrichtenboard)
-- Kann mehrfach laufen. Ausführen mit:  keyhelp-php85 bin/update-db.php

-- Beiträge: Hinweis (note), Ankündigung (announcement) oder Mod-Wunsch (mod_wish).
-- Gilt über Savegames hinweg. Ausblenden statt löschen, der Urheber bleibt.
CREATE TABLE IF NOT EXISTS board_posts (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    kind         VARCHAR(20) NOT NULL DEFAULT 'note',
    title        VARCHAR(150) NOT NULL,
    body         TEXT NULL,
    ref_type     VARCHAR(20) NULL,                         -- task | contract | vehicle
    ref_id       VARCHAR(80) NULL,
    is_pinned    TINYINT(1) NOT NULL DEFAULT 0,
    expires_at   DATE NULL,                                -- nur Ankündigungen: ab dann nicht mehr oben
    mod_url      VARCHAR(255) NULL,
    mod_status   VARCHAR(20) NULL,                         -- open | accepted | rejected | installed
    mod_name     VARCHAR(100) NULL,                        -- bei „installiert“: Eintrag in der Downloadverwaltung
    created_by   INT UNSIGNED NULL,
    created_at   DATETIME NOT NULL,
    updated_at   DATETIME NOT NULL,
    hidden_at    DATETIME NULL,
    hidden_by    INT UNSIGNED NULL,
    KEY idx_board_list (hidden_at, kind, created_at),
    KEY idx_board_ref (ref_type, ref_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Antworten auf einen Beitrag
CREATE TABLE IF NOT EXISTS board_replies (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    post_id     INT UNSIGNED NOT NULL,
    body        TEXT NOT NULL,
    created_by  INT UNSIGNED NULL,
    created_at  DATETIME NOT NULL,
    hidden_at   DATETIME NULL,
    hidden_by   INT UNSIGNED NULL,
    KEY idx_board_replies (post_id, id),
    CONSTRAINT fk_board_replies_post FOREIGN KEY (post_id) REFERENCES board_posts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Bis wann jemand das Board gelesen hat (für den Ungelesen-Zähler)
CREATE TABLE IF NOT EXISTS board_reads (
    user_id       INT UNSIGNED NOT NULL PRIMARY KEY,
    last_read_at  DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Startrechte nur einmal, damit Änderungen des Admins bleiben:
-- Farmmanager schreibt, kündigt an und moderiert, Spieler schreibt. Gäste lesen nur.
INSERT IGNORE INTO role_permissions (role_key, permission)
    SELECT r.role_key, p.permission FROM (
        SELECT 'hofleiter' AS role_key, 'board.post' AS permission
        UNION ALL SELECT 'hofleiter', 'board.announce'
        UNION ALL SELECT 'hofleiter', 'board.moderate'
        UNION ALL SELECT 'mitarbeiter', 'board.post'
    ) p JOIN roles r ON r.role_key = p.role_key
    WHERE NOT EXISTS (SELECT 1 FROM settings WHERE name = 'seed_board_permissions');
INSERT IGNORE INTO settings (name, value) VALUES ('seed_board_permissions', '1');
