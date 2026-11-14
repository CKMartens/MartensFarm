# MartensFarm

Eigene Web-App für unseren **Landwirtschafts-Simulator 25**-Multiplayer-Hof auf **https://martensfarm.de**.
Sie holt die Daten automatisch vom Dedicated Server (Nitrado) und zeigt sie im Browser – als Ersatz für LS-Management, aber mit **automatischen Serverdaten** statt Handeingabe.

> Status: **Phase 1 live und getestet** (Login, Rollen, Dashboard, Fuhrpark, Preise, Importer).

---

## Inhalt des Repos

```
MartensFarm/
├── martensfarm.de/        Die Web-App (Inhalt = /home/users/cmartens/www/martensfarm.de)
│   ├── public/            Document Root: index.php (Router), .htaccess, assets/
│   ├── config/            config.example.php (config.php wird NICHT eingecheckt)
│   ├── src/               bootstrap, db, helpers, auth, mailer, gamedata, pages/*
│   ├── views/             HTML-Vorlagen
│   ├── cron/import.php    Abruf vom Gameserver (nur CLI)
│   ├── bin/               check.php, create-admin.php
│   ├── sql/schema.sql     Datenbank-Schema
│   └── README.md          Installationsanleitung für KeyHelp
├── docs/
│   └── Projektuebergabe.md   Stand, Architektur, Erkenntnisse, offene Punkte
├── LICENSE                GPL-3.0
└── README.md              diese Datei
```

## Technik

- PHP 8.5 ohne Framework, PDO mit Prepared Statements, server-gerendert
- MariaDB, gehostet unter KeyHelp auf einem Hetzner-Server
- Cronjob alle 5 Minuten holt `stats.xml` + Savegame-Feeds (`careerSavegame`, `vehicles`, `economy`) – nur lesend, mit Mindestabstand von 240 s
- Login ohne Passwort (Link + 6-stelliger Code per Mail), Rollen admin > hofleiter > mitarbeiter > gast
- Strenge CSP: kein Inline-JS, kein Inline-Style

```
Gameserver-Feeds ──(Cron alle 5 Min, nur lesend)──► MariaDB ──► PHP-Web-App ──► Browser
```

## Installation / Deployment

Die komplette Schritt-für-Schritt-Anleitung (Domain auf `public/`, Datenbank, Config, Cronjob, SPF/DKIM) steht in
**[`martensfarm.de/README.md`](martensfarm.de/README.md)**.

Kurzfassung für ein Update per SSH:

```bash
cd /home/users/cmartens/www/martensfarm.de
# geänderte Dateien hochladen (config/config.php bleibt unangetastet!)
keyhelp-php85 bin/check.php
```

## Sicherheit – Regeln fürs Repo

Das Repo ist **öffentlich**. Deshalb:

- **Niemals** `config/config.php`, Passwörter, den Feed-Code des Gameservers oder IP-Adressen committen. `.gitignore` fängt `config.php` ab, trotzdem vor jedem Commit kurz `git diff --staged` prüfen.
- Keine Datenbank-Dumps oder Feed-Mitschnitte mit echten Daten einchecken.
- Keine GIANTS-/LS25-Basis-Assets (Modelle, Texturen, Karten) – die unterliegen der GIANTS-Lizenz.
- Der Webserver steht im Hetzner-Netz: Rate-Limits beim Mailversand, schonende Abrufe beim Gameserver, kein offenes Registrieren.

## Roadmap

- **Phase 2:** Aufgaben-Board (Kanban) mit automatischen Werkstatt-Aufgaben ab 15 % Schaden, Felderseite aus `stats.xml`
- **Phase 3:** Rechnungen zwischen Höfen, Karte mit Fahrzeugpositionen, Discord-Webhook
- **Später:** getrennte Kontostände für mehrere Höfe (braucht anderen Datenweg, z. B. Mod oder FTP auf `farms.xml`)

Details: [`docs/Projektuebergabe.md`](docs/Projektuebergabe.md)

## Lizenz

Der Code steht unter der **GNU General Public License v3.0** – siehe [`LICENSE`](LICENSE).

*Landwirtschafts-Simulator / Farming Simulator ist eine Marke der GIANTS Software GmbH. Dieses Projekt ist ein inoffizielles Fan-Projekt und steht in keiner Verbindung zu GIANTS Software oder Nitrado.*
