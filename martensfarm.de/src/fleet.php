<?php
declare(strict_types=1);

/**
 * Fuhrpark: Gruppen, Kosten je Fahrzeug (aus dem Kassenbuch) und Position auf der Karte.
 */

/** Kategorien aus dem Spiel grob zu Gruppen zusammenfassen */
const FLEET_GROUPS = [
    'Traktoren und Selbstfahrer' => ['TRACTORSS', 'TRACTORSM', 'TRACTORSL', 'HARVESTERS', 'FORAGEHARVESTERS', 'FRONTLOADERVEHICLES', 'WHEELLOADERVEHICLES', 'TELELOADERVEHICLES', 'SKIDSTEERVEHICLES', 'CARS', 'TRUCKS'],
    'Ernte und Transport'        => ['CUTTERS', 'CUTTERTRAILERS', 'TRAILERS', 'AUGERWAGONS', 'SLURRYTANKS', 'LOWLOADERS', 'MISC'],
    'Boden, Saat, Pflege'        => ['CULTIVATORS', 'PLOWS', 'DISCHARROWS', 'POWERHARROWS', 'SUBSOILERS', 'SEEDERS', 'PLANTERS', 'FERTILIZERSPREADERS', 'SPRAYERS', 'ROLLERS', 'WEEDERS', 'MANURESPREADERS', 'LIMESPREADERS'],
    'Grünland und Futter'        => ['MOWERS', 'TEDDERS', 'WINDROWERS', 'LOADERWAGONS', 'BALERSROUND', 'BALERSSQUARE', 'BALEWRAPPERS', 'BALELOADERS', 'FORAGEMIXERS', 'SILOCOMPACTION', 'LEVELER'],
];
const FLEET_OTHER = 'Sonstiges';

const PROPERTY_STATES = ['OWNED' => 'Eigentum', 'LEASED' => 'Geleast', 'MISSION' => 'Auftrag'];

function fleet_group(?string $category): string
{
    foreach (FLEET_GROUPS as $g => $cats) {
        if (in_array($category, $cats, true)) {
            return $g;
        }
    }
    return FLEET_OTHER;
}

function property_label(?string $state): string
{
    return PROPERTY_STATES[$state] ?? (string) $state;
}

function vehicle_get(string $uniqueId): ?array
{
    $v = q('SELECT * FROM vehicles WHERE unique_id = ? AND savegame_id <=> ?', [$uniqueId, current_savegame_id()])->fetch();
    return $v ?: null;
}

/**
 * Gebuchte Beträge je Fahrzeug (gültige Buchungen mit Bezug auf das Fahrzeug): unique_id => ['income', 'expense', 'n'].
 * Nur Höfe, deren Finanzen der Nutzer sehen darf.
 */
function vehicle_costs(?array $user, int $savegameId, array $uniqueIds): array
{
    $farmIds = finance_farm_ids($user);
    if (!$uniqueIds || !$farmIds) {
        return [];
    }
    $in = implode(',', array_fill(0, count($uniqueIds), '?'));
    $farmIn = implode(',', array_map('intval', $farmIds));
    $out = [];
    foreach (q("SELECT ref_id, COALESCE(SUM(GREATEST(amount, 0)), 0) AS income, COALESCE(SUM(LEAST(amount, 0)), 0) AS expense, COUNT(*) AS n
                FROM bookings WHERE savegame_id = ? AND ref_type = 'vehicle' AND cancelled_at IS NULL AND farm_id IN ({$farmIn}) AND ref_id IN ({$in})
                GROUP BY ref_id", array_merge([$savegameId], array_values($uniqueIds)))->fetchAll() as $r) {
        $out[$r['ref_id']] = ['income' => (int) $r['income'], 'expense' => (int) $r['expense'], 'n' => (int) $r['n']];
    }
    return $out;
}

/** Fahrzeuge für die Auswahl beim Buchen: unique_id => „Name (Hof)“, nur die genannten Höfe */
function vehicle_options(array $farmIds): array
{
    if (!$farmIds) {
        return [];
    }
    $out = [];
    $in = implode(',', array_map('intval', $farmIds));
    foreach (q("SELECT unique_id, name, farm_id FROM vehicles WHERE savegame_id <=> ? AND removed_at IS NULL AND farm_id IN ({$in}) ORDER BY farm_id, name",
        [current_savegame_id()])->fetchAll() as $v) {
        $out[$v['unique_id']] = $v['name'] . (count($farmIds) > 1 ? ' (' . farm_name((int) $v['farm_id']) . ')' : '');
    }
    return $out;
}

// ---------------------------------------------------------------- Karte

/** Kantenlänge der Karte in Metern (aus dem Stats-Feed, Standard 2048) */
function map_size_m(): int
{
    $s = (int) setting_get('map_size_m', '0');
    return $s >= 256 && $s <= 65536 ? $s : 2048;
}

/**
 * Weltkoordinate (x, z; Mitte der Karte = 0) als Punkt im Kartenbild mit viewBox 0..1000.
 * null, wenn die Position fehlt oder außerhalb liegt.
 */
function map_point(?float $x, ?float $z): ?array
{
    if ($x === null || $z === null) {
        return null;
    }
    $size = map_size_m();
    $px = ($x + $size / 2) / $size * 1000;
    $py = ($z + $size / 2) / $size * 1000;
    if ($px < 0 || $px > 1000 || $py < 0 || $py > 1000) {
        return null;
    }
    return [round($px, 1), round($py, 1)];
}
