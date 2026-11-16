# MartensFarm – Hof-Management für LS25 (1.0 RC)

Eigene Web-App für euren Multiplayer-Hof auf **martensfarm.de**.
Holt die Daten automatisch vom Dedicated Server (Nitrado) und zeigt sie euch im Browser.

**Phase 1 enthält:**
- Anmeldung ohne Passwort: Link plus 6-stelliger Code per E-Mail
- Nutzerverwaltung mit Hofzuordnung, Rollen (Admin, Farmmanager, Spieler, Gast) und frei einstellbaren Rechten
- Dashboard: Server online, Spieler, Uhrzeit im Spiel, Kontostand mit Verlauf, fällige Reparaturen, Verkaufstipps
- Fuhrpark mit Schaden, Stunden, Füllständen und Verkaufshistorie, Filter, Karte und Detailseite mit Kosten je Fahrzeug (ab 0.10)
- Preise vom Server mit bestem und schlechtestem Monat
- Finanzen: Kassenbuch je Hof, Abgleich mit dem Kontostand, Vorschläge aus dem Spiel (ab 0.4), Monatsabschluss mit Korrekturbuchung und Löschen (ab 0.8)
- Downloads: Mods, die nicht im ModHub sind, für die Mitspieler; Server-Liste automatisch aus dem Stats-Feed (ab 0.6)
- Nachrichtenboard: Ankündigungen, Mod-Wünsche und Hinweise zu Aufgaben, Lohnaufträgen und Fahrzeugen (ab 0.11)
- Aufgaben-Board mit automatischen Werkstatt- und Tierpflege-Aufgaben, Lohnaufträge mit Buchungsvorschlag (ab 0.7), Löschen (ab 0.9) und Archiv (ab 0.15)

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
├── var/cache/       ← Zwischenspeicher (Kartenbild), legt die App selbst an
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
- `gameserver.base_url` und `gameserver.code`: Adresse und der `code=...` aus euren Server-Links
  (lassen sich später auch unter **Einstellungen → Gameserver** pflegen, das gilt dann vorrangig)
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
Lager und Ställe der neuen Map entstehen von selbst beim ersten Save auf der neuen Map (Export-Mod), dafür ist nichts zu tun.
Bis dahin zeigt die App noch den alten Stand.
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

### Update auf 0.4 (Finanzen)

1. Dateien hochladen (wie oben, `config/config.php` bleibt).
2. `keyhelp-php85 bin/update-db.php` ausführen. Legt `finance_categories`, `finance_stat_map`, `bookings`, `booking_suggestions`,
   `farmlands` und `game_finances` an und ergänzt `farm_money_history` um Spieltag und Kredit. Darf mehrfach laufen.
3. Export-Mod 0.3 auf den Server (siehe `mod/README.md`), danach einmal speichern. `bin/check-mod-export.php` zeigt unter „Finanzen“, was ankommt.
4. Unter **Verwaltung → Rollen & Rechte** die neuen Rechte prüfen: **Alle Höfe** (sonst sieht man nur den eigenen Hof aus „Nutzer & Höfe“)
   und **Buchungssätze verwalten**. Beide hat nach dem Update nur der Admin.

So funktioniert's:
- **Kassenbuch** (`/finanzen`): Buchung = Hof, Buchungssatz, Betrag, Spieltag, Text. Falsch gebucht? Stornieren, gelöscht wird nichts.
- **Abgleich je Spieltag:** Änderung des Kontostands − Änderung des Kredits − Buchungen = *nicht zugeordnet*. Der Kontostand kommt nur
  beim Server-Save, pro Änderung gibt es einen Punkt im Geldverlauf. Der erste Save nach dem Update ist der Startpunkt.
- **Vorschläge aus dem Spiel:** Die Mod zählt jede Geldbewegung im Spiel je Hof, Tag und Art mit (Fahrzeugkauf, Treibstoff, Ernteverkauf,
  Helferlohn …). Unter **Verwaltung → Buchungssätze** legt man fest, welche Art in welchem Buchungssatz landet. Ein Klick übernimmt den offenen Rest.
