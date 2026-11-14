# MartensFarm – Hof-Management für LS25 (Phase 1)

Eigene Web-App für euren Multiplayer-Hof auf **martensfarm.de**.
Holt die Daten automatisch vom Dedicated Server (Nitrado) und zeigt sie euch im Browser.

**Phase 1 enthält:**
- Anmeldung ohne Passwort: Link plus 6-stelliger Code per E-Mail
- Nutzerverwaltung mit Hofzuordnung, Rollen (Admin, Farmmanager, Spieler, Gast) und frei einstellbaren Rechten
- Dashboard: Server online, Spieler, Uhrzeit im Spiel, Kontostand mit Verlauf, fällige Reparaturen, Verkaufstipps
- Fuhrpark mit Schaden, Stunden, Füllständen und Verkaufshistorie
- Preise vom Server mit bestem und schlechtestem Monat

---

## Ordnerstruktur

```
martensfarm.de/          (= /home/users/cmartens/www/martensfarm.de)
├── public/          ← NUR dieser Ordner ist von außen erreichbar (Document Root)
│   ├── index.php
│   ├── .htaccess
│   └── assets/app.css
├── config/          ← config.php mit Passwörtern (nicht öffentlich!)
├── src/             ← Programmlogik
├── views/           ← HTML-Vorlagen
├── cron/import.php  ← Abruf vom Gameserver (Cronjob)
├── bin/             ← Werkzeuge: check.php, create-admin.php
└── sql/schema.sql   ← Datenbank-Tabellen
```

---

## Installation in KeyHelp

### 1. Dateien hochladen
Den **Inhalt** des ZIP-Ordners `martensfarm.de/` per SFTP nach
`/home/users/cmartens/www/martensfarm.de/` laden.
Die KeyHelp-Standardseite (`index.html`), die dort evtl. noch liegt, vorher löschen.

### 2. Domain auf `public/` zeigen lassen
KeyHelp → **Domains** → martensfarm.de → **Bearbeiten**:
- **Zielverzeichnis:** `martensfarm.de/public`
  (absolut: `/home/users/cmartens/www/martensfarm.de/public`)
- **PHP-Version:** 8.5
- **SSL/TLS:** Let's Encrypt aktivieren und **HTTPS erzwingen**

Wichtig: Die Domain darf **nicht** auf `martensfarm.de/` selbst zeigen, sonst wären `config/` und `src/` von außen erreichbar.

### 3. Datenbank anlegen
KeyHelp → **Datenbanken** → **Datenbank hinzufügen**: Name, Benutzer, starkes Passwort.
Dann in **phpMyAdmin** die Datenbank auswählen → **Importieren** → `sql/schema.sql`.

### 4. Konfiguration
`config/config.example.php` nach `config/config.php` kopieren und ausfüllen:
- `db.*`: Zugangsdaten aus Schritt 3
- `gameserver.code`: der `code=...` aus euren Server-Links
- `mail.from_address`: z. B. `hof@martensfarm.de`

Rechte: `chmod 640 config/config.php`

### 5. Prüfen und ersten Admin anlegen
Per SSH (KeyHelp → Konto → SSH-Zugang aktivieren):
```bash
cd /home/users/cmartens/www/martensfarm.de
keyhelp-php85 bin/check.php
keyhelp-php85 bin/create-admin.php "Carsten Martens" "[MF]Carsten" deine@mail.de
keyhelp-php85 cron/import.php --force
```

**Ohne SSH:** Den Admin in phpMyAdmin per SQL anlegen:
```sql
INSERT INTO users (name, nickname, email, role) VALUES ('Carsten Martens', '[MF]Carsten', 'deine@mail.de', 'admin');
```

### 6. Cronjob einrichten
KeyHelp → **Cronjobs** → **Cronjob hinzufügen**:
- Typ: **PHP-Skript**, Pfad `www/martensfarm.de/cron/import.php`, PHP-Version 8.5
  (oder als Befehl: `/usr/bin/keyhelp-php85 /home/users/cmartens/www/martensfarm.de/cron/import.php`)
- Zeitplan: alle 5 Minuten (`*/5 * * * *`)

Der Importer hält selbst mindestens 4 Minuten Abstand zwischen zwei Abrufen. Doppelte Läufe verhindert eine Sperrdatei.

### 7. E-Mail sauber zustellbar machen
KeyHelp → **E-Mail** → Adresse `hof@martensfarm.de` anlegen.
Unter **Domains → DNS** prüfen, dass diese Einträge da sind:
- **SPF:** `v=spf1 mx a ~all`
- **DKIM:** in KeyHelp für die Domain aktivieren (legt den DNS-Eintrag an)
- **DMARC:** `_dmarc` TXT `v=DMARC1; p=quarantine; rua=mailto:hof@martensfarm.de`

Ohne SPF und DKIM landen die Anmelde-Mails oft im Spam.

### 8. Erster Login
`https://martensfarm.de` öffnen → E-Mail eingeben → Link in der Mail anklicken oder den Code eingeben.
Dann unter **Nutzer & Höfe**:
- dich selbst Hof 1 zuordnen
- den Hof umbenennen
- Mitspieler anlegen

Auf der **Preisseite** einmal den aktuellen Spielmonat und Tag einstellen. Ab da zählt die Seite selbst mit.

---

## Sicherheit – bewusst so gebaut

