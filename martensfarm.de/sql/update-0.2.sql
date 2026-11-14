-- Update auf Stand 0.2 (Export-Mod: Ställe, Lager, Savegames)
-- Kann mehrfach laufen (IF NOT EXISTS). Ausführen mit:  keyhelp-php85 bin/update-db.php
-- Benötigt MariaDB 10.6+.

-- Spielstände (Map + Savegame-Name). Daten der anderen Tabellen hängen an savegame_id.
CREATE TABLE IF NOT EXISTS savegames (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    map_id         VARCHAR(150) NOT NULL,
    map_title      VARCHAR(150) NULL,
    savegame_name  VARCHAR(150) NOT NULL,
    first_seen_at  DATETIME NOT NULL,
    last_seen_at   DATETIME NOT NULL,
    UNIQUE KEY uq_savegame (map_id, savegame_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Kontostände der Höfe beim Wechsel auf ein anderes Savegame
CREATE TABLE IF NOT EXISTS farms_archive (
    savegame_id  INT UNSIGNED NOT NULL,
    farm_id      TINYINT UNSIGNED NOT NULL,
    name         VARCHAR(100) NOT NULL,
    money        BIGINT NULL,
    loan         BIGINT NULL,
    archived_at  DATETIME NOT NULL,
    PRIMARY KEY (savegame_id, farm_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ställe aus der Export-Mod (Stand: letzter Save)
CREATE TABLE IF NOT EXISTS husbandries (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    savegame_id    INT UNSIGNED NOT NULL,
    pen_key        VARCHAR(80) NOT NULL,
    farm_id        TINYINT UNSIGNED NOT NULL,
    name           VARCHAR(150) NOT NULL,
    animal_type    VARCHAR(50) NULL,
    animals        INT UNSIGNED NOT NULL DEFAULT 0,
    max_animals    INT UNSIGNED NULL,
    productivity   DECIMAL(6,4) NULL,
    health         DECIMAL(5,2) NULL,
    food_total     DECIMAL(14,2) NULL,
    food_capacity  DECIMAL(14,2) NULL,
    food_per_day   DECIMAL(12,2) NULL,
    water_auto     TINYINT(1) NULL,
    water_per_day  DECIMAL(12,2) NULL,
    straw_per_day  DECIMAL(12,2) NULL,
    foods          JSON NULL,
    storage        JSON NULL,
    updated_at     DATETIME NOT NULL,
    removed_at     DATETIME NULL,
    UNIQUE KEY uq_pen (savegame_id, pen_key),
    KEY idx_farm (savegame_id, farm_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Silos, Lager und Produktionen aus der Export-Mod
CREATE TABLE IF NOT EXISTS storages (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    savegame_id  INT UNSIGNED NOT NULL,
    storage_key  VARCHAR(80) NOT NULL,
    farm_id      TINYINT UNSIGNED NOT NULL,
    name         VARCHAR(150) NOT NULL,
    kind         VARCHAR(20) NOT NULL,
    fills        JSON NULL,
    updated_at   DATETIME NOT NULL,
    removed_at   DATETIME NULL,
    UNIQUE KEY uq_storage (savegame_id, storage_key),
    KEY idx_farm (savegame_id, farm_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Hofname vom Admin vergeben → Import überschreibt ihn nicht mehr
ALTER TABLE farms ADD COLUMN IF NOT EXISTS name_locked TINYINT(1) NOT NULL DEFAULT 0 AFTER name;

ALTER TABLE vehicles ADD COLUMN IF NOT EXISTS savegame_id INT UNSIGNED NULL AFTER unique_id;
ALTER TABLE vehicles ADD INDEX IF NOT EXISTS idx_savegame (savegame_id);

ALTER TABLE farm_money_history ADD COLUMN IF NOT EXISTS savegame_id INT UNSIGNED NULL AFTER farm_id;
ALTER TABLE farm_money_history ADD INDEX IF NOT EXISTS idx_savegame_farm (savegame_id, farm_id, fetched_at);
