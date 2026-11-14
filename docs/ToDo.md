# ToDo / Roadmap

Stand: 14.11.2026. Basis ist Carstens Ideenliste, ergänzt um die abgestimmten Entscheidungen.

**Rahmen für alles:**
- Server steht im Hetzner-Netz: keine offenen Formulare, Rate-Limits, schonende Abrufe
- Geheimnisse (DB-Passwort, Feed-Code) bleiben in `config/config.php`, nie in der Oberfläche oder im Repo
- Datenquellen sind aktuell nur `stats.xml` (live) sowie `careerSavegame`, `vehicles` und `economy` (nur nach dem Speichern). Kontostand gibt es nur für Hof 1.

---

## Reihenfolge

| # | Paket | Umfang |
| --- | --- | --- |
| 1 | Grundlagen: Rollennamen, Einstellungen | klein |
| 2 | Finanzen: Buchungen und Abgleich mit dem Kontostand | mittel |
| 3 | Aufgaben-Board inkl. automatischer Werkstatt-Aufgaben | mittel |
| 4 | Fahrzeugverwaltung erweitern | klein |
| 5 | Datenweg erweitern (FTP oder Mod) für Tiere und Höfe 2–8 | klären |
| 6 | Downloadverwaltung | mittel |

---

## 1. Userverwaltung

Ist schon da: Unter `/admin/nutzer` kann man anlegen, bearbeiten und de-/aktivieren. Nur der Admin legt Nutzer an, offenes Registrieren gibt es nicht.

- [ ] Rollen in der Oberfläche umbenennen, intern bleiben die Schlüssel gleich:
  - `admin` → **Admin**
  - `hofleiter` → **Farmmanager**
  - `mitarbeiter` → **Spieler**
  - `gast` → **Gast** (nur lesen)
- [ ] Rechte statt fest verdrahteter Rollenprüfungen: Jede Rolle ist ein Bündel von Rechten (z. B. `tasks.assign`, `finance.book`, `admin.settings`). Neue Funktionen fragen Rechte ab, nicht Rollen.
- [ ] **Deaktivieren statt löschen.** Aufgaben, Buchungen und Verläufe behalten ihren Urheber. Wenn wirklich gelöscht werden muss, dann nur anonymisieren (Name/E-Mail entfernen).

## 2. Finanzen

Ziel: ein einfaches Kassenbuch und ein Hinweis, wenn sich der Kontostand ändert, ohne dass gebucht wurde.

- [ ] Buchung = Datum/Spieltag, Hof, Kategorie, Betrag, Text, optional Verweis auf Fahrzeug, Feld, Aufgabe oder Lohnauftrag
- [ ] **Kategorien in der Datenbank**, pflegbar vom Admin (siehe Administration). Startwerte:
  - **Einnahmen:** Lohnaufträge, Verkauf Fahrzeuge/Maschinen, Verkauf Ernteerträge, Verkauf Felder, Sonstiges
  - **Ausgaben:** Fahrzeugwartung und Treibstoff, Landkauf, Pacht, Lohn, Verbrauchsgüter (Dünger, Saatgut usw.), Sonstiges
- [ ] **Differenzerkennung** über `farm_money_history`: Die Änderung des Kontostands wird mit der Summe der Buchungen im selben Zeitraum verglichen, der Rest erscheint als „ungeklärt“.
  - Achtung: Der Kontostand ändert sich nur, wenn der Server speichert, und gilt **nur für Hof 1**.
  - Laufende Kosten (Helferlohn, Treibstoff, Wartung) sind nie einzeln sichtbar, dafür gibt es eine Sammelbuchung „Laufende Kosten“.
- [ ] **Buchungsvorschläge** automatisch:
  - Fahrzeug verschwindet (`removed_at`) → Verkauf
  - neues Fahrzeug → Kauf
  - Farmland-Besitzer wechselt in `stats.xml` → Landkauf/-verkauf
  - Lohnauftrag erledigt → Einnahme

## 3. Aufgaben

Aufgaben manuell oder automatisch anlegen und an Mitspieler delegieren.

