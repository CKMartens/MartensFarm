-- MartensFarm Hof-Management – Datenbankschema Phase 1
-- MariaDB 10.6+ / MySQL 8+, Zeichensatz utf8mb4

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS farms (
    farm_id      TINYINT UNSIGNED NOT NULL PRIMARY KEY,   -- farmId aus dem Spiel (1–8)
    name         VARCHAR(100) NOT NULL,
    color        TINYINT UNSIGNED NULL,
    money        BIGINT NULL,
    loan         BIGINT NULL,
    updated_at   DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name           VARCHAR(100) NOT NULL,
    nickname       VARCHAR(50)  NOT NULL,
    email          VARCHAR(190) NOT NULL,
    role           ENUM('admin','hofleiter','mitarbeiter','gast') NOT NULL DEFAULT 'mitarbeiter',
    farm_id        TINYINT UNSIGNED NULL,
    is_active      TINYINT(1) NOT NULL DEFAULT 1,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_login_at  DATETIME NULL,
    UNIQUE KEY uq_users_email (email),
    UNIQUE KEY uq_users_nickname (nickname),
    CONSTRAINT fk_users_farm FOREIGN KEY (farm_id) REFERENCES farms (farm_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Anmelde-Token: Link und 6-stelliger Code, beides nur als SHA-256-Hash gespeichert
CREATE TABLE IF NOT EXISTS login_tokens (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id      INT UNSIGNED NOT NULL,
    token_hash   CHAR(64) NOT NULL,
    code_hash    CHAR(64) NOT NULL,
    code_tries   TINYINT UNSIGNED NOT NULL DEFAULT 0,
    expires_at   DATETIME NOT NULL,
    used_at      DATETIME NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    request_ip   VARCHAR(45) NOT NULL,
    UNIQUE KEY uq_token (token_hash),
    KEY idx_user (user_id),
    CONSTRAINT fk_tokens_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Rate-Limit für Anmelde-Anfragen (Schutz vor Mail-Spam über unser Formular)
CREATE TABLE IF NOT EXISTS login_requests (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    email       VARCHAR(190) NOT NULL,
    request_ip  VARCHAR(45) NOT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_email_time (email, created_at),
    KEY idx_ip_time (request_ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ein Eintrag pro Abruf der Server-Feeds
CREATE TABLE IF NOT EXISTS server_snapshots (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    fetched_at        DATETIME NOT NULL,
    ok                TINYINT(1) NOT NULL,
    error             VARCHAR(255) NULL,
    server_name       VARCHAR(150) NULL,
    map_name          VARCHAR(150) NULL,
    game_version      VARCHAR(30)  NULL,
    day_time_ms       BIGINT NULL,
    players_online    TINYINT UNSIGNED NULL,
    players_capacity  TINYINT UNSIGNED NULL,
    KEY idx_fetched (fetched_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS snapshot_players (
    snapshot_id  INT UNSIGNED NOT NULL,
    name         VARCHAR(100) NOT NULL,
    is_admin     TINYINT(1) NOT NULL DEFAULT 0,
    uptime_min   INT UNSIGNED NULL,
    KEY idx_snapshot (snapshot_id),
    CONSTRAINT fk_players_snapshot FOREIGN KEY (snapshot_id) REFERENCES server_snapshots (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS farm_money_history (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    farm_id     TINYINT UNSIGNED NOT NULL,
    fetched_at  DATETIME NOT NULL,
    money       BIGINT NOT NULL,
    KEY idx_farm_time (farm_id, fetched_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Aktueller Fuhrpark (wird bei jedem Import abgeglichen)
CREATE TABLE IF NOT EXISTS vehicles (
    unique_id       VARCHAR(64) NOT NULL PRIMARY KEY,
    farm_id         TINYINT UNSIGNED NOT NULL,
    name            VARCHAR(150) NOT NULL,
    category        VARCHAR(50) NULL,
    filename        VARCHAR(255) NOT NULL,
    property_state  VARCHAR(20) NULL,
    age_months      INT UNSIGNED NULL,
    operating_hours DECIMAL(10,1) NULL,
    price           INT UNSIGNED NULL,
    damage          DECIMAL(5,4) NULL,
    wear            DECIMAL(5,4) NULL,
    fills           JSON NULL,
    pos_x           DECIMAL(9,2) NULL,
    pos_z           DECIMAL(9,2) NULL,
    is_ai_active    TINYINT(1) NOT NULL DEFAULT 0,
    first_seen_at   DATETIME NOT NULL,
    last_seen_at    DATETIME NOT NULL,
    removed_at      DATETIME NULL,
    KEY idx_farm (farm_id),
    KEY idx_removed (removed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Preisverlauf je Ware und Spielperiode (aus economy.xml)
CREATE TABLE IF NOT EXISTS prices (
    fill_type   VARCHAR(50) NOT NULL,
    period      VARCHAR(20) NOT NULL,
    price       INT NOT NULL,
    updated_at  DATETIME NOT NULL,
    PRIMARY KEY (fill_type, period)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
    name   VARCHAR(50) NOT NULL PRIMARY KEY,
    value  TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
