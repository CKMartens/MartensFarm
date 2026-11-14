# FS25_MartensFarmExport (Test)

Kleine LS25-Mod für martensfarm.de. Nitrado gibt uns ohne FTP nur den Stats-Feed und die Savegame-Dateien
`careerSavegame`, `vehicles` und `economy` frei. Die Mod hängt sich ans Speichern und schreibt einen eigenen
Block `<martensFarm>` in `careerSavegame.xml`. Den kann der Cron über den vorhandenen Feed mitlesen.

Inhalt des Blocks (Schema 1):

```xml
<martensFarm schema="1" modVersion="0.1.0" writtenAt="2026-11-14 11:00:00">
  <test>hallo-martensfarm</test>
  <farms>
    <farm id="1" name="..." money="123456.00" loan="0.00"/>
  </farms>
  <husbandries>
    <husbandry farmId="1" name="..." animalType="COW" animals="12" maxAnimals="40" food="1500.00" water="300.00" straw="80.00"/>
  </husbandries>
</martensFarm>
```

- Nur der Server schreibt, am Spiel wird nichts verändert. Alles läuft in `pcall`, bei einem Fehler steht nur eine Zeile im Log.
- Bestehende Schlüssel in `careerSavegame.xml` werden nicht angefasst.
- Status: **Test**. Ob der Feed den Block wirklich ausliefert, prüft `martensfarm.de/bin/check-mod-export.php`.

## ZIP bauen

Der ZIP-Name muss genau `FS25_MartensFarmExport.zip` sein, die Dateien liegen direkt im ZIP (kein Unterordner):

```sh
cd mod/FS25_MartensFarmExport
zip ../FS25_MartensFarmExport.zip modDesc.xml MartensFarmExport.lua icon_MartensFarmExport.dds
```

## Testen

1. Vorher im Nitrado-Webinterface ein Backup vom Savegame machen.
2. Server stoppen, `FS25_MartensFarmExport.zip` per FTP in den Mod-Ordner laden, Server starten.
3. Im Server-Webinterface die Mod für das Savegame aktivieren (falls nötig) und den Server neu starten.
4. Im Log nach `[MartensFarmExport] Version 0.1.0 geladen` suchen.
5. Einmal speichern (Spieler im Spiel oder Autosave abwarten). Im Log sollte `Block <martensFarm> geschrieben: N Höfe, M Ställe` stehen.
6. Auf dem Webserver `keyhelp-php85 bin/check-mod-export.php` ausführen.

Mitspieler brauchen die Mod ebenfalls. Normalerweise bietet der Dedicated Server sie beim Beitreten zum Download an.
