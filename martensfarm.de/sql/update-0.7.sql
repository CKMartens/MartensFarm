-- Update auf Stand 0.7 (Aufgaben-Board, automatische Aufgaben, Lohnaufträge)
-- Kann mehrfach laufen. Ausführen mit:  keyhelp-php85 bin/update-db.php

-- Lohnaufträge: farm_id = unser Hof, der die Arbeit macht. Auftraggeber ist ein Hof im Spiel oder extern (nur Name).
CREATE TABLE IF NOT EXISTS contracts (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    savegame_id     INT UNSIGNED NULL,
    farm_id         TINYINT UNSIGNED NOT NULL,
    client_farm_id  TINYINT UNSIGNED NULL,
    client_name     VARCHAR(100) NULL,
    service         VARCHAR(150) NOT NULL,
    farmland_id     INT UNSIGNED NULL,
    price           BIGINT NOT NULL DEFAULT 0,
    due_day         INT NULL,
    note            TEXT NULL,
    status          VARCHAR(20) NOT NULL DEFAULT 'open',     -- open | accepted | done | cancelled
    created_by      INT UNSIGNED NULL,
    created_at      DATETIME NOT NULL,
    updated_at      DATETIME NOT NULL,
    done_at         DATETIME NULL,
    done_day        INT NULL,
    KEY idx_contract_sg (savegame_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Aufgaben. created_by NULL = automatisch angelegt (auto_key sagt, wofür: repair:<Fahrzeug>, pen:<Stall>:<Art>).
CREATE TABLE IF NOT EXISTS tasks (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    savegame_id    INT UNSIGNED NULL,
    farm_id        TINYINT UNSIGNED NULL,
    title          VARCHAR(150) NOT NULL,
    description    TEXT NULL,
    status         VARCHAR(20) NOT NULL DEFAULT 'todo',      -- backlog | todo | doing | review | done | dropped
    priority       TINYINT UNSIGNED NOT NULL DEFAULT 2,      -- 1 niedrig, 2 normal, 3 hoch, 4 dringend
    assignee_id    INT UNSIGNED NULL,
    due_day        INT NULL,                                 -- Spieltag
    ref_type       VARCHAR(20) NULL,                         -- vehicle | farmland | pen
    ref_id         VARCHAR(80) NULL,
    contract_id    INT UNSIGNED NULL,
    auto_key       VARCHAR(120) NULL,
    created_by     INT UNSIGNED NULL,
    created_at     DATETIME NOT NULL,
    updated_at     DATETIME NOT NULL,
    done_at        DATETIME NULL,
    done_by        INT UNSIGNED NULL,
    KEY idx_task_board (savegame_id, status),
    KEY idx_task_auto (savegame_id, auto_key),
    KEY idx_task_assignee (assignee_id, status),
    KEY idx_task_contract (contract_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Verlauf und Kommentare je Aufgabe. user_id NULL = automatisch.
CREATE TABLE IF NOT EXISTS task_events (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    task_id     INT UNSIGNED NOT NULL,
    user_id     INT UNSIGNED NULL,
    kind        VARCHAR(20) NOT NULL,                        -- created | status | assign | edit | comment | auto
    text        VARCHAR(1000) NOT NULL DEFAULT '',
    created_at  DATETIME NOT NULL,
    KEY idx_task_events (task_id, id),
    CONSTRAINT fk_task_events_task FOREIGN KEY (task_id) REFERENCES tasks (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ausgabe-Buchungssatz für den Auftraggeber, wenn ein anderer Hof im Spiel den Lohnauftrag bezahlt
INSERT IGNORE INTO finance_categories (kind, code, name, sort_order) VALUES ('expense', 'contract_cost', 'Lohnarbeit (Fremdleistung)', 45);

-- Neues Recht „Lohnaufträge verwalten“ für den Farmmanager (nur einmal, damit Änderungen des Admins bleiben)
INSERT IGNORE INTO role_permissions (role_key, permission)
    SELECT 'hofleiter', 'contracts.manage' FROM roles
    WHERE role_key = 'hofleiter' AND NOT EXISTS (SELECT 1 FROM settings WHERE name = 'seed_contracts_permissions');
INSERT IGNORE INTO settings (name, value) VALUES ('seed_contracts_permissions', '1');
