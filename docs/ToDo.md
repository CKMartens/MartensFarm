# ToDo / Roadmap

Stand: 15.11.2026. Basis ist Carstens Ideenliste, ergänzt um die abgestimmten Entscheidungen.

**Rahmen für alles:**
- Server steht im Hetzner-Netz: keine offenen Formulare, Rate-Limits, schonende Abrufe
- Geheimnisse (DB-Passwort, Feed-Code) bleiben in `config/config.php`, nie in der Oberfläche oder im Repo
- Datenquellen: `stats.xml` (live) sowie `careerSavegame`, `vehicles` und `economy` (nur nach dem Speichern). Über die eigene Export-Mod kommen in `careerSavegame` auch alle Höfe, Ställe, Lager und der Kalender mit.

---

## Reihenfolge

| # | Paket | Umfang |
| --- | --- | --- |
| 1 | Grundlagen: Rollen und Rechte, Einstellungen | erledigt (0.3) |
| 2 | Finanzen: Buchungen und Abgleich mit dem Kontostand | erledigt (0.4, Monatsabschluss 0.8) |
| 3 | Aufgaben-Board inkl. automatischer Werkstatt-Aufgaben und Lohnaufträge | erledigt (0.7, Löschen/Aufräumen 0.9) |
| 4 | Fahrzeugverwaltung erweitern | klein |
| 5 | Datenweg erweitern: Export-Mod für Tiere, Lager und Höfe 2–8 | erledigt (0.2) |
| 6 | Downloadverwaltung | erledigt (0.6) |
| 7 | Nachrichtenboard: Mod-Wünsche, Adminbenachrichtigungen, Hinweise zu Aufgaben | mittel |

---

## 1. Userverwaltung ✔

Ist schon da: Unter `/admin/nutzer` kann man anlegen, bearbeiten und de-/aktivieren. Nur der Admin legt Nutzer an, offenes Registrieren gibt es nicht.

- [x] Rollen in der Oberfläche umbenennen, intern bleiben die Schlüssel gleich:
  - `admin` → **Admin**
  - `hofleiter` → **Farmmanager**
  - `mitarbeiter` → **Spieler**
  - `gast` → **Gast** (nur lesen)
- [x] Rechte statt fest verdrahteter Rollenprüfungen: Jede Rolle ist ein Bündel von Rechten (z. B. `tasks.assign`, `finance.book`, `admin.settings`). Neue Funktionen fragen Rechte ab, nicht Rollen.
- [x] **Deaktivieren statt löschen.** Aufgaben, Buchungen und Verläufe behalten ihren Urheber. Wenn wirklich gelöscht werden muss, dann nur anonymisieren (Name/E-Mail entfernen).
- [x] Seite **Rollen & Rechte** unter Verwaltung: Matrix, Rollen umbenennen, eigene Rollen anlegen und löschen
- [x] Hofbezogene Rechte bei den Finanzen: ohne das Recht „Alle Höfe“ nur der eigene Hof

## 2. Finanzen ✔

Ziel: ein einfaches Kassenbuch und ein Hinweis, wenn sich der Kontostand ändert, ohne dass gebucht wurde. Seite `/finanzen`.

- [x] Buchung = Spieltag, Hof, Kategorie, Betrag, Text, optional Verweis auf Fahrzeug oder Farmland (Aufgabe/Lohnauftrag kommt mit Paket 3). Stornieren statt löschen.
- [x] **Kategorien in der Datenbank**, pflegbar unter Verwaltung → Buchungssätze (anlegen, umbenennen, deaktivieren). Startwerte:
  - **Einnahmen:** Lohnaufträge, Verkauf Fahrzeuge/Maschinen, Verkauf Ernteerträge, Verkauf Felder, Sonstiges
  - **Ausgaben:** Fahrzeugwartung und Treibstoff, Kauf Fahrzeuge/Maschinen (neu ergänzt), Landkauf, Pacht, Lohn, Verbrauchsgüter (Dünger, Saatgut usw.), Sonstiges
- [x] **Differenzerkennung** je Spieltag über `farm_money_history` (jetzt ein Punkt pro Save-Änderung, mit Spieltag und Kredit): Änderung − Kredit − Buchungen = „nicht zugeordnet“. Hinweis auf Finanzseite und Dashboard.
  - Der Kontostand ändert sich nur, wenn der Server speichert.
  - Laufende Kosten sind jetzt sichtbar: Die Export-Mod 0.3 zählt jede Geldbewegung im Spiel je Hof, Tag und Art mit. Daraus werden Vorschläge je Buchungssatz, Zuordnung unter Buchungssätze.
