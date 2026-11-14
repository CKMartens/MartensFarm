# MartensFarm – Projektübergabe (Stand 11.11.2026)

Eigene Web-App für den LS25-Multiplayer-Hof auf **https://martensfarm.de**. Sie holt die Daten automatisch vom Dedicated Server und zeigt sie im Browser. Gebaut als Ersatz für LS-Management (lsmgmt.de), aber mit **automatischen Serverdaten** statt Handeingabe.

---

## Ziel

- Login für Mitspieler mit Rollen, Daten vom Gameserver anzeigen
- Aufgabenliste für die Mitglieder
- Später Rechnungen zwischen Höfen
- Aktuell 2 Spieler (Carsten + Patenkind). Der Server hat 8 Slots, und maximal soll jeder einen eigenen Hof haben können (Höfe 1–8).

---

## Umgebung

| | |
| --- | --- |
| Webserver | Dedizierter Server bei Hetzner, KeyHelp |
| Domain | martensfarm.de. DNS ist an KeyHelp delegiert, SSL über Let's Encrypt |
| App-Pfad | `/home/users/cmartens/www/martensfarm.de` |
| Document Root | `/home/users/cmartens/www/martensfarm.de/public` |
| PHP | 8.5.12, CLI-Aufruf: **`keyhelp-php85`** |
| Datenbank | MariaDB (in KeyHelp angelegt), Schema aus `sql/schema.sql` |
| Mail | `mail()` über das KeyHelp-Postfix, Absender `hof@martensfarm.de` (SPF/DKIM in KeyHelp) |
| Cronjob | alle 5 Minuten `cron/import.php` (läuft, Einträge im 5-Minuten-Takt bestätigt) |
| Gameserver | Nitrado LS25 Dedicated Server, Web-Interface (IP/Port stehen in `config/config.php`). Der Feed-Code steht in `config/config.php` und wird **nicht** weitergegeben |
| Spielstand | „MartensFarm“, Karte Schwesing Bahnhof, LS25 v1.25.1.0, 4 Tage pro Monat |

**Sicherheitsrahmen (immer beachten):** Der Server steht im Hetzner-Netz. Nichts bauen, was dort Störungen oder Abuse-Meldungen auslösen kann. Deshalb gilt:
- Rate-Limits beim Mailversand
- schonende Abrufe beim Gameserver
- kein offenes Registrieren

---

## Architektur

```
Gameserver-Feeds ──(Cron alle 5 Min, nur lesend)──► MariaDB ──► PHP-Web-App (Login + Rollen) ──► Browser
```

- PHP 8.5 ohne Framework, PDO mit Prepared Statements, server-gerendert
- Strenge CSP: kein Inline-JS, kein Inline-Style. Balken als `<progress>`, Kurven als SVG
- Dunkles, grünes Design, angelehnt an LS-Management

### Ordner

```
martensfarm.de/
├── public/            Document Root: index.php (Router), .htaccess, assets/app.css
├── config/            config.php (Passwörter, Server-Code) + config.example.php
├── src/               bootstrap, db, helpers, auth, mailer, gamedata (Import), pages/*
├── views/             Vorlagen: layout, auth_layout, login*, dashboard, fleet, prices, users, error
├── cron/import.php    Abruf vom Gameserver (nur CLI, Sperrdatei gegen Doppelläufe)
├── bin/               check.php (Systemcheck), create-admin.php
└── sql/schema.sql
```

Alle Ordner außer `public/` haben zusätzlich eine `.htaccess` mit `Require all denied`.

### Datenbank (Phase 1)

| Tabelle | Inhalt |
| --- | --- |
| `farms` | Höfe aus dem Spiel |
| `users` | Name, Nickname, E-Mail, Rolle, Hof |
| `login_tokens` | Link- und Code-Hash, Ablauf, Fehlversuche |
| `login_requests` | Rate-Limit |
| `server_snapshots` + `snapshot_players` | Ein Eintrag pro Abruf |
| `farm_money_history` | Höchstens 1 Punkt pro Stunde |
| `vehicles` | Fuhrpark, inkl. `removed_at` für verkaufte Fahrzeuge |
| `prices` | Ware × Periode |
| `settings` | Spielkalender, letzter Import usw. |

---

## Was fertig ist (Phase 1) – live und getestet

- **Login ohne Passwort:** Per Mail kommen ein Link und ein 6-stelliger Code.
  - Beide gelten 15 Minuten und nur einmal und sind nur als SHA-256-Hash gespeichert.
  - Nach 5 falschen Codes ist der Code gesperrt.
  - Der Link führt erst auf eine Bestätigungsseite (POST), damit Mail-Scanner ihn nicht verbrauchen.
  - Die Anmeldeseite antwortet immer gleich, egal ob die Adresse bekannt ist.
  - Rate-Limit: 5 Mails pro Adresse und 20 pro IP und Stunde.
  - Session-Cookie mit `Secure`, `HttpOnly` und `SameSite=Lax`, Abmeldung nach 72 Stunden.
