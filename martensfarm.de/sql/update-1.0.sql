-- Update auf Stand 1.0 RC (Lohnaufträge und Aufgaben an KI-Helfer geben)
-- Kann mehrfach laufen. Ausführen mit:  keyhelp-php85 bin/update-db.php

-- Lohnauftrag wird von KI-Helfern im Spiel abgearbeitet (gilt für seine Aufgaben)
ALTER TABLE contracts ADD COLUMN IF NOT EXISTS ai_helper TINYINT(1) NOT NULL DEFAULT 0 AFTER note;

-- Aufgabe arbeiten die KI-Helfer ab statt eines Mitspielers (assignee_id bleibt dann leer)
ALTER TABLE tasks ADD COLUMN IF NOT EXISTS ai_helper TINYINT(1) NOT NULL DEFAULT 0 AFTER assignee_id;