- [x] **Buchungsvorschläge** automatisch:
  - Fahrzeug verschwindet (`removed_at`) → Verkauf
  - neues Fahrzeug → Kauf (Listenpreis)
  - Farmland-Besitzer wechselt in `stats.xml` → Landkauf/-verkauf
  - [x] Lohnauftrag erledigt → Einnahme (und Ausgabe beim Auftraggeber, wenn es ein Hof im Spiel ist)
- [ ] Im echten Spiel prüfen: Kommen die Arten so an, wie erwartet (`bin/check-mod-export.php`, Abschnitt Finanzen)? Passt die Summe zur Kontostandsänderung?
- [x] **Korrekturbuchung zum Monatsende** (0.8): Bleibt am Monatsende eine Differenz zum Kontostand, die sich nicht zuordnen lässt (z. B. Kleinbeträge, „Einkauf, ohne Angabe“), wird sie mit einer Korrekturbuchung ausgeglichen. Danach geht der Abgleich für den Monat auf 0.
  - Eigener Buchungssatz **Korrektur (Abgleich)**, beim normalen Buchen nicht auswählbar
  - Der Monat gilt danach als **abgeschlossen**: keine Buchungen, Stornos oder Löschungen mehr für seine Tage. Recht *Monat abschließen* (`finance.close`), wieder öffnen mit *Buchungssätze verwalten* (Korrektur wird storniert)
- [x] **Buchungen löschen** (0.8): zusätzlich zum Stornieren echtes Löschen, eigenes Recht `finance.delete` (nach dem Update nur Admin), mit Rückfrage und optionalem Grund. Im **Löschprotokoll** bleibt stehen, wer wann was gelöscht hat

## 3. Aufgaben ✔

Seite `/aufgaben` (0.7). Aufgaben manuell oder automatisch anlegen und an Mitspieler delegieren.

- [x] Kanban mit den Spalten Backlog, To Do, In Arbeit, Prüfung, Erledigt (plus „verworfen“ statt löschen)
- [x] Zuweisung an Mitglieder, Priorität, Fälligkeit (Spieltag, angezeigt als Datum im Spielkalender), Verknüpfung mit Fahrzeug, Stall oder Feld
- [x] Kommentare und Verlauf je Aufgabe, Kachel „Meine Aufgaben“ auf dem Dashboard
- [x] **Fahrzeugwartung automatisch** ab der Schwelle (Default 20 %). Keine doppelten Aufgaben pro Fahrzeug, schließt sich nach der Reparatur.
- [x] **Tierpflege automatisch** aus den Hinweisen (Futter, Wasser, Stroh, Produkte fast voll), eine pro Stall und Problem
- [x] Drag & Drop mit externem JS (`assets/tasks.js`), CSP-konform, ohne JS über Knöpfe
- [x] Einstellungen: Automatik an/aus, ob Spieler selbst abhaken dürfen oder erst „Prüfung“
- [ ] Im echten Spiel prüfen: Kommen die Auto-Aufgaben zum richtigen Zeitpunkt und schließen sie sich nach dem Save?
- [x] **Aufgaben löschen** (0.9): eigenes Recht `tasks.delete` (nach dem Update nur Admin), mit Rückfrage, Kommentare und Verlauf gehen mit. Nicht bei Aufgaben eines offenen Lohnauftrags
- [x] **Erledigte automatisch aufräumen** (0.9): Aufgaben in „Erledigt“ werden im nächsten Ingame-Monat beim Import gelöscht (abschaltbar unter Einstellungen). Lohnaufträge verlieren nichts: Aufgaben offener Aufträge bleiben, Buchungsvorschläge hängen am Auftrag

### Lohnaufträge ✔

Seite `/lohnauftraege`, eigenes Recht *Lohnaufträge verwalten*.
- [x] Auftraggeber (Hof oder extern), Leistung, Feld, vereinbarter Preis, Fälligkeit, Status (angefragt, angenommen, erledigt, storniert)
- [x] erzeugt eine oder mehrere **Aufgaben**; sind alle erledigt, ist der Auftrag erledigt
- [x] erzeugt nach Abschluss einen **Buchungsvorschlag** (Einnahme, beim Hof-Auftraggeber zusätzlich Ausgabe)
- [ ] später Grundlage für die **Rechnung zwischen Höfen** (Phase 3)

## 4. Fahrzeugverwaltung

Die Anzeige gibt es schon (Fuhrpark mit Schaden, Stunden, Füllständen, Verkaufshistorie).

- [ ] Wünsche konkret sammeln, z. B. Karte mit Positionen, Filter, Kosten pro Fahrzeug (aus Buchungen)

