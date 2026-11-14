-- Update auf Stand 0.6 (Downloads per Upload: Nitrado gibt Mods nur mit Login heraus)
-- Kann mehrfach laufen. Ausführen mit:  keyhelp-php85 bin/update-db.php

ALTER TABLE server_mods ADD COLUMN IF NOT EXISTS file_version VARCHAR(30) NULL;
ALTER TABLE server_mods ADD COLUMN IF NOT EXISTS file_sha256 CHAR(64) NULL;
ALTER TABLE server_mods ADD COLUMN IF NOT EXISTS file_size BIGINT UNSIGNED NULL;
ALTER TABLE server_mods ADD COLUMN IF NOT EXISTS file_uploaded_at DATETIME NULL;
ALTER TABLE server_mods ADD COLUMN IF NOT EXISTS file_uploaded_by INT UNSIGNED NULL;

-- Die Download-Adresse aus 0.5 wird nicht mehr gebraucht
DELETE FROM settings WHERE name = 'mods_base_url';
