<?php
// Kopiere diese Datei nach config/config.php und trage eure Werte ein.
// config.php liegt AUSSERHALB des Webroots (public/) und darf nie öffentlich erreichbar sein.

return [
    'app' => [
        'name'      => 'MartensFarm',
        'base_url'  => 'https://martensfarm.de',   // ohne Schrägstrich am Ende
        'timezone'  => 'Europe/Berlin',
        'debug'     => false,                      // nur zum Testen auf true
    ],

    'db' => [
        'host'     => 'localhost',
        'port'     => 3306,
        'name'     => 'martensfarm',
        'user'     => 'martensfarm',
        'password' => 'HIER_DB_PASSWORT',
    ],

    'mail' => [
        // Absender muss eine Adresse eurer Domain sein (SPF/DKIM in KeyHelp aktivieren!)
        'from_address' => 'hof@martensfarm.de',
        'from_name'    => 'MartensFarm',
    ],

    'login' => [
        'token_minutes'        => 15,   // so lange gelten Link und Code
        'max_requests_email_h' => 5,    // Anmelde-Mails pro E-Mail-Adresse und Stunde
        'max_requests_ip_h'    => 20,   // Anmelde-Mails pro IP und Stunde
        'session_hours'        => 72,   // so lange bleibt man angemeldet
    ],

    'gameserver' => [
        // Adresse und Code lassen sich auch unter Admin → Einstellungen pflegen. Was dort steht, gilt vorrangig,
        // die Werte hier sind dann nur noch der Fallback.
        // Basis-URL des Web-Interfaces vom Dedicated Server (Nitrado), ohne /feed/...
        'base_url'   => 'http://IP-DES-GAMESERVERS:PORT',
        // Der "code" aus den Feed-Links. GEHEIM halten!
        'code'       => 'HIER_SERVER_CODE',
        // Welche Savegame-Dateien geholt werden. Der Nitrado-Server gibt nur diese drei frei
        // (farms und environment antworten mit "204 No Content").
        'files'      => ['careerSavegame', 'vehicles', 'economy'],
        'timeout_s'  => 15,
        // Mindestabstand zwischen zwei Abrufen (schont den Gameserver)
        'min_interval_s' => 240,
        // Kartenbild aus dem Stats-Feed (Dashboard, Einstellungen); wird 6 Stunden zwischengespeichert
        'map_size'    => 512,
        'map_quality' => 60,
    ],

    'downloads' => [
        // Größte Mod-Datei für Upload/Download (MB). PHP begrenzt zusätzlich über upload_max_filesize
        // und post_max_size (in KeyHelp unter PHP-Einstellungen der Domain anpassen).
        'max_mb' => 1024,
        // Ablage der hochgeladenen ZIPs, außerhalb von public/. Standard: var/mods/
        // 'dir' => '/home/users/BENUTZER/martensfarm-mods',
        // FTP-Eingang für große Mods (umgeht die PHP-Upload-Grenze). Standard: var/mods-inbox/
        // 'inbox_dir' => '/home/users/BENUTZER/martensfarm-mods-inbox',
        // ZIPs mit passendem Namen übernimmt cron/import.php automatisch; false = nur von Hand unter /downloads
        'inbox_auto' => true,
    ],
];