- [ ] Kanban mit den Spalten Backlog, To Do, In Arbeit, Prüfung, Erledigt
- [ ] Zuweisung an Mitglieder, Priorität, Fälligkeit (Spieltag), Verknüpfung mit Fahrzeug oder Feld
- [ ] **Fahrzeugwartung automatisch**: Die Schwelle ist in `settings` einstellbar, **Default 20 %**. Dashboard und Doku sind von 15 % darauf umzustellen. Es gibt keine doppelten Aufgaben pro Fahrzeug, und die Aufgabe schließt sich, sobald repariert wurde.
- [ ] **Tierpflege**: Vorerst nur manuell bzw. wiederkehrend (z. B. alle 2 Spieltage), weil die freigegebenen Feeds keine Tierdaten liefern. Automatisch geht es erst mit Paket 5.
- [ ] Drag & Drop mit externem JS, CSP-konform

### Lohnaufträge (eigenes Objekt)

Ein Lohnauftrag verbindet drei Dinge und bekommt deshalb ein eigenes Objekt:
- [ ] Auftraggeber (Hof oder extern), Leistung, Feld, vereinbarter Preis, Status
- [ ] erzeugt eine oder mehrere **Aufgaben**
- [ ] erzeugt nach Abschluss eine **Einnahme-Buchung**
- [ ] später Grundlage für die **Rechnung zwischen Höfen** (Phase 3)

## 4. Fahrzeugverwaltung

Die Anzeige gibt es schon (Fuhrpark mit Schaden, Stunden, Füllständen, Verkaufshistorie).

- [ ] Wünsche konkret sammeln, z. B. Karte mit Positionen, Filter, Kosten pro Fahrzeug (aus Buchungen)

## 5. Datenweg erweitern

Für Tiere, Lager und getrennte Kontostände der Höfe 1–8 reichen die freigegebenen Feeds nicht.

- [ ] Klären, ob Nitrado **FTP-Lesezugriff auf den Savegame-Ordner** gibt. Dann könnte der Cron `farms.xml` u. a. direkt lesen, ohne dass ein Spieler einen Mod braucht. Das ist der bevorzugte Weg.
- [ ] Alternative: Mod, der die Daten in `modSettings` exportiert. Kandidat ist [FarmHub](https://github.com/WizardlyPayload/FarmHub), das eine `data.json` schreibt. Wird separat geprüft.
  - Lizenz: FarmHub hat eine eigene Non-Commercial-Lizenz, also keinen Code ins GPL-Repo übernehmen, nur die exportierten Daten lesen.
  - Alternativ ein eigener schlanker Export-Mod.
- [ ] FTP-Abruf schonend: gleicher Mindestabstand wie beim Feed, Zugangsdaten nur in `config.php`.

## 6. Downloadverwaltung

Mods, die nicht im offiziellen GIANTS-ModHub sind, für die Mitspieler zum Download.

- [ ] **Nur eigene oder ausdrücklich freigegebene Mods.** Bei fremden Mods vorher die Erlaubnis der Autoren einholen, viele verbieten Re-Uploads.
- [ ] Pro Mod: Version, Changelog, Prüfsumme (SHA-256), Lizenz-/Quellenhinweis
- [ ] Hinweis vor dem Download, dass es private Mods sind (Nutzung auf eigene Gefahr, nicht weitergeben)
- [ ] Nur für eingeloggte Nutzer. Die Dateien liegen **außerhalb des Document Root** und werden über PHP mit Rechteprüfung ausgeliefert.
- [ ] Größengrenze pro Datei, kein öffentlicher Link

## Administration

- [ ] Einstellungsseite für Nicht-Geheimes aus der `settings`-Tabelle: Karte, Spielkalender (Tage pro Monat), Wartungsschwelle, Importer-Intervall
- [ ] Buchungskategorien pflegen (anlegen, umbenennen, deaktivieren)
- [ ] Rechte je Rolle anzeigen bzw. anpassen
- Geheimnisse (Feed-Code, DB, FTP) bleiben in `config/config.php`

---

## Später (Phase 3)

- Rechnungen zwischen Höfen (Buchführung, die App bewegt kein Geld im Spiel)
- Karte mit Fahrzeugpositionen
- Discord-Webhook