- **Erkannte Ereignisse:** neues Fahrzeug → Kauf (Listenpreis), Fahrzeug weg → Verkauf, Farmland-Besitzer gewechselt (Stats-Feed) → Landkauf/-verkauf.
  Geleaste Fahrzeuge laufen über die Spiel-Vorschläge.
- Buchungen hängen am Savegame. Alte Savegames lassen sich auf der Finanzseite auswählen und nur noch ansehen.

### Gameserver wechseln und Kartenbild

Adresse und Feed-Code des Servers stehen entweder in `config/config.php` oder unter **Einstellungen → Gameserver**.
Was in den Einstellungen steht, gilt vorrangig. Dort gibt es „Verbindung testen“ und „Zurück zu config.php“.
Der Code wird nur maskiert angezeigt und geht nie im Klartext an den Browser.

Das Kartenbild aus dem Stats-Feed liefert die App selbst unter `/karte.jpg` aus (nur angemeldet). Sie speichert es 6 Stunden
in `var/cache/` und lädt es nach einem Server- oder Savegame-Wechsel sofort neu. Größe und Qualität: `gameserver.map_size` und `map_quality`.
Kein Datenbank-Update nötig.

### Update auf 0.5/0.6 (Downloads)

1. Dateien hochladen (wie oben, `config/config.php` bleibt).
2. `keyhelp-php85 bin/update-db.php` ausführen. Legt `server_mods` und `mod_downloads` an und gibt Farmmanager und Spieler
   einmalig das Recht **Mods herunterladen**. Darf mehrfach laufen, spätere Läufe ändern die Rechte nicht mehr.
3. Upload-Grenze prüfen: In KeyHelp bei der Domain `upload_max_filesize` und `post_max_size` hochsetzen (z. B. 512M).
   Die aktuelle Grenze steht auf der Download-Seite. Dazu kommt `downloads.max_mb` in `config.php` (Standard 1024).

So funktioniert's:
- Welche Mods auf dem Server laufen, kommt aus dem `<Mods>`-Block des Stats-Feeds (Name, Titel, Version, Autor).
- Nitrado gibt die Mod-Dateien nur mit Login im Web-Interface heraus. Deshalb lädt ein Verwalter die ZIPs der Mods,
  die **nicht im ModHub** sind, unter `/downloads` → „Weitere Mods auf dem Server“ hoch. Die App prüft, ob die ZIP eine
  `modDesc.xml` im Hauptordner hat, liest die Version aus und speichert SHA-256 und Größe.
- Die Dateien liegen in `var/mods/` (außerhalb von `public/`, nicht im Repo, wird beim Hochladen der App nicht überschrieben)
  und gehen nur angemeldet mit Recht über PHP raus. Anderer Ort: `downloads.dir` in `config.php`.
- Mitspieler sehen nur Mods mit Datei. Spielt man auf dem Server eine neue Version ein, zeigt die Liste
  „Server hat neuere Version“, bis die neue ZIP hochgeladen ist.
- **Rechte:** *Mods herunterladen* für die Seite, *Downloads verwalten* für Upload, Löschen, Ausblenden, Hinweise/Changelog und die Download-Zahlen.

### Update auf 0.7 (Aufgaben und Lohnaufträge)

1. Dateien hochladen (wie oben, `config/config.php` bleibt). Neu ist u. a. `public/assets/tasks.js`.
2. `keyhelp-php85 bin/update-db.php` ausführen. Legt `tasks`, `task_events` und `contracts` an, den Buchungssatz
   **Lohnarbeit (Fremdleistung)** und gibt dem Farmmanager einmalig das neue Recht **Lohnaufträge verwalten**.

So funktioniert's:
- **Board** unter `/aufgaben` mit Backlog, To Do, In Arbeit, Prüfung, Erledigt. Karten per Drag & Drop verschieben
  (ohne JavaScript bzw. am Handy über die Pfeil-Knöpfe). Pro Aufgabe: Hof, Zuweisung, Priorität, Fälligkeit im
  Spielkalender, optional Bezug auf Fahrzeug, Stall oder Feld, dazu Kommentare und Verlauf.