## 5. Datenweg erweitern ✔

FTP bei Nitrado geht nur für den Mod-Ordner und nur bei gestopptem Server, FarmHub scheidet damit aus (und hat eine Non-Commercial-Lizenz).
Gelöst mit der eigenen Mod `FS25_MartensFarmExport` (Ordner `mod/`, GPL-3.0):

- [x] Mod schreibt beim Speichern `<martensFarm>` in `careerSavegame.xml`, abrufbar über den freigegebenen Feed
- [x] Map-unabhängig und roh: alle Höfe, Ställe, Lager mit Besitzer-ID, dazu der Spielkalender
- [x] App: Import, Seiten Tiere und Lager, Tierpflege-Hinweise, Savegame-/Map-Wechsel mit Archiv
- [x] Administration → Einstellungen: welche Höfe zählen, Namen, Schwellen
- [ ] Im echten Spiel prüfen, welche Werte LS25 liefert (Wasser, Stroh, Futter pro Tag, Produktivität). Was fehlt, zeigt `bin/check-mod-export.php` bzw. die Einstellungsseite.

## 6. Downloadverwaltung ✔

Seite `/downloads` (0.6). Welche Mods auf dem Server laufen, kommt automatisch aus dem Stats-Feed. Die Dateien lädt ein Verwalter hoch,
weil Nitrado Mods nur mit Login im Web-Interface herausgibt (der Durchreich-Weg aus 0.5 ist deshalb wieder raus).

- [x] Nur angemeldet und mit Recht *Mods herunterladen*. Dateien liegen **außerhalb des Document Root** (`var/mods/`) und gehen über PHP mit Rechteprüfung raus
- [x] Mitspieler sehen nur Mods mit hochgeladener Datei, also die, die nicht im ModHub sind
- [x] Pro Mod: Version aus der `modDesc.xml`, SHA-256, Größe, Hinweis/Changelog; Warnung, wenn der Server eine neuere Version hat
- [x] Hinweis über der Liste: nur für unsere Runde, nicht weitergeben, Nutzung auf eigene Gefahr
- [x] Größengrenze pro Datei (`downloads.max_mb` und PHP-Upload-Grenze), Download-Zahlen je Mod
- [ ] Bei fremden Mods vorher die Erlaubnis der Autoren einholen, viele verbieten Re-Uploads

## 7. Nachrichtenboard

Neue Seite `/nachrichten`. Ein Ort für alles, was nicht direkt eine Aufgabe oder Buchung ist, damit nichts mehr im Chat untergeht.

- [ ] **Mod-Wünsche:** Spieler schlagen Mods vor (Name, Link zum ModHub oder zur Quelle, Begründung). Status: offen, angenommen, abgelehnt, installiert. Bei „installiert“ Verweis auf den Eintrag in der Downloadverwaltung
- [ ] **Adminbenachrichtigungen:** Admin bzw. Farmmanager veröffentlichen Hinweise an alle (z. B. Server-Neustart, Mod-Update, Savegame-Wechsel). Optional anheften und mit Ablaufdatum
- [ ] **Hinweise zu Aufgaben usw.:** Beiträge mit Verknüpfung zu Aufgabe, Lohnauftrag, Fahrzeug, Stall oder Feld; erscheinen auch in deren Detailansicht
- [ ] Rechte statt Rollen, z. B. `board.post`, `board.announce`, `board.moderate`. Gäste nur lesen
- [ ] Ungelesen-Zähler und Kachel „Neue Nachrichten“ auf dem Dashboard
- [ ] Ausblenden statt löschen, der Urheber bleibt erhalten (wie bei Aufgaben und Buchungen)
- [ ] Schutz wie überall: nur angemeldet, CSRF, Rate-Limit pro Nutzer, Text ohne HTML (Ausgabe escapen)
- [ ] Später: Benachrichtigung per Discord-Webhook (siehe Phase 3)

## Administration

- [x] Einstellungsseite (`/admin/einstellungen`): Höfe, Namen, Wartungsschwelle, Tier-Schwellen, Savegames
- [ ] Weitere Einstellungen bei Bedarf: Importer-Intervall
- [x] Buchungskategorien pflegen (anlegen, umbenennen, deaktivieren) und Zuordnung der Spiel-Statistik
- [ ] Rechte je Rolle anzeigen bzw. anpassen
- Geheimnisse (Feed-Code, DB, FTP) bleiben in `config/config.php`

---

## Später (Phase 3)

- Rechnungen zwischen Höfen (Buchführung, die App bewegt kein Geld im Spiel)
- Karte mit Fahrzeugpositionen
- Discord-Webhook
