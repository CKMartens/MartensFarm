-- Update auf Stand 0.4 (Finanzen: Buchungen, Abgleich, Vorschläge)
-- Kann mehrfach laufen. Ausführen mit:  keyhelp-php85 bin/update-db.php
-- Benötigt MariaDB 10.6+.

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

-- Geldverlauf: ab jetzt ein Punkt pro Änderung (= Server-Save), mit Spieltag und Kredit
ALTER TABLE farm_money_history ADD COLUMN IF NOT EXISTS game_day INT NULL AFTER fetched_at;
ALTER TABLE farm_money_history ADD COLUMN IF NOT EXISTS loan BIGINT NULL AFTER money;