- **Rechte:** *Aufgaben anlegen* (neue Aufgaben, eigene bearbeiten/verwerfen), *Aufgaben bearbeiten* (offene übernehmen,
  eigene bis „Prüfung“ schieben, kommentieren), *Aufgaben verteilen* (zuweisen, alles verschieben, abhaken).
  Gäste sehen das Board nur. Ob Spieler selbst abhaken dürfen, steht unter Einstellungen → Aufgaben.
- **Automatisch** (bei jedem Import, abschaltbar unter Einstellungen): Fahrzeug ab der Werkstatt-Schwelle und jeder
  Tierpflege-Hinweis (Futter, Wasser, Stroh, Produkt fast voll) werden zu einer Aufgabe, pro Fahrzeug bzw. Stall und Problem
  nur eine. Sie schließt sich selbst, sobald der nächste Save zeigt, dass es behoben ist. Hakt jemand sie vorher ab,
  kommt sie erst nach dem nächsten Save wieder, falls das Problem dann noch besteht.
- **Fälligkeit:** Spieltag aus der Export-Mod. Zwischen zwei Saves zählt die App den Tag über die Uhrzeit des Servers weiter.
- **Lohnaufträge** unter `/lohnauftraege`: Auftraggeber (Hof im Spiel oder extern), Leistung, Feld, Preis, Fälligkeit.
  Daraus eine oder mehrere Aufgaben. Sind alle erledigt (oder hakt man den Auftrag ab), entsteht unter Finanzen ein
  Vorschlag *Lohnauftrag erledigt* (Einnahme beim eigenen Hof) und, wenn der Auftraggeber ein Hof im Spiel ist,
  *Lohnarbeit bezahlen* (Ausgabe beim Auftraggeber). Die App führt nur Buch, im Spiel fließt kein Geld.

### Update auf 0.8 (Monatsabschluss, Buchungen löschen)

1. Dateien hochladen (wie oben, `config/config.php` bleibt).
2. `keyhelp-php85 bin/update-db.php` ausführen. Legt `finance_month_closes` und `booking_deletions` an, dazu den Buchungssatz
   **Korrektur (Abgleich)** (je einmal als Einnahme und Ausgabe) und gibt dem Farmmanager einmalig das neue Recht **Monat abschließen**.
   **Buchungen löschen** hat nach dem Update nur der Admin.

So funktioniert's:
- **Monatsabschluss** (Karte auf `/finanzen`): Ist ein Spielmonat vorbei und hat der Server danach gespeichert, zeigt die Liste
  „kann abgeschlossen werden“. *Abschließen* bucht den Rest, der sich nicht zuordnen lässt, auf *Korrektur (Abgleich)*, am letzten
  Abgleich-Tag des Monats. Danach steht der Monat auf 0 und ist gesperrt: keine neuen Buchungen, Stornos oder Löschungen für seine Tage,
  und die Spiel-Vorschläge dafür verschwinden.
- Ein Abgleich-Zeitraum (von Save zu Save) zählt zu dem Monat, in dem sein letzter Tag liegt. Gesperrt werden genau die Tage,
  die in diese Zeiträume fallen.
- **Wieder öffnen** kann, wer *Buchungssätze verwalten* darf. Die Korrektur wird dabei storniert, nicht gelöscht.
- Den Buchungssatz *Korrektur (Abgleich)* gibt es beim normalen Buchen nicht, nur der Monatsabschluss bucht darauf.
- **Löschen** (Recht *Buchungen löschen*): zusätzlich zum Stornieren, z. B. für Fehleingaben. Mit Rückfrage und optionalem Grund.
  Die Buchung verschwindet aus Kassenbuch und Abgleich, im **Löschprotokoll** unten auf der Finanzseite bleibt stehen, was es war,
  wer sie angelegt und wer sie gelöscht hat. Ein verknüpfter Vorschlag wird wieder geöffnet.
- **Stornierte aufräumen** (ab 0.12): Im Kassenbuch löscht „Stornierte löschen“ alle stornierten Buchungen des Hofs auf einmal
  (mit Rückfrage, nicht in abgeschlossenen Monaten). Einzeln geht es weiterhin über „Löschen“ in der Zeile.