- **Rollen:** admin > hofleiter > mitarbeiter > gast. Nutzer legt nur der Admin an (`/admin/nutzer`), dort lassen sich auch Höfe umbenennen.
- **Dashboard:** Serverstatus, Spieler online, Uhrzeit im Spiel, Kontostand mit 30-Tage-Verlauf, Werkstatt fällig (ab 20 % Schaden, einstellbar), Verkaufstipps.
- **Fuhrpark:** gruppiert nach Kategorie, mit Schaden, Stunden, Alter, Füllständen und zuletzt verkauften Fahrzeugen.
- **Preise:** jetzt, tiefster und höchster Preis, bester und schlechtester Monat. Den Spielmonat stellt ein Hofleiter ein, danach zählt die App selbst weiter (jede Mitternacht im Spiel ein Tag, nach 4 Tagen der nächste Monat).
- **Importer:**
  - Fahrzeugnamen aus dem Stats-Feed, ordnet sie den Fahrzeugen im Savegame über die Position zu.
  - Übernimmt nur Höfe 1–8; Kartenfahrzeuge (farmId 0), NPC-Höfe (z. B. farmId 15) und Paletten werden übersprungen.
  - Hält mindestens 240 Sekunden Abstand zwischen zwei Abrufen.
- **Werkzeuge:** `bin/check.php` prüft PHP, DB, Code, Gameserver (401/403 = Code falsch) und Mail.

Erster echter Import: Server erreichbar, 44 Fahrzeuge, 3.084 Preiswerte, Kontostand 551.017 €.

---

## Wichtige Erkenntnisse

- **Freigegebene Feeds:** Der Nitrado-Server gibt nur `dedicated-server-stats.xml` sowie die Savegame-Dateien `careerSavegame`, `vehicles` und `economy` frei.
  - `farms` und `environment` antworten mit **204 No Content**.
  - Getrennte Kontostände für mehrere Höfe gibt es darüber nicht. Aktuell gibt's nur den Kontostand von Hof 1 aus `careerSavegame/statistics/money`.
  - Den Spielmonat liefert der Server auch nicht, daher der Kalender in der App.
- **Wann sich was ändert:** Die Savegame-Feeds ändern sich nur, wenn der Server speichert. `stats.xml` ist live.
- **Was `stats.xml` noch liefert:** `Farmlands` mit Besitzer (`owner`), Feldern (`Fields isOwned`), Fahrzeugpositionen (x/z) und `mapOverviewFilename` – gut für spätere Karten- und Felderseiten.
- **Einheiten im Savegame:** `operatingTime` steht in Sekunden. `wearable damage` geht von 0 bis 1.
- **PHP 8.5:** `curl_close()` ist veraltet und wird deshalb nicht benutzt.

---

## Offene Punkte / nächste Schritte

1. **Einrichtung abschließen:**
   - Carsten Hof 1 zuordnen und den Hof umbenennen.
   - Spielmonat auf der Preisseite setzen.
   - Patenkind als Hofleiter anlegen.
2. **Phase 2 – Aufgaben-Board (als Nächstes):**
   - Kanban mit den Spalten Backlog, To Do, In Arbeit, Prüfung, Erledigt.
   - Aufgaben Mitgliedern zuweisen, mit Feld oder Fahrzeug verknüpfen, Priorität setzen.
   - Automatisch Werkstatt-Aufgaben anlegen, sobald ein Fahrzeug über der Wartungsschwelle (Default 20 %) liegt.
   - Drag & Drop mit externem JS, CSP-konform.
3. **Phase 2 – Felder:**
   - Eigene Felder aus `stats.xml` (`Fields` und `Farmlands`).
   - Dazu Fruchtfolge und Bodenwerte als manuelle Ergänzung.
4. **Phase 3:**
   - Rechnungen zwischen Höfen. Die App kann nur Buch führen, Geld bewegt sie im Spiel nicht.
   - Karte mit Fahrzeugpositionen.
   - Discord-Webhook.
5. **Mehrere Höfe / Kontostände:** Dafür braucht es einen anderen Datenweg, z. B. einen Mod oder FTP-Zugriff auf `farms.xml`.

---

## Zusammenarbeit (Vorlieben)

- Sprache Deutsch, lockerer Freundeston, technisch genau und detailliert. Carsten ist IT-Profi (System Operator / Managed Services).
- Lieferung als ZIP mit README. Bei kleinen Änderungen nur die geänderten Dateien plus Zielpfad.
- Vor der Auslieferung lokal testen: echte MariaDB und ein nachgebauter Gameserver mit den Feed-Formaten.
- Befehle immer mit `keyhelp-php85` und den echten Pfaden.
- Den Server-Code und andere Geheimnisse nie in Dokus oder Chats schreiben.
