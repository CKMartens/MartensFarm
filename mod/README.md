# FS25_MartensFarmExport

LS25-Mod für martensfarm.de. Nitrado gibt uns ohne FTP nur den Stats-Feed und die Savegame-Dateien
`careerSavegame`, `vehicles` und `economy` frei. Die Mod hängt sich ans Speichern und schreibt einen eigenen
Block `<martensFarm>` in `careerSavegame.xml`. Den liest der Cron über den vorhandenen Feed mit.

Die Mod ist **map-unabhängig und exportiert roh**: alles, was das Spiel gerade hat, jeweils mit Besitzer-ID.
Welche Höfe zählen und wie sie heißen, entscheidet die App (Administration → Einstellungen).

Inhalt des Blocks (Schema 2):

```xml
<martensFarm schema="2" modVersion="0.2.1" writtenAt="2026-11-14 12:26:46">
  <game mapId="..." mapTitle="..." savegameName="..." savegameIndex="2"/>
  <environment day="12" period="8" dayInPeriod="2" daysPerPeriod="3" year="1" dayTime="36000000"/>
  <farms>
    <farm id="1" name="..." money="650242.24" loan="0.00" color="3"/>
  </farms>
  <husbandries>
    <husbandry key="<uniqueId>" farmId="1" name="..." animalType="COW" animals="44" maxAnimals="200"
               productivity="0.85" health="97.50" foodCapacity="..." foodPerDay="..." waterAuto="true" waterPerDay="..." strawPerDay="...">
      <food><fill fillType="SILAGE" level="70000.00"/></food>
      <storage><fill fillType="MILK" level="12000.00" capacity="60000.00"/> <!-- auch WATER, STRAW, LIQUIDMANURE, MANURE … --></storage>
    </husbandry>
  </husbandries>
  <storages>
    <storage key="<uniqueId>" farmId="1" name="..." kind="silo|production|manure|other">
      <fill fillType="WHEAT" level="30000.00" capacity="200000.00"/>
    </storage>
  </storages>
  <diag missing="productivity"/>  <!-- nur wenn das Spiel etwas nicht liefert -->
</martensFarm>
```

- Nur der Server schreibt, am Spiel wird nichts verändert. Jeder Teil läuft einzeln in `pcall`: Scheitert einer, kommen die anderen trotzdem.
- Bestehende Schlüssel in `careerSavegame.xml` werden nicht angefasst.
- Was eine Spielversion nicht hergibt, fehlt einfach und steht in `<diag missing>`. Die App zeigt das unter Einstellungen an.
- Lager werden über die Gebäude gesucht (Silo, Produktion, Misthaufen …), pro Gebäude zusammengefasst und mit dessen `uniqueId` verschlüsselt. Ställe tauchen dort nicht auf, ihr Lager steht unter `<husbandry><storage>`.
- Lager ohne Besitzer und ohne Inhalt werden weggelassen, damit die Datei klein bleibt. Lager mit Inhalt, die zu keinem Gebäude gehören, meldet `<diag missing="unassignedStorages">`.
- Die App liest auch noch Schema 1 (Version 0.1).

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
4. Im Log nach `[MartensFarmExport] Version 0.2.1 geladen` suchen.
5. Einmal speichern (Spieler im Spiel oder Autosave abwarten). Im Log sollte `Block <martensFarm> geschrieben: N Höfe, M Ställe, K Lager` stehen.
6. Auf dem Webserver `keyhelp-php85 bin/check-mod-export.php` ausführen (mit `-v` auch leere Ställe und Lager).

Mitspieler brauchen die Mod ebenfalls. Normalerweise bietet der Dedicated Server sie beim Beitreten zum Download an.
