<?php
declare(strict_types=1);

/** HTML-Ausgabe immer escapen */
function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function url(string $path = ''): string
{
    return rtrim((string) config('app.base_url'), '/') . '/' . ltrim($path, '/');
}

function redirect(string $path): never
{
    header('Location: ' . url($path), true, 303);
    exit;
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

/** IP des Besuchers. Bewusst nur REMOTE_ADDR – Header wie X-Forwarded-For sind fälschbar. */
function client_ip(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function take_flashes(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): void
{
    $sent = (string) ($_POST['_csrf'] ?? '');
    if ($sent === '' || !hash_equals(csrf_token(), $sent)) {
        http_response_code(400);
        render('error', ['title' => 'Ungültige Anfrage', 'message' => 'Das Formular ist abgelaufen. Bitte lade die Seite neu und versuche es nochmal.'], current_user() !== null);
        exit;
    }
}

/** View mit Layout ausgeben */
function render(string $view, array $data = [], bool $withLayout = true): void
{
    extract($data, EXTR_SKIP);
    ob_start();
    require APP_ROOT . '/views/' . $view . '.php';
    $content = ob_get_clean();
    if ($withLayout) {
        require APP_ROOT . '/views/layout.php';
    } else {
        echo $content;
    }
}

function fmt_money(int|float|null $value): string
{
    return $value === null ? '–' : number_format((float) $value, 0, ',', '.') . ' €';
}

function fmt_num(int|float|null $value, int $decimals = 0): string
{
    return $value === null ? '–' : number_format((float) $value, $decimals, ',', '.');
}

function fmt_datetime(?string $value): string
{
    return $value ? date('d.m.Y H:i', strtotime($value)) : '–';
}

/** "vor 3 Min." */
function fmt_ago(?string $value): string
{
    if (!$value) {
        return 'nie';
    }
    $s = time() - strtotime($value);
    return match (true) {
        $s < 60    => 'gerade eben',
        $s < 3600  => 'vor ' . intdiv($s, 60) . ' Min.',
        $s < 86400 => 'vor ' . intdiv($s, 3600) . ' Std.',
        default    => 'vor ' . intdiv($s, 86400) . ' Tagen',
    };
}

/** Spielzeit aus dayTime (Millisekunden seit Mitternacht) */
function fmt_daytime(?int $ms): string
{
    if ($ms === null) {
        return '–';
    }
    $min = intdiv($ms, 60000) % 1440;
    return sprintf('%02d:%02d', intdiv($min, 60), $min % 60);
}

const PERIOD_NAMES = [
    'EARLY_SPRING' => 'März',     'MID_SPRING' => 'April',      'LATE_SPRING' => 'Mai',
    'EARLY_SUMMER' => 'Juni',     'MID_SUMMER' => 'Juli',       'LATE_SUMMER' => 'August',
    'EARLY_AUTUMN' => 'September','MID_AUTUMN' => 'Oktober',    'LATE_AUTUMN' => 'November',
    'EARLY_WINTER' => 'Dezember', 'MID_WINTER' => 'Januar',     'LATE_WINTER' => 'Februar',
];

const FILLTYPE_NAMES = [
    'WHEAT' => 'Weizen', 'BARLEY' => 'Gerste', 'OAT' => 'Hafer', 'CANOLA' => 'Raps',
    'MAIZE' => 'Mais', 'SORGHUM' => 'Hirse', 'SOYBEAN' => 'Soja', 'SUNFLOWER' => 'Sonnenblumen',
    'POTATO' => 'Kartoffeln', 'SUGARBEET' => 'Zuckerrüben', 'MILK' => 'Milch', 'STRAW' => 'Stroh',
    'SILAGE' => 'Silage', 'DRYGRASS_WINDROW' => 'Heu', 'GRASS_WINDROW' => 'Gras', 'WOODCHIPS' => 'Hackschnitzel',
    'MANURE' => 'Mist', 'LIQUIDMANURE' => 'Gülle', 'CHAFF' => 'Häckselgut', 'DIESEL' => 'Diesel',
    'DEF' => 'AdBlue', 'FERTILIZER' => 'Dünger', 'LIQUIDFERTILIZER' => 'Flüssigdünger', 'SEEDS' => 'Saatgut',
    'LIME' => 'Kalk', 'HERBICIDE' => 'Herbizid', 'ELECTRICCHARGE' => 'Strom', 'SILAGE_ADDITIVE' => 'Siliermittel',
    'BALE_NET' => 'Ballennetz', 'BALE_WRAP' => 'Ballenfolie', 'GRASS' => 'Gras', 'EGG' => 'Eier',
    'WOOL' => 'Wolle', 'HONEY' => 'Honig', 'FORAGE' => 'TMR', 'MINERAL_FEED' => 'Mineralfutter',
];

function period_name(string $period): string
{
    return PERIOD_NAMES[$period] ?? $period;
}

function filltype_name(string $type): string
{
    return FILLTYPE_NAMES[$type] ?? ucfirst(strtolower(str_replace('_', ' ', $type)));
}
