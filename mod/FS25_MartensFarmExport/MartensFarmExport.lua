-- MartensFarm Export (Test) – FS25
-- Copyright (C) 2026 CKMartens, GPL-3.0 (siehe LICENSE im Repo)
--
-- Hängt sich an FSCareerMissionInfo:saveToXMLFile. Danach ist die careerSavegame.xml
-- noch offen und wird erst anschließend auf die Platte geschrieben. Wir ergänzen dort
-- einen eigenen Block <martensFarm>, den die Web-App über den freigegebenen
-- Savegame-Feed (file=careerSavegame) mitlesen kann.
--
-- Grundsätze: nur auf dem Server, alles in pcall, niemals bestehende Schlüssel anfassen.

MartensFarmExport = {}
MartensFarmExport.VERSION = "0.1.0"
MartensFarmExport.SCHEMA = 1
MartensFarmExport.hooked = false

local function log(fmt, ...)
    print("[MartensFarmExport] " .. string.format(fmt, ...))
end

local function isServer()
    return g_currentMission ~= nil and g_currentMission.getIsServer ~= nil and g_currentMission:getIsServer()
end

-- Kleiner Adapter: FS25 liefert hier je nach Stand einen klassischen XML-Handle (Zahl)
-- oder ein XMLFile-Objekt. Beides wird unterstützt.
local function writer(xmlFile)
    if type(xmlFile) == "table" and xmlFile.setString ~= nil then
        return {
            str = function(k, v) xmlFile:setString(k, v) end,
            int = function(k, v) xmlFile:setInt(k, v) end,
        }
    end
    return {
        str = function(k, v) setXMLString(xmlFile, k, v) end,
        int = function(k, v) setXMLInt(xmlFile, k, v) end,
    }
end

local function num(v)
    return string.format("%.2f", tonumber(v) or 0)
end

local function placeableName(p)
    local ok, name = pcall(function() return p:getName() end)
    if ok and type(name) == "string" then return name end
    return ""
end

local function animalTypeName(p)
    local ok, name = pcall(function()
        local idx = p:getAnimalTypeIndex()
        local t = g_currentMission.animalSystem:getTypeByIndex(idx)
        return t and t.name
    end)
    if ok and type(name) == "string" then return name end
    return ""
end

local function call(p, fnName)
    if p[fnName] == nil then return nil end
    local ok, v = pcall(function() return p[fnName](p) end)
    if ok then return v end
    return nil
end

function MartensFarmExport.writeBlock(xmlFile, rootKey)
    local w = writer(xmlFile)
    local base = rootKey .. ".martensFarm"

    w.int(base .. "#schema", MartensFarmExport.SCHEMA)
    w.str(base .. "#modVersion", MartensFarmExport.VERSION)
    w.str(base .. "#writtenAt", getDate("%Y-%m-%d %H:%M:%S"))
    w.str(base .. ".test", "hallo-martensfarm")

    -- Höfe: Kontostand und Kredit (farmId 0 = Zuschauer, überspringen)
    local nFarms = 0
    if g_farmManager ~= nil and g_farmManager.farms ~= nil then
        local farms = {}
        for _, farm in pairs(g_farmManager.farms) do
            local id = tonumber(farm.farmId) or 0
            if id > 0 then table.insert(farms, farm) end
        end
        table.sort(farms, function(a, b) return a.farmId < b.farmId end)
        for _, farm in ipairs(farms) do
            local key = string.format("%s.farms.farm(%d)", base, nFarms)
            w.int(key .. "#id", farm.farmId)
            w.str(key .. "#name", tostring(farm.name or ""))
            w.str(key .. "#money", num(farm.money))
            w.str(key .. "#loan", num(farm.loan))
            nFarms = nFarms + 1
        end
    end

    -- Ställe: Besitzer, Tierart, Anzahl, Futter/Wasser/Stroh
    local nPens = 0
    local hs = g_currentMission ~= nil and g_currentMission.husbandrySystem or nil
    if hs ~= nil and hs.placeables ~= nil then
        for _, p in pairs(hs.placeables) do
            local key = string.format("%s.husbandries.husbandry(%d)", base, nPens)
            w.int(key .. "#farmId", tonumber(call(p, "getOwnerFarmId")) or 0)
            w.str(key .. "#name", placeableName(p))
            w.str(key .. "#animalType", animalTypeName(p))
            w.int(key .. "#animals", tonumber(call(p, "getNumOfAnimals")) or 0)
            w.int(key .. "#maxAnimals", tonumber(call(p, "getMaxNumOfAnimals")) or 0)

            local food = 0
            if p.spec_husbandryFood ~= nil and p.spec_husbandryFood.fillLevels ~= nil then
                for _, level in pairs(p.spec_husbandryFood.fillLevels) do
                    food = food + (tonumber(level) or 0)
                end
            end
            w.str(key .. "#food", num(food))
            if p.spec_husbandryWater ~= nil then
                w.str(key .. "#water", num(p.spec_husbandryWater.fillLevel))
            end
            if p.spec_husbandryStraw ~= nil then
                w.str(key .. "#straw", num(p.spec_husbandryStraw.fillLevel))
            end
            nPens = nPens + 1
        end
    end

    return nFarms, nPens
end

function MartensFarmExport.onCareerSaved(missionInfo)
    if not isServer() then return end
    local xmlFile = missionInfo ~= nil and missionInfo.xmlFile or nil
    if xmlFile == nil or xmlFile == 0 then
        log("Kein offenes careerSavegame.xml beim Speichern gefunden, nichts geschrieben.")
        return
    end
    local ok, nFarms, nPens = pcall(MartensFarmExport.writeBlock, xmlFile, missionInfo.xmlKey or "careerSavegame")
    if ok then
        log("Block <martensFarm> geschrieben: %d Höfe, %d Ställe.", nFarms or 0, nPens or 0)
    else
        log("Fehler beim Schreiben: %s", tostring(nFarms))
    end
end

function MartensFarmExport:loadMap()
    if MartensFarmExport.hooked then return end
    if FSCareerMissionInfo == nil or FSCareerMissionInfo.saveToXMLFile == nil then
        log("FSCareerMissionInfo.saveToXMLFile nicht gefunden, Export inaktiv.")
        return
    end
    FSCareerMissionInfo.saveToXMLFile = Utils.appendedFunction(FSCareerMissionInfo.saveToXMLFile, MartensFarmExport.onCareerSaved)
    MartensFarmExport.hooked = true
    log("Version %s geladen (Server: %s).", MartensFarmExport.VERSION, tostring(isServer()))
end

addModEventListener(MartensFarmExport)