- **Verwaltung → Löschprotokoll** (ab 0.12): alle gelöschten Buchungen über alle Höfe, filterbar nach Hof und Savegame, mit wer/wann/was
  und Grund. Sichtbar mit *Buchungen löschen* oder *Buchungssätze verwalten*, jeweils nur für Höfe, deren Finanzen man sehen darf.

### Update auf 0.9 (Aufgaben löschen und aufräumen)

1. Dateien hochladen (wie oben, `config/config.php` bleibt).
2. `keyhelp-php85 bin/update-db.php` ausführen. Ergänzt `tasks` um den Erledigt-Spieltag (`done_day`).
   Das neue Recht **Aufgaben löschen** hat nach dem Update nur der Admin.

So funktioniert's:
- **Löschen:** Unten auf der Aufgabenseite „Aufgabe löschen …“, danach eine Rückfrage. Kommentare und Verlauf gehen mit.
  Aufgaben eines noch offenen Lohnauftrags lassen sich nicht löschen, sonst stimmt dessen Fortschritt nicht mehr.
- **Aufräumen:** Bei jedem Import löscht die App erledigte Aufgaben, die in einem früheren Spielmonat erledigt wurden
  (Spielkalender aus der Export-Mod). Verworfene bleiben, ebenso Aufgaben offener Lohnaufträge. Buchungsvorschläge hängen
  am Lohnauftrag, nicht an der Aufgabe, und bleiben deshalb erhalten. Abschaltbar unter **Einstellungen → Aufgaben**.
- Aufgaben, die schon vor dem Update erledigt waren, bekommen beim ersten Import den aktuellen Spieltag und gehen im nächsten Spielmonat.

### Update auf 0.10 (Fuhrpark)

Nur Dateien hochladen, kein Datenbank-Update nötig (neu sind u. a. `src/fleet.php`, `src/pages/vehicle.php`, `views/vehicle.php`).

So funktioniert's:
- **Filter** auf `/fuhrpark`: Name, Gruppe, Eigentum/geleast/Auftrag, „nur Werkstatt fällig“. Alles per Link teilbar.
- **Karte** (aufklappbar): jedes Fahrzeug als Punkt auf dem Kartenbild, Farbe nach Schaden, Klick öffnet das Fahrzeug.
  Die Position kommt aus `vehicles.xml`, also mit dem Save. Die Kartengröße liest die App aus dem Stats-Feed (`mapSize`, sonst 2048 m).
- **Detailseite** je Fahrzeug (`/fuhrpark/fahrzeug?id=…`): Schaden, Stunden, Füllstände, Position, Aufgaben und alle Buchungen
  mit Bezug auf das Fahrzeug samt Summe. Knöpfe „Kosten buchen“ und „Aufgabe“ belegen Hof und Fahrzeug vor.
- **Kosten je Fahrzeug:** Beim Buchen lässt sich optional ein Fahrzeug wählen. Kauf- und Verkaufsvorschläge hängen schon dran.
  Die Spalte „Gebucht“ im Fuhrpark und die Summe auf der Detailseite sieht nur, wer die Finanzen des Hofs sehen darf.

### Update auf 0.11 (Nachrichtenboard)

1. Dateien hochladen (wie oben, `config/config.php` bleibt).
2. `keyhelp-php85 bin/update-db.php` ausführen. Legt `board_posts`, `board_replies` und `board_reads` an und setzt einmalig
   die neuen Rechte: Farmmanager **Beiträge schreiben**, **Ankündigen** und **Moderieren**, Spieler **Beiträge schreiben**. Gäste lesen nur.

So funktioniert's:
- Seite `/nachrichten` mit drei Arten: **Ankündigung** (an alle, optional angeheftet und mit Ablaufdatum, erscheint dann auch oben
  auf dem Dashboard), **Mod-Wunsch** (Link zum ModHub oder zur Quelle, Status offen/angenommen/abgelehnt/installiert; bei „installiert“
  Verweis auf den Eintrag in den Downloads) und **Hinweis** (optional mit Bezug auf Aufgabe, Lohnauftrag oder Fahrzeug, erscheint dort
  auch auf der Detailseite unter „Hinweise“). Zu jedem Beitrag gibt es Antworten.