- **Geheimnisse außerhalb des Webroots:** Datenbank-Passwort und Server-Code stehen in `config/`, nie im Browser.
- **Anmeldung:**
  - Link-Token und Code werden nur als SHA-256-Hash gespeichert.
  - Beide gelten 15 Minuten und nur einmal.
  - Nach 5 falschen Codes ist der Code gesperrt.
  - Der Link führt erst auf eine Bestätigungsseite, damit Mail-Virenscanner ihn nicht "verbrauchen".
- **Kein Ausspähen von Adressen:** Die Anmeldeseite antwortet immer gleich, egal ob die Adresse bekannt ist.
- **Rate-Limits:** höchstens 5 Anmelde-Mails pro Adresse und 20 pro IP und Stunde. So kann niemand euer Formular zum Spammen missbrauchen. Das ist wichtig fürs Hetzner-Netz.
- **Kein offenes Registrieren:** Nur ein Admin legt Nutzer an.
- **Schutz im Browser:**
  - CSRF-Token in allen Formularen
  - Prepared Statements gegen SQL-Injection
  - konsequentes HTML-Escaping
  - strenge Content-Security-Policy, kein Inline-JavaScript
- **Sitzungen:** Session-Cookie mit `Secure`, `HttpOnly` und `SameSite=Lax`. Neue Session-ID nach dem Login, automatische Abmeldung nach 72 Stunden.
- **Schonung des Gameservers:** Ein Abruf alle 5 Minuten mit 4 Requests, Timeout 15 Sekunden. Abgerufen wird nur lesend.

---

## Was der Server hergibt (und was nicht)

Der Nitrado-Server gibt folgende Feeds frei:

| Feed | Inhalt |
| --- | --- |
| `stats.xml` | Spieler, Uhrzeit im Spiel, Fahrzeugnamen, Felder und Besitz |
| `careerSavegame` | Einstellungen, Kontostand |
| `vehicles` | Fuhrpark mit Schaden, Stunden, Füllständen |
| `economy` | Preise je Monat |

**Nicht** freigegeben sind `farms` und `environment` (Antwort 204). FTP gibt es bei Nitrado nur für den Mod-Ordner und nur bei gestopptem Server.
Deshalb gibt es die eigene Export-Mod `FS25_MartensFarmExport` (Quelle und Anleitung in `mod/`). Sie schreibt beim Speichern einen Block
`<martensFarm>` in `careerSavegame.xml`, und der Import liest ihn über den freigegebenen Feed mit:
- Kontostand und Kredit **aller** Höfe
- Ställe mit Tieren, Produktivität, Futter, Wasser, Stroh und Produkten → Seite **Tiere** mit Hinweisen
- Silos, Lager und Produktionen → Seite **Lager**
- Spielkalender (Monat und Tag), der manuelle Monat auf der Preisseite ist dann nur noch Notbehelf

Ohne die Mod läuft alles wie vorher: Kontostand nur von Hof 1, Monat manuell.

Die Savegame-Feeds ändern sich nur, wenn der Server speichert (Autosave bzw. manuell). Das Dashboard weist darauf hin.

### Savegames und Map-Wechsel

Die App erkennt das Savegame an Map und Savegame-Name. Bei einem Wechsel legt sie automatisch ein neues an, archiviert die Kontostände
des alten und leert die Preise. Fahrzeuge, Ställe, Lager und der Geldverlauf hängen am Savegame und tauchen wieder auf, wenn ihr zurückwechselt.
Welche Höfe zählen, wie sie heißen und ab wann es Hinweise gibt, stellt der Admin unter **Einstellungen** ein.

### Update auf 0.2

1. Dateien hochladen (wie oben, `config/config.php` bleibt).
2. `keyhelp-php85 bin/update-db.php` ausführen. Das Skript legt die neuen Tabellen und Spalten an und darf mehrfach laufen.
3. Die Export-Mod auf den Server laden (siehe `mod/README.md`) und einmal speichern.
4. `keyhelp-php85 bin/check-mod-export.php` zeigt, was ankommt. Danach unter **Einstellungen** die Höfe prüfen.

Die Savegame-Feeds ändern sich nur, wenn der Server speichert (Autosave bzw. manuell).

### Update auf 0.3 (Rollen & Rechte)

1. Dateien hochladen (wie oben, `config/config.php` bleibt).
2. `keyhelp-php85 bin/update-db.php` ausführen. Legt `roles` und `role_permissions` an, macht aus `users.role` einen Verweis auf die Rollen und erlaubt leere E-Mails (für anonymisierte Nutzer).
3. Unter **Verwaltung → Rollen & Rechte** die Häkchen prüfen.

Rollen sind jetzt Bündel von Rechten. Der Code fragt nur Rechte ab (`can($user, 'finance.book')`), der Katalog steht in `src/permissions.php`.
Die Rolle `admin` darf immer alles. Der letzte aktive Admin kann weder gesperrt noch herabgestuft werden.
Nutzer werden gesperrt statt gelöscht. Gesperrte Nutzer lassen sich anonymisieren (Name, E-Mail und Hof weg, Nickname bleibt).

---

## Nächste Phasen

- **Phase 2:** Aufgaben-Board (Kanban), automatische Werkstatt-Aufgaben, Felder
- **Phase 3:** Rechnungen zwischen Höfen, Karte mit Fahrzeugpositionen, Discord-Webhook
