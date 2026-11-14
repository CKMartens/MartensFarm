<?php
declare(strict_types=1);

/**
 * Datenbank aktualisieren (nur Kommandozeile):  keyhelp-php85 bin/update-db.php
 * Führt alle sql/update-*.sql der Reihe nach aus. Die Skripte sind so geschrieben,
 * dass sie mehrfach laufen dürfen (IF NOT EXISTS).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
require dirname(__DIR__) . '/src/bootstrap.php';

$files = glob(APP_ROOT . '/sql/update-*.sql') ?: [];
natsort($files);
if (!$files) {
    echo "Keine Update-Skripte gefunden.\n";
    exit(0);
}

$ok = true;
foreach ($files as $file) {
    echo '== ' . basename($file) . " ==\n";
    // Kommentarzeilen entfernen, dann an ";" am Zeilenende trennen
    $sql = preg_replace('/^\s*--.*$/m', '', (string) file_get_contents($file));
    foreach (preg_split('/;\s*$/m', $sql) as $stmt) {
        $stmt = trim($stmt);
        if ($stmt === '') {
            continue;
        }
        $label = mb_substr(preg_replace('/\s+/', ' ', $stmt), 0, 70);
        try {
            db()->exec($stmt);
            echo "[ OK ] {$label}\n";
        } catch (Throwable $e) {
            echo "[FEHL] {$label}\n       " . $e->getMessage() . "\n";
            $ok = false;
        }
    }
}

echo $ok ? "\nDatenbank ist aktuell.\n" : "\nEs gab Fehler, bitte die [FEHL]-Zeilen prüfen.\n";
exit($ok ? 0 : 1);
