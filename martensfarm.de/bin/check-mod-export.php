<?php
declare(strict_types=1);

/**
 * Test für die Export-Mod (nur Kommandozeile):  keyhelp-php85 bin/check-mod-export.php [-v]
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
        $mf = parse_mf_export($career->martensFarm);
        echo "[ OK ] Block gefunden: Schema {$mf['schema']}, Mod {$mf['mod_version']}, geschrieben {$mf['written_at']}\n";
        if ($mf['game']) {
            echo "       Map {$mf['game']['map_title']} ({$mf['game']['map_id']}), Savegame {$mf['game']['savegame_name']}\n";
        }
        if ($mf['environment']) {
            $env = $mf['environment'];
            $period = $env['period'] >= 1 && $env['period'] <= 12 ? period_name(PERIOD_ORDER[$env['period'] - 1]) : '?';
            echo "       Kalender: {$period}, Tag {$env['day_in_period']} von {$env['days_per_period']} (Spieltag {$env['day']})\n";
        }
        if ($mf['missing']) {
            echo '[INFO] Nicht geliefert: ' . implode(', ', $mf['missing']) . "\n";
        }
        foreach ($mf['farms'] as $f) {
            printf("       Hof %-3d %-24s Geld %14s  Kredit %12s\n", $f['farm_id'], $f['name'], fmt_num($f['money']), fmt_num($f['loan']));
        }
        $byFarm = [];
        foreach ($mf['husbandries'] as $h) {
            $byFarm[$h['farm_id']][] = $h;
        }
        ksort($byFarm);
        foreach ($byFarm as $farmId => $pens) {
            printf("       Ställe Hof %d: %d Ställe, %d Tiere\n", $farmId, count($pens), array_sum(array_column($pens, 'animals')));
        }
        $verbose = in_array('-v', $argv, true);
        foreach ($mf['husbandries'] as $h) {
            if (!$verbose && $h['animals'] === 0) {
                continue;
            }
            $water = (int) $h['water_auto'] === 1 ? 'auto' : (isset($h['storage']['WATER']) ? fmt_num($h['storage']['WATER']['level']) : '-');
            $products = [];
            foreach ($h['storage'] as $t => $f) {
                if (!in_array($t, PEN_INPUTS, true) && $f['level'] > 0.5) {
                    $products[] = $t . ' ' . fmt_num($f['level']);
                }
            }
            printf("       Hof %-3d %-30s %-8s %4d/%-4s Prod %5s  Futter %10s (%s/Tag)  Wasser %s  Stroh %s  %s\n",
                $h['farm_id'], mb_substr($h['name'], 0, 30), $h['animal_type'], $h['animals'], (string) ($h['max_animals'] ?? '-'),
                $h['productivity'] !== null ? fmt_num($h['productivity'] * 100) . '%' : '-',
                fmt_num($h['food_total']), $h['food_per_day'] !== null ? fmt_num($h['food_per_day']) : '?',
                $water, isset($h['storage']['STRAW']) ? fmt_num($h['storage']['STRAW']['level']) : '-', implode(', ', $products));
        }
        echo '       Lager: ' . count($mf['storages']) . "\n";
        foreach ($mf['storages'] as $s) {
            $fills = [];
            foreach ($s['fills'] as $t => $f) {
                $fills[] = $t . ' ' . fmt_num($f['level']);
            }
            if ($verbose || $fills) {
                printf("       Hof %-3d %-10s %-30s %s\n", $s['farm_id'], $s['kind'], mb_substr($s['name'], 0, 30), implode(', ', array_slice($fills, 0, 6)));
            }
        }
        // Ab Schema 3: Geldbewegungen je Hof, Spieltag und Art
        if ($mf['finances'] === null) {
            echo "[INFO] Keine Finanzen im Block (erst ab Mod 0.3).\n";
        } else {
            $fin = $mf['finances'];
            echo '       Finanzen: Sitzung ' . $fin['session'] . ', ' . count($fin['days']) . " Hof-Tage\n";
            foreach ($fin['days'] as $d) {
                $parts = [];
                foreach ($d['stats'] as $stat => $v) {
                    $extra = array_filter([$v['type_id'] ?? null, $v['title'] ?? null, isset($v['text']) ? '„' . $v['text'] . '“' : null]);
                    $parts[] = $stat . ' ' . fmt_num($v['amount']) . ' (' . $v['count'] . 'x' . ($extra ? ', ' . implode(' ', $extra) : '') . ')';
                }
                printf("       Hof %-3d Tag %-4d %s\n", $d['farm_id'], $d['day'], implode(', ', $parts));
            }
        }
        echo $verbose ? '' : "       (mit -v auch leere Ställe und Lager)\n";
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