- **Ungelesen:** Zähler im Menü und Kachel „Neue Nachrichten“ auf dem Dashboard. Gelesen ist, was man beim letzten Öffnen von `/nachrichten` gesehen hat.
- **Ausblenden statt löschen:** Eigene Beiträge und Antworten blendet man selbst aus, fremde nur mit *Moderieren*. Wer moderiert,
  sieht Ausgeblendetes über „Ausgeblendete zeigen“ und kann es wieder einblenden. Der Urheber bleibt immer erhalten.
- **Schutz:** nur angemeldet, CSRF, nur Text (wird escaped), höchstens 30 Beiträge plus Antworten pro Nutzer und Stunde.
- Die Beiträge gelten über Savegames hinweg.

### Update auf 0.13 (Version anzeigen, Löschen bedienbarer)

Nur Dateien hochladen, kein Datenbank-Update.
- Unten in der Seitenleiste steht jetzt **Version und Build** (z. B. `v0.13 · Build #22`, Build = Nummer des PRs).
  Daran sieht man nach dem Hochladen sofort, ob der Server den neuen Stand hat. Gepflegt wird das in `src/version.php`.
- Stornierte Zeilen im Kassenbuch sind weiter blass, ihre Knöpfe aber nicht mehr. Die Rückfrage vor dem Löschen erscheint
  direkt über dem Kassenbuch statt ganz oben auf der Seite.

### Update auf 0.14 (Mods per FTP bereitstellen)

Kein Datenbank-Update nötig. Große Mods scheitern im Browser an der PHP-Upload-Grenze. Dafür gibt es jetzt einen FTP-Eingang:

1. Dateien hochladen (wie oben, `config/config.php` bleibt).
2. Eingangsordner anlegen: `var/mods-inbox/` neben `var/mods/` (also `…/martensfarm.de/var/mods-inbox/`, Rechte 750).
   Legt die App beim ersten Aufruf von `/downloads` auch selbst an. Anderer Ort: `downloads.inbox_dir` in `config.php`.
   Eingang und Ablage am besten auf demselben Dateisystem, dann wird nur umbenannt statt kopiert.
3. Optional in KeyHelp einen **eigenen FTP-Zugang** anlegen, der nur auf diesen Ordner zeigt. Dann braucht der Mod-Upload
   kein Konto mit Zugriff auf die ganze App. Die Dateien gehören so demselben Benutzer wie PHP, das Verschieben klappt.

So funktioniert's:
- ZIP per FTP (z. B. FileZilla, Binärmodus) in den Eingang legen. Heißt sie genau wie die Mod (`FS25_Irgendwas.zip`,
  Groß/klein egal), übernimmt der Cron (`cron/import.php`, alle 5 Minuten) sie automatisch nach `var/mods/`:
  modDesc.xml prüfen, Version, SHA-256 und Größe merken, Datei aus dem Eingang entfernen. Läuft unabhängig davon, ob der Gameserver erreichbar ist.
- Übernommen wird erst, wenn die Datei 2 Minuten nicht mehr geändert wurde, halbe Uploads bleiben also liegen.
- Alles, was nicht eindeutig passt (z. B. `FS25_Irgendwas (1).zip`, `FS25_Irgendwas_v1.2.zip`), fehlerhafte ZIPs und Dateien
  zu Mods, die noch nicht in der Server-Liste stehen, zeigt `/downloads` → **Per FTP bereitgestellt**. Dort Mod wählen und
  „Übernehmen“ oder „Löschen“ (Recht *Downloads verwalten*).
- Automatik abschalten: `downloads.inbox_auto => false` in `config.php`. Die Grenze `downloads.max_mb` gilt auch für den Eingang.

### Update auf 0.15 (Anpassungen nach dem Spieleabend)

1. Dateien hochladen (wie oben, `config/config.php` bleibt).
2. `keyhelp-php85 bin/update-db.php` ausführen. Ergänzt `board_posts.is_sticky`, `server_mods.is_private`/`is_approved`,
   `tasks`/`contracts.archived_at` und legt `board_dismissals` an. Setzt einmalig die Tier-Schwellen auf 25 % (Futter, Wasser, Stroh)
   und 80 % (Milch, Gülle, Mist). Keine neuen Rechte.

