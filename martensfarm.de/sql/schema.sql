-- MartensFarm Hof-Management – Datenbankschema (Stand 0.7)
-- Bestehende Installation aktualisieren: bin/update-db.php (führt update-*.sql aus)
-- MariaDB 10.6+ / MySQL 8+, Zeichensatz utf8mb4

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS farms (
    farm_id      TINYINT UNSIGNED NOT NULL PRIMARY KEY,   -- farmId aus dem Spiel (1–8)
    name         VARCHAR(100) NOT NULL,
    name_locked  TINYINT(1) NOT NULL DEFAULT 0,               -- vom Admin benannt, Import überschreibt nicht
    color        TINYINT UNSIGNED NULL,
    money        BIGINT NULL,
    loan         BIGINT NULL,
    updated_at   DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Rollen sind Daten: Anzeigename und Beschreibung pflegt der Admin unter „Rollen & Rechte“.
-- is_system = mitgelieferte Rolle, kann umbenannt, aber nicht gelöscht werden.
CREATE TABLE IF NOT EXISTS roles (
    role_key     VARCHAR(30)  NOT NULL PRIMARY KEY,
    name         VARCHAR(50)  NOT NULL,
    description  VARCHAR(255) NOT NULL DEFAULT '',
    sort_order   SMALLINT     NOT NULL DEFAULT 100,
    is_system    TINYINT(1)   NOT NULL DEFAULT 0,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Welche Rolle welches Recht hat (Rechte-Katalog: src/permissions.php). "admin" darf immer alles.
CREATE TABLE IF NOT EXISTS role_permissions (
    role_key    VARCHAR(30) NOT NULL,
    permission  VARCHAR(50) NOT NULL,
    PRIMARY KEY (role_key, permission),
    CONSTRAINT fk_roleperm_role FOREIGN KEY (role_key) REFERENCES roles (role_key) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO roles (role_key, name, description, sort_order, is_system) VALUES
    ('admin',       'Admin',       'Darf alles, auch Nutzer, Rollen und Einstellungen verwalten.', 10, 1),
    ('hofleiter',   'Farmmanager', 'Organisiert den Betrieb: bucht, verteilt Aufgaben, stellt den Spielmonat ein.', 20, 1),
    ('mitarbeiter', 'Spieler',     'Spielt mit: sieht die Finanzen, bucht und arbeitet Aufgaben ab.', 30, 1),
    ('gast',        'Gast',        'Darf nur schauen.', 40, 1);

INSERT IGNORE INTO role_permissions (role_key, permission) VALUES
    ('hofleiter', 'game.calendar'), ('hofleiter', 'finance.view'), ('hofleiter', 'finance.book'),
    ('hofleiter', 'tasks.create'), ('hofleiter', 'tasks.assign'), ('hofleiter', 'tasks.work'),
    ('mitarbeiter', 'finance.view'), ('mitarbeiter', 'finance.book'), ('mitarbeiter', 'tasks.create'), ('mitarbeiter', 'tasks.work'),
    ('hofleiter', 'downloads.view'), ('mitarbeiter', 'downloads.view');

CREATE TABLE IF NOT EXISTS users (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name           VARCHAR(100) NOT NULL,
    nickname       VARCHAR(50)  NOT NULL,
    email          VARCHAR(190) NULL,                        -- leer = anonymisiert
    role           VARCHAR(30)  NOT NULL DEFAULT 'mitarbeiter',
    farm_id        TINYINT UNSIGNED NULL,
    is_active      TINYINT(1) NOT NULL DEFAULT 1,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_login_at  DATETIME NULL,
    anonymized_at  DATETIME NULL,
    UNIQUE KEY uq_users_email (email),
    UNIQUE KEY uq_users_nickname (nickname),
    CONSTRAINT fk_users_farm FOREIGN KEY (farm_id) REFERENCES farms (farm_id) ON DELETE SET NULL,
    CONSTRAINT fk_users_role FOREIGN KEY (role) REFERENCES roles (role_key) ON UPDATE CASCADE
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
    savegame_id INT UNSIGNED NULL,
    fetched_at  DATETIME NOT NULL,
    game_day    INT NULL,
    money       BIGINT NOT NULL,
    loan        BIGINT NULL,
    KEY idx_farm_time (farm_id, fetched_at),
    KEY idx_savegame_farm (savegame_id, farm_id, fetched_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Aktueller Fuhrpark (wird bei jedem Import abgeglichen)
CREATE TABLE IF NOT EXISTS vehicles (
    unique_id       VARCHAR(64) NOT NULL PRIMARY KEY,
    savegame_id     INT UNSIGNED NULL,
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
    KEY idx_removed (removed_at),
    KEY idx_savegame (savegame_id)
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

-- ---------- Finanzen (0.4) ----------

-- Buchungssätze (Kategorien). code = fester Schlüssel für mitgelieferte Kategorien, eigene haben keinen.
-- Löschen gibt es nicht, nur deaktivieren, damit alte Buchungen ihre Kategorie behalten.
CREATE TABLE IF NOT EXISTS finance_categories (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    kind        ENUM('income','expense') NOT NULL,
    code        VARCHAR(30) NULL,
    name        VARCHAR(100) NOT NULL,
    sort_order  SMALLINT NOT NULL DEFAULT 100,
    is_active   TINYINT(1) NOT NULL DEFAULT 1,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_fincat_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Startwerte (INSERT IGNORE: umbenannte oder deaktivierte Kategorien bleiben, wie der Admin sie gesetzt hat)
INSERT IGNORE INTO finance_categories (kind, code, name, sort_order) VALUES
    ('income',  'contract',      'Lohnaufträge', 10),
    ('income',  'vehicle_sale',  'Verkauf Fahrzeuge/Maschinen', 20),
    ('income',  'harvest_sale',  'Verkauf Ernteerträge', 30),
    ('income',  'field_sale',    'Verkauf Felder', 40),
    ('income',  'income_other',  'Sonstiges', 90),
    ('expense', 'vehicle_costs', 'Fahrzeugwartung und Treibstoff', 10),
    ('expense', 'vehicle_buy',   'Kauf Fahrzeuge/Maschinen', 15),
    ('expense', 'land_buy',      'Landkauf', 20),
    ('expense', 'lease',         'Pacht', 30),
    ('expense', 'wages',         'Lohn', 40),
    ('expense', 'supplies',      'Verbrauchsgüter (Dünger, Saatgut usw.)', 50),
    ('expense', 'expense_other', 'Sonstiges', 90);

-- Welche Finanz-Statistik des Spiels (aus der Export-Mod) zu welchem Buchungssatz gehört.
-- category_id NULL = nach Vorzeichen unter Sonstiges, is_ignored = gar nicht vorschlagen (z. B. Kredit).
CREATE TABLE IF NOT EXISTS finance_stat_map (
    stat         VARCHAR(50) NOT NULL PRIMARY KEY,
    category_id  INT UNSIGNED NULL,
    is_ignored   TINYINT(1) NOT NULL DEFAULT 0,
    CONSTRAINT fk_statmap_cat FOREIGN KEY (category_id) REFERENCES finance_categories (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO finance_stat_map (stat, category_id)
SELECT m.stat, c.id FROM (
    SELECT 'newVehiclesCost' AS stat, 'vehicle_buy' AS code
    UNION ALL SELECT 'soldVehicles', 'vehicle_sale'
    UNION ALL SELECT 'newHandtoolsCost', 'vehicle_buy'
    UNION ALL SELECT 'soldHandtools', 'vehicle_sale'
    UNION ALL SELECT 'fieldPurchase', 'land_buy'
    UNION ALL SELECT 'fieldSelling', 'field_sale'
    UNION ALL SELECT 'vehicleRunningCost', 'vehicle_costs'
    UNION ALL SELECT 'vehicleLeasingCost', 'vehicle_costs'
    UNION ALL SELECT 'purchaseFuel', 'vehicle_costs'
    UNION ALL SELECT 'wagePayment', 'wages'
    UNION ALL SELECT 'purchaseSeeds', 'supplies'
    UNION ALL SELECT 'purchaseFertilizer', 'supplies'
    UNION ALL SELECT 'purchaseSaplings', 'supplies'
    UNION ALL SELECT 'purchaseWater', 'supplies'
    UNION ALL SELECT 'harvestIncome', 'harvest_sale'
    UNION ALL SELECT 'soldMilk', 'harvest_sale'
    UNION ALL SELECT 'soldWool', 'harvest_sale'
    UNION ALL SELECT 'soldBales', 'harvest_sale'
    UNION ALL SELECT 'soldWood', 'harvest_sale'
    UNION ALL SELECT 'soldProducts', 'harvest_sale'
    UNION ALL SELECT 'incomeBga', 'harvest_sale'
    UNION ALL SELECT 'missionIncome', 'contract'
) m JOIN finance_categories c ON c.code = m.code;

-- Kredit aufnehmen/tilgen ist keine Einnahme oder Ausgabe, der Abgleich rechnet den Kredit separat heraus
INSERT IGNORE INTO finance_stat_map (stat, category_id, is_ignored) VALUES ('loan', NULL, 1);

-- Kassenbuch. amount mit Vorzeichen: Einnahme positiv, Ausgabe negativ. Stornieren statt löschen.
CREATE TABLE IF NOT EXISTS bookings (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    savegame_id    INT UNSIGNED NOT NULL,
    farm_id        TINYINT UNSIGNED NOT NULL,
    category_id    INT UNSIGNED NOT NULL,
    amount         BIGINT NOT NULL,
    game_day       INT NOT NULL,
    text           VARCHAR(255) NOT NULL DEFAULT '',
    source         VARCHAR(20) NOT NULL DEFAULT 'manual',   -- manual | game | suggestion
    ref_type       VARCHAR(20) NULL,                         -- vehicle | farmland | später task, contract
    ref_id         VARCHAR(64) NULL,
    suggestion_id  INT UNSIGNED NULL,
    created_by     INT UNSIGNED NULL,
    created_at     DATETIME NOT NULL,
    cancelled_at   DATETIME NULL,
    cancelled_by   INT UNSIGNED NULL,
    KEY idx_farm_day (savegame_id, farm_id, game_day),
    KEY idx_ref (ref_type, ref_id),
    CONSTRAINT fk_booking_cat FOREIGN KEY (category_id) REFERENCES finance_categories (id),
    CONSTRAINT fk_booking_user FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Vorschläge aus Ereignissen im Spiel (Fahrzeug gekauft/verkauft, Farmland gewechselt)
CREATE TABLE IF NOT EXISTS booking_suggestions (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    savegame_id    INT UNSIGNED NOT NULL,
    farm_id        TINYINT UNSIGNED NOT NULL,
    kind           VARCHAR(20) NOT NULL,                     -- vehicle_buy | vehicle_sale | land_buy | land_sale
    ref_type       VARCHAR(20) NOT NULL,
    ref_id         VARCHAR(64) NOT NULL,
    title          VARCHAR(190) NOT NULL,
    amount         BIGINT NULL,                              -- Vorschlag, ohne Vorzeichen; NULL = unbekannt
    game_day       INT NULL,
    detected_at    DATETIME NOT NULL,
    status         ENUM('open','booked','dismissed') NOT NULL DEFAULT 'open',
    booking_id     INT UNSIGNED NULL,
    UNIQUE KEY uq_suggestion (savegame_id, farm_id, kind, ref_id),
    KEY idx_open (savegame_id, farm_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Farmland-Besitzer aus dem Stats-Feed, um Käufe und Verkäufe zu erkennen
CREATE TABLE IF NOT EXISTS farmlands (
    savegame_id    INT UNSIGNED NOT NULL,
    farmland_id    INT UNSIGNED NOT NULL,
    name           VARCHAR(50) NULL,
    owner_farm_id  TINYINT UNSIGNED NOT NULL DEFAULT 0,
    area_ha        DECIMAL(10,3) NULL,
    updated_at     DATETIME NOT NULL,
    PRIMARY KEY (savegame_id, farmland_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Finanz-Statistik aus der Export-Mod (ab 0.3): Summe je Hof, Spieltag und Art.
-- session = Serverstart. Nach einem Neustart zählt die Mod von vorn, die Sitzungen werden addiert.
CREATE TABLE IF NOT EXISTS game_finances (
    savegame_id  INT UNSIGNED NOT NULL,
    session      VARCHAR(20) NOT NULL,
    farm_id      TINYINT UNSIGNED NOT NULL,
    game_day     INT NOT NULL,
    stat         VARCHAR(50) NOT NULL,
    amount       DECIMAL(16,2) NOT NULL,
    tx_count     INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at   DATETIME NOT NULL,
    PRIMARY KEY (savegame_id, session, farm_id, game_day, stat),
    KEY idx_farm_day (savegame_id, farm_id, game_day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
    changed_at    DATETIME NULL,
    -- Hochgeladene ZIP (liegt in var/mods/), Version aus der modDesc.xml
    file_version     VARCHAR(30) NULL,
    file_sha256      CHAR(64) NULL,
    file_size        BIGINT UNSIGNED NULL,
    file_uploaded_at DATETIME NULL,
    file_uploaded_by INT UNSIGNED NULL
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

-- Startrechte für Downloads stehen oben bei role_permissions; Merker, damit update-0.5.sql sie nicht erneut setzt
INSERT IGNORE INTO settings (name, value) VALUES ('seed_downloads_permissions', '1');

-- ---------- Aufgaben und Lohnaufträge (0.7) ----------

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

INSERT IGNORE INTO role_permissions (role_key, permission) VALUES ('hofleiter', 'contracts.manage');
INSERT IGNORE INTO settings (name, value) VALUES ('seed_contracts_permissions', '1');
