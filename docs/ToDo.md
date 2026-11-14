# ToDo / Roadmap

Stand: 14.11.2026. Basis ist Carstens Ideenliste, ergänzt um die abgestimmten Entscheidungen.

**Rahmen für alles:**
- Server steht im Hetzner-Netz: keine offenen Formulare, Rate-Limits, schonende Abrufe
- Geheimnisse (DB-Passwort, Feed-Code) bleiben in `config/config.php`, nie in der Oberfläche oder im Repo
- Datenquellen: `stats.xml` (live) sowie `careerSavegame`, `vehicles` und `economy` (nur nach dem Speichern). Über die eigene Export-Mod kommen in `careerSavegame` auch alle Höfe, Ställe, Lager und der Kalender mit.

---

## Reihenfolge

| # | Paket | Umfang |
| --- | --- | --- |
| 1 | Grundlagen: Rollennamen, Einstellungen | klein |
| 2 | Finanzen: Buchungen und Abgleich mit dem Kontostand | mittel |
| 3 | Aufgaben-Board inkl. automatischer Werkstatt-Aufgaben | mittel |
| 4 | Fahrzeugverwaltung erweitern | klein |
| 5 | Datenweg erweitern: Export-Mod für Tiere, Lager und Höfe 2–8 | erledigt (0.2) |
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
  - Achtung: Der Kontostand ändert sich nur, wenn der Server speichert. Mit der Export-Mod gibt es ihn für alle Höfe.
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
- [ ] **Fahrzeugwartung automatisch**: Die Schwelle ist unter Einstellungen einstellbar, **Default 20 %** (Dashboard und Fuhrpark nutzen sie schon). Es gibt keine doppelten Aufgaben pro Fahrzeug, und die Aufgabe schließt sich, sobald repariert wurde.
- [ ] **Tierpflege**: Die Hinweise gibt es schon (Seite Tiere und Dashboard: Futter, Wasser, Stroh, Produkte fast voll; Schwellen unter Einstellungen). Mit dem Board werden daraus automatische Aufgaben.
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

## 5. Datenweg erweitern ✔

FTP bei Nitrado geht nur für den Mod-Ordner und nur bei gestopptem Server, FarmHub scheidet damit aus (und hat eine Non-Commercial-Lizenz).
Gelöst mit der eigenen Mod `FS25_MartensFarmExport` (Ordner `mod/`, GPL-3.0):

- [x] Mod schreibt beim Speichern `<martensFarm>` in `careerSavegame.xml`, abrufbar über den freigegebenen Feed
- [x] Map-unabhängig und roh: alle Höfe, Ställe, Lager mit Besitzer-ID, dazu der Spielkalender
- [x] App: Import, Seiten Tiere und Lager, Tierpflege-Hinweise, Savegame-/Map-Wechsel mit Archiv
- [x] Administration → Einstellungen: welche Höfe zählen, Namen, Schwellen
- [ ] Im echten Spiel prüfen, welche Werte LS25 liefert (Wasser, Stroh, Futter pro Tag, Produktivität). Was fehlt, zeigt `bin/check-mod-export.php` bzw. die Einstellungsseite.

## 6. Downloadverwaltung

Mods, die nicht im offiziellen GIANTS-ModHub sind, für die Mitspieler zum Download.

- [ ] **Nur eigene oder ausdrücklich freigegebene Mods.** Bei fremden Mods vorher die Erlaubnis der Autoren einholen, viele verbieten Re-Uploads.
- [ ] Pro Mod: Version, Changelog, Prüfsumme (SHA-256), Lizenz-/Quellenhinweis
- [ ] Hinweis vor dem Download, dass es private Mods sind (Nutzung auf eigene Gefahr, nicht weitergeben)
- [ ] Nur für eingeloggte Nutzer. Die Dateien liegen **außerhalb des Document Root** und werden über PHP mit Rechteprüfung ausgeliefert.
- [ ] Größengrenze pro Datei, kein öffentlicher Link

## Administration

- [x] Einstellungsseite (`/admin/einstellungen`): Höfe, Namen, Wartungsschwelle, Tier-Schwellen, Savegames
- [ ] Weitere Einstellungen bei Bedarf: Importer-Intervall
- [ ] Buchungskategorien pflegen (anlegen, umbenennen, deaktivieren)
- [ ] Rechte je Rolle anzeigen bzw. anpassen
- Geheimnisse (Feed-Code, DB, FTP) bleiben in `config/config.php`

---

## Später (Phase 3)

- Rechnungen zwischen Höfen (Buchführung, die App bewegt kein Geld im Spiel)
- Karte mit Fahrzeugpositionen
- Discord-Webhook