So funktioniert's:
- **Finanzen → Vorschläge aus dem Spiel:** Weicht an einem Spieltag das Gebuchte vom Spiel ab (z. B. Tippfehler bei einzeln
  gebuchten Lohnaufträgen), steht am Ende des Tages **Tag korrigieren**. Das bucht die Differenz auf *Korrektur (Abgleich)*,
  verknüpft mit dem Buchungssatz, und die Zeile verschwindet. Im Kassenbuch als „Tageskorrektur“ markiert und stornierbar.
  Ganz ungebuchte Zeilen bleiben stehen, die gehören normal übernommen.
- **Dashboard-Nachrichten:** Angeheftete Ankündigungen stehen groß über den Kacheln. Jeder kann sie mit „Gelesen, ausblenden“ für
  sich wegklicken (unter `/nachrichten` bleiben sie). Mit dem Haken **nicht ausblendbar** bleiben sie stehen, bis jemand sie abheftet
  oder das Anzeigedatum vorbei ist. Tier-Hinweise erscheinen dort ebenfalls als Nachricht.
- **Downloads:** Was der Cron aus dem FTP-Eingang übernimmt, sehen die Mitspieler erst nach **Freigeben** (Abschnitt „Per FTP
  bereitgestellt → Wartet auf Freigabe“). Von Hand übernommene oder im Browser hochgeladene Dateien sind gleich frei.
  Neuer Haken **private Mod** (beim Hochladen, Übernehmen, Freigeben oder unter „Verwalten“): Vor dem Herunterladen kommt dann ein
  Hinweis, den man abhaken muss.
- **Tiere:** Hinweise (und mit „Tierpflege-Aufgaben“ auch Aufgaben) bei Futter, Wasser oder Stroh unter 25 %, Gesundheit unter 50 %
  und Milch/Gülle/Mist ab 80 %. Futter zählt alle Sorten zusammen (TMR, Heu, Gras …). Ställe ohne Gesundheits-Prüfung
  (z. B. Kälberstall) unter **Einstellungen → Gesundheit: Ställe ausnehmen**.
- **Archiv:** Erledigte/verworfene Aufgaben und erledigte/stornierte Lohnaufträge lassen sich auf ihrer Seite archivieren und
  wieder herausholen. Zum nächsten Spielmonat archiviert der Import sie automatisch (ersetzt das automatische Löschen aus 0.9,
  Schalter unter **Einstellungen → Aufgaben**). Archiv unter `/aufgaben` → „Archiv“ bzw. `/lohnauftraege` → „Archiv“.

### Update auf 1.0 RC (KI-Helfer, Release-Kandidat)

1. Dateien hochladen (wie oben, `config/config.php` bleibt).
2. `keyhelp-php85 bin/update-db.php` ausführen. Ergänzt `contracts.ai_helper` und `tasks.ai_helper`. Keine neuen Rechte.

Damit ist alles für das erste Release drin, jetzt läuft die Testphase. Neu:
- **Lohnauftrag an KI-Helfer:** Beim Anlegen oder Bearbeiten „🤖 Von KI-Helfern im Spiel abarbeiten lassen“ anhaken. Seine Aufgaben
  stehen dann als „🤖 KI-Helfer“ auf dem Board statt „offen“ und zählen auf dem Dashboard nicht als „noch niemand dran“.
  Wer die Helfer startet und am Ende prüft, schiebt die Aufgabe weiter, danach schließt sich der Auftrag wie gewohnt.
- Auch einzelne Aufgaben lassen sich unter „Zugewiesen an“ den KI-Helfern geben. Übernimmt ein Mitspieler, ist der Haken weg.
- Den Helferlohn bucht das Spiel als „Lohnzahlungen“, er erscheint unter Finanzen bei den Vorschlägen (Buchungssatz Lohn).

---

## Nächste Phasen

- **Phase 3:** Rechnungen zwischen Höfen, Karte mit Fahrzeugpositionen, Discord-Webhook
