<?php
declare(strict_types=1);

/**
 * Test für die Export-Mod (nur Kommandozeile):  keyhelp-php85 bin/check-mod-export.php
 *
 * 1. Holt careerSavegame über den freigegebenen Savegame-Feed und zeigt den Block <martensFarm>,
 *    den FS25_MartensFarmExport beim Speichern schreibt.
 * 2. Zeigt nebenbei, welche Bereiche der Stats-Feed liefert (z. B. ob es <Farms> mit Geld gibt).
 *
 * Nur lesend, schreibt nichts in die Datenbank. Gibt keine URLs oder Codes aus.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
require dirname(__DIR__) . '/src/bootstrap.php';

echo "== Savegame-Feed: careerSavegame ==\n";
try {
    $career = gs_savegame('careerSavegame');
    if (!isset($career->martensFarm)) {
        echo "[FEHL] Kein <martensFarm>-Block gefunden.\n";
        echo "       Ist die Mod aktiv und wurde seitdem gespeichert? Im Server-Log nach [MartensFarmExport] suchen.\n";
    } else {
        $mf = $career->martensFarm;
        echo '[ OK ] Block gefunden: Schema ' . $mf['schema'] . ', Mod ' . $mf['modVersion'] . ', geschrieben ' . $mf['writtenAt'] . "\n";
        echo '       Testwert: ' . trim((string) $mf->test) . "\n";
        foreach ($mf->farms->farm ?? [] as $f) {
            printf("       Hof %d %-24s Geld %14s  Kredit %12s\n", (int) $f['id'], (string) $f['name'], (string) $f['money'], (string) $f['loan']);
        }
        foreach ($mf->husbandries->husbandry ?? [] as $h) {
            printf("       Stall Hof %d %-28s %-10s %4d/%-4d Tiere  Futter %s  Wasser %s  Stroh %s\n",
                (int) $h['farmId'], (string) $h['name'], (string) $h['animalType'],
                (int) $h['animals'], (int) $h['maxAnimals'],
                (string) $h['food'], (string) ($h['water'] ?? '-'), (string) ($h['straw'] ?? '-'));
        }
    }
} catch (Throwable $e) {
    echo '[FEHL] careerSavegame: ' . $e->getMessage() . "\n";
}

echo "\n== Stats-Feed: enthaltene Bereiche ==\n";
try {
    $stats = gs_stats();
    $names = [];
    foreach ($stats->children() as $child) {
        $names[$child->getName()] = ($names[$child->getName()] ?? 0) + 1;
    }
    echo '       ' . implode(', ', array_keys($names)) . "\n";
    if (isset($stats->Farms)) {
        echo "[ OK ] <Farms> vorhanden:\n";
        foreach ($stats->Farms->children() as $farm) {
            $attrs = [];
            foreach ($farm->attributes() as $k => $v) {
                $attrs[] = $k . '=' . $v;
            }
            echo '       ' . $farm->getName() . ' ' . implode(' ', $attrs) . "\n";
        }
    } else {
        echo "[INFO] Kein <Farms>-Block im Stats-Feed.\n";
    }
} catch (Throwable $e) {
    echo '[FEHL] Stats-Feed: ' . $e->getMessage() . "\n";
}
