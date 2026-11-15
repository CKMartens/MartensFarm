-- Update auf Stand 0.9 (Aufgaben löschen, Erledigte automatisch aufräumen)
-- Kann mehrfach laufen. Ausführen mit:  keyhelp-php85 bin/update-db.php

-- Spieltag, an dem eine Aufgabe erledigt wurde: Daran hängt das Aufräumen im nächsten Spielmonat
ALTER TABLE tasks ADD COLUMN IF NOT EXISTS done_day INT NULL AFTER done_by;
ALTER TABLE tasks ADD INDEX IF NOT EXISTS idx_task_done (savegame_id, status, done_day);

-- Das neue Recht „Aufgaben löschen“ (tasks.delete) hat nach dem Update nur der Admin.
