-- Update auf Stand 0.15 (Anpassungen nach dem Spieleabend am 16.11.)
-- Kann mehrfach laufen. Ausführen mit:  keyhelp-php85 bin/update-db.php

-- Nachrichten auf dem Dashboard: „nicht ausblendbar“ (bleibt stehen, bis abgeheftet oder Anzeigedatum vorbei)
ALTER TABLE board_posts ADD COLUMN IF NOT EXISTS is_sticky TINYINT(1) NOT NULL DEFAULT 0 AFTER is_pinned;

-- Wer welche Dashboard-Nachricht als gelesen ausgeblendet hat
CREATE TABLE IF NOT EXISTS board_dismissals (
    user_id       INT UNSIGNED NOT NULL,
    post_id       INT UNSIGNED NOT NULL,
    dismissed_at  DATETIME NOT NULL,
    PRIMARY KEY (user_id, post_id),
    KEY idx_dismiss_post (post_id),
    CONSTRAINT fk_dismiss_post FOREIGN KEY (post_id) REFERENCES board_posts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Downloads: private Mod (Hinweis vor dem Herunterladen) und Freigabe (per FTP übernommene Mods erst nach Freigabe sichtbar).
-- Was schon hochgeladen ist, bleibt freigegeben.
ALTER TABLE server_mods ADD COLUMN IF NOT EXISTS is_private TINYINT(1) NOT NULL DEFAULT 0 AFTER is_hidden;
ALTER TABLE server_mods ADD COLUMN IF NOT EXISTS is_approved TINYINT(1) NOT NULL DEFAULT 1 AFTER is_private;

-- Archiv für Aufgaben und Lohnaufträge
ALTER TABLE tasks ADD COLUMN IF NOT EXISTS archived_at DATETIME NULL AFTER done_day;
ALTER TABLE tasks ADD COLUMN IF NOT EXISTS archived_by INT UNSIGNED NULL AFTER archived_at;
ALTER TABLE tasks ADD INDEX IF NOT EXISTS idx_task_archived (savegame_id, archived_at);
ALTER TABLE contracts ADD COLUMN IF NOT EXISTS archived_at DATETIME NULL AFTER done_day;
ALTER TABLE contracts ADD COLUMN IF NOT EXISTS archived_by INT UNSIGNED NULL AFTER archived_at;

-- Tier-Schwellen nach Carstens Wunsch (Futter/Wasser/Stroh unter 25 %, Produkte ab 80 %), nur einmal setzen,
-- damit spätere Änderungen unter Einstellungen bleiben. Der Umweg über (SELECT … ) x ist nötig, weil MariaDB sonst
-- nicht in derselben Tabelle nachsehen darf, die gerade geändert wird.
UPDATE settings SET value = '25' WHERE name IN ('food_min_pct', 'water_min_pct', 'straw_min_pct')
    AND (SELECT COUNT(*) FROM (SELECT name FROM settings WHERE name = 'seed_animal_thresholds_015') x) = 0;
UPDATE settings SET value = '80' WHERE name = 'product_full_pct'
    AND (SELECT COUNT(*) FROM (SELECT name FROM settings WHERE name = 'seed_animal_thresholds_015') x) = 0;
INSERT IGNORE INTO settings (name, value) VALUES ('seed_animal_thresholds_015', '1');
