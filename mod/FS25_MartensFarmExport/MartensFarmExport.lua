-- MartensFarm Export – FS25
-- Copyright (C) 2026 CKMartens, GPL-3.0 (siehe LICENSE im Repo)
--
-- Hängt sich an FSCareerMissionInfo:saveToXMLFile. Danach ist die careerSavegame.xml
-- noch offen und wird erst anschließend auf die Platte geschrieben. Wir ergänzen dort
-- einen eigenen Block <martensFarm>, den die Web-App über den freigegebenen
-- Savegame-Feed (file=careerSavegame) mitlesen kann.
--
-- Grundsätze:
--   * map-unabhängig und roh: alles, was das Spiel hat, mit Besitzer-ID. Gefiltert wird in der App.
--   * nur auf dem Server, alles in pcall, niemals bestehende Schlüssel anfassen.
--   * was eine Spielversion nicht liefert, fehlt einfach und landet in <diag missing="...">.

MartensFarmExport = {}
MartensFarmExport.VERSION = "0.2.0"
MartensFarmExport.SCHEMA = 2
MartensFarmExport.hooked = false

local missing = {}
local unpack = unpack or table.unpack

local function log(fmt, ...)
    print("[MartensFarmExport] " .. string.format(fmt, ...))
end

local function miss(name)
    missing[name] = true
end

local function isServer()
    return g_currentMission ~= nil and g_currentMission.getIsServer ~= nil and g_currentMission:getIsServer()
end

-- Klassischer XML-Handle (Zahl) oder XMLFile-Objekt, beides wird unterstützt.
local function writer(xmlFile)
    if type(xmlFile) == "table" and xmlFile.setString ~= nil then
        return {
            str = function(k, v) xmlFile:setString(k, tostring(v)) end,
            int = function(k, v) xmlFile:setInt(k, math.floor(tonumber(v) or 0)) end,
        }
    end
    return {
        str = function(k, v) setXMLString(xmlFile, k, tostring(v)) end,
        int = function(k, v) setXMLInt(xmlFile, k, math.floor(tonumber(v) or 0)) end,
    }
end

local function num(v)
    return string.format("%.2f", tonumber(v) or 0)
end

--- Methode sicher aufrufen. Gibt nil zurück, wenn es sie nicht gibt oder sie scheitert.
local function call(obj, fnName, ...)
    if obj == nil or type(obj[fnName]) ~= "function" then return nil end
    local args = { ... }
    local ok, v = pcall(function() return obj[fnName](obj, unpack(args)) end)
    if ok then return v end
    return nil
end

local function fillTypeName(idx)
    if type(idx) == "string" then return idx end
    if g_fillTypeManager == nil then return tostring(idx) end
    local name = call(g_fillTypeManager, "getFillTypeNameByIndex", idx)
    if type(name) == "string" then return name end
    local ft = call(g_fillTypeManager, "getFillTypeByIndex", idx)
    if ft ~= nil and type(ft.name) == "string" then return ft.name end
    return tostring(idx)
end

local function placeableName(p)
    local name = call(p, "getName")
    if type(name) == "string" then return name end
    return ""
end

local function placeableKey(p, fallback)
    if p ~= nil and p.uniqueId ~= nil then return tostring(p.uniqueId) end
    return fallback
end

local function perDay(spec)
    if spec == nil then return nil end
    local lph = tonumber(spec.litersPerHour)
    if lph ~= nil and lph > 0 then return lph * 24 end
    local lpd = tonumber(spec.litersPerDay)
    if lpd ~= nil and lpd > 0 then return lpd end
    return nil
end

--- Füllstände eines Storage-Objekts als Liste { {name, level, capacity}, ... }
local function storageFills(storage)
    local out = {}
    if storage == nil or type(storage.fillLevels) ~= "table" then return out end
    for ftIndex, level in pairs(storage.fillLevels) do
        local lvl = tonumber(level) or 0
        local cap = tonumber(call(storage, "getCapacity", ftIndex))
        if cap == nil and type(storage.capacities) == "table" then cap = tonumber(storage.capacities[ftIndex]) end
        if cap == nil then cap = tonumber(storage.capacity) end
        table.insert(out, { name = fillTypeName(ftIndex), level = lvl, capacity = cap })
    end
    table.sort(out, function(a, b) return a.name < b.name end)
    return out
end

local function writeFills(w, key, fills, kind, onlyFilled)
    local i = 0
    for _, f in ipairs(fills) do
        if not onlyFilled or f.level > 0.5 then
            local k = string.format("%s.fill(%d)", key, i)
            w.str(k .. "#fillType", f.name)
            w.str(k .. "#level", num(f.level))
            if f.capacity ~= nil then w.str(k .. "#capacity", num(f.capacity)) end
            if kind ~= nil then w.str(k .. "#kind", kind) end
            i = i + 1
        end
    end
end

local function writeGame(w, base, missionInfo)
    local k = base .. ".game"
    w.str(k .. "#mapId", missionInfo.mapId or "")
    w.str(k .. "#mapTitle", missionInfo.mapTitle or "")
    w.str(k .. "#savegameName", missionInfo.savegameName or "")
    if missionInfo.savegameIndex ~= nil then w.int(k .. "#savegameIndex", missionInfo.savegameIndex) end

    local env = g_currentMission ~= nil and g_currentMission.environment or nil
    if env == nil then miss("environment") return end
    local e = base .. ".environment"
    if env.currentDay ~= nil then w.int(e .. "#day", env.currentDay) end
    if env.currentPeriod ~= nil then w.int(e .. "#period", env.currentPeriod) end
    if env.currentDayInPeriod ~= nil then w.int(e .. "#dayInPeriod", env.currentDayInPeriod) end
    if env.daysPerPeriod ~= nil then w.int(e .. "#daysPerPeriod", env.daysPerPeriod) end
    if env.currentYear ~= nil then w.int(e .. "#year", env.currentYear) end
    if env.dayTime ~= nil then w.int(e .. "#dayTime", env.dayTime) end
end

local function writeFarms(w, base)
    local n = 0
    if g_farmManager == nil or g_farmManager.farms == nil then miss("farmManager") return 0 end
    local farms = {}
    for _, farm in pairs(g_farmManager.farms) do
        local id = tonumber(farm.farmId) or 0
        if id > 0 then table.insert(farms, farm) end
    end
    table.sort(farms, function(a, b) return a.farmId < b.farmId end)
    for _, farm in ipairs(farms) do
        local key = string.format("%s.farms.farm(%d)", base, n)
        w.int(key .. "#id", farm.farmId)
        w.str(key .. "#name", farm.name or "")
        w.str(key .. "#money", num(farm.money))
        w.str(key .. "#loan", num(farm.loan))
        if farm.color ~= nil then w.int(key .. "#color", farm.color) end
        n = n + 1
    end
    return n
end

local function animalTypeName(p)
    local idx = call(p, "getAnimalTypeIndex")
    if idx == nil then return "" end
    local sys = g_currentMission.animalSystem
    local t = sys ~= nil and call(sys, "getTypeByIndex", idx) or nil
    if t ~= nil and type(t.name) == "string" then return t.name end
    return tostring(idx)
end

--- Gesundheit (0–100) gewichtet nach Tierzahl
local function avgHealth(p)
    local clusters = call(p, "getClusters")
    if type(clusters) ~= "table" then return nil end
    local sum, count = 0, 0
    for _, c in pairs(clusters) do
        local n = tonumber(call(c, "getNumAnimals")) or tonumber(c.numAnimals) or 0
        local h = tonumber(c.health)
        if n > 0 and h ~= nil then
            sum = sum + h * n
            count = count + n
        end
    end
    if count == 0 then return nil end
    return sum / count
end

local function writeHusbandries(w, base)
    local hs = g_currentMission ~= nil and g_currentMission.husbandrySystem or nil
    if hs == nil or hs.placeables == nil then miss("husbandrySystem") return 0 end
    local n = 0
    for _, p in pairs(hs.placeables) do
        local key = string.format("%s.husbandries.husbandry(%d)", base, n)
        w.str(key .. "#key", placeableKey(p, "pen" .. n))
        w.int(key .. "#farmId", tonumber(call(p, "getOwnerFarmId")) or 0)
        w.str(key .. "#name", placeableName(p))
        w.str(key .. "#animalType", animalTypeName(p))
        w.int(key .. "#animals", tonumber(call(p, "getNumOfAnimals")) or 0)
        local maxN = tonumber(call(p, "getMaxNumOfAnimals"))
        if maxN ~= nil then w.int(key .. "#maxAnimals", maxN) end

        local prod = tonumber(call(p, "getGlobalProductionFactor"))
        if prod ~= nil then w.str(key .. "#productivity", num(prod)) else miss("productivity") end
        local health = avgHealth(p)
        if health ~= nil then w.str(key .. "#health", num(health)) end

        -- Futter: eigene Füllstände je Sorte
        local food = p.spec_husbandryFood
        local fills = {}
        if food ~= nil and type(food.fillLevels) == "table" then
            for ftIndex, level in pairs(food.fillLevels) do
                table.insert(fills, { name = fillTypeName(ftIndex), level = tonumber(level) or 0 })
            end
            table.sort(fills, function(a, b) return a.name < b.name end)
            local cap = tonumber(call(p, "getFoodCapacity")) or tonumber(food.capacity)
            if cap ~= nil then w.str(key .. "#foodCapacity", num(cap)) end
            local fpd = perDay(food)
            if fpd ~= nil then w.str(key .. "#foodPerDay", num(fpd)) end
        end

        local water = p.spec_husbandryWater
        if water ~= nil then
            local auto = water.automaticWaterSupply
            if auto == nil then auto = water.isAutomatic end
            if auto ~= nil then w.str(key .. "#waterAuto", auto and "true" or "false") end
            local wpd = perDay(water)
            if wpd ~= nil then w.str(key .. "#waterPerDay", num(wpd)) end
        end
        local straw = p.spec_husbandryStraw
        if straw ~= nil then
            local spd = perDay(straw)
            if spd ~= nil then w.str(key .. "#strawPerDay", num(spd)) end
        end

        writeFills(w, key .. ".food", fills, nil, false)

        -- Stall-Lager: Wasser, Stroh, Milch, Gülle, Mist … mit Kapazität
        local storage = p.spec_husbandry ~= nil and p.spec_husbandry.storage or nil
        if storage ~= nil then
            writeFills(w, key .. ".storage", storageFills(storage), nil, false)
        else
            miss("husbandryStorage")
        end
        n = n + 1
    end
    return n
end

local function storageKind(placeable)
    if placeable == nil then return "other" end
    if placeable.spec_husbandry ~= nil then return "husbandry" end
    if placeable.spec_productionPoint ~= nil then return "production" end
    if placeable.spec_silo ~= nil or placeable.spec_siloExtension ~= nil then return "silo" end
    return "other"
end

local function writeStorages(w, base)
    local sys = g_currentMission ~= nil and g_currentMission.storageSystem or nil
    if sys == nil then miss("storageSystem") return 0 end
    local list = call(sys, "getStorages") or sys.storages
    if type(list) ~= "table" then miss("storages") return 0 end

    -- Je nach Version sind Storages Schlüssel oder Werte der Tabelle
    local seen, storages = {}, {}
    for k, v in pairs(list) do
        local s = (type(v) == "table" and v.fillLevels ~= nil) and v or ((type(k) == "table" and k.fillLevels ~= nil) and k or nil)
        if s ~= nil and not seen[s] then
            seen[s] = true
            table.insert(storages, s)
        end
    end

    -- Pro Gebäude zusammenfassen (ein Silo kann mehrere Storages haben). So bleiben die
    -- Schlüssel stabil, egal in welcher Reihenfolge das Spiel die Storages liefert.
    local groups, order, loose = {}, {}, 0
    for _, s in ipairs(storages) do
        local owner = s.owningPlaceable
        local farmId = tonumber(call(s, "getOwnerFarmId")) or tonumber(s.ownerFarmId) or 0
        if farmId == 0 and owner ~= nil then farmId = tonumber(call(owner, "getOwnerFarmId")) or 0 end
        local fills = storageFills(s)
        local hasContent = false
        for _, f in ipairs(fills) do
            if f.level > 0.5 then hasContent = true break end
        end
        -- Leere Lager ohne Besitzer lassen wir weg, sonst wird die Datei unnötig groß
        if farmId > 0 or hasContent then
            local pk = placeableKey(owner, nil)
            if pk == nil then
                loose = loose + 1
                pk = "storage" .. loose
            end
            local g = groups[pk]
            if g == nil then
                g = { farmId = farmId, name = placeableName(owner), kind = storageKind(owner), fills = {} }
                groups[pk] = g
                table.insert(order, pk)
            end
            for _, f in ipairs(fills) do
                local e = g.fills[f.name]
                if e == nil then
                    g.fills[f.name] = { name = f.name, level = f.level, capacity = f.capacity }
                else
                    e.level = e.level + f.level
                    if f.capacity ~= nil then e.capacity = (e.capacity or 0) + f.capacity end
                end
            end
        end
    end
    table.sort(order)

    local n = 0
    for _, pk in ipairs(order) do
        local g = groups[pk]
        local fills = {}
        for _, f in pairs(g.fills) do table.insert(fills, f) end
        table.sort(fills, function(a, b) return a.name < b.name end)
        local key = string.format("%s.storages.storage(%d)", base, n)
        w.str(key .. "#key", pk)
        w.int(key .. "#farmId", g.farmId)
        w.str(key .. "#name", g.name)
        w.str(key .. "#kind", g.kind)
        writeFills(w, key, fills, nil, true)
        n = n + 1
    end
    return n
end

function MartensFarmExport.writeBlock(xmlFile, rootKey, missionInfo)
    missing = {}
    local w = writer(xmlFile)
    local base = rootKey .. ".martensFarm"
    local counts = {}

    w.int(base .. "#schema", MartensFarmExport.SCHEMA)
    w.str(base .. "#modVersion", MartensFarmExport.VERSION)
    w.str(base .. "#writtenAt", getDate("%Y-%m-%d %H:%M:%S"))

    -- Jeder Teil einzeln abgesichert: scheitert einer, kommen die anderen trotzdem
    local parts = {
        { "game", function() writeGame(w, base, missionInfo or {}) return 1 end },
        { "farms", function() return writeFarms(w, base) end },
        { "husbandries", function() return writeHusbandries(w, base) end },
        { "storages", function() return writeStorages(w, base) end },
    }
    for _, part in ipairs(parts) do
        local ok, res = pcall(part[2])
        if ok then
            counts[part[1]] = res or 0
        else
            counts[part[1]] = 0
            miss(part[1] .. "-error")
            log("Teil %s fehlgeschlagen: %s", part[1], tostring(res))
        end
    end

    local names = {}
    for name in pairs(missing) do table.insert(names, name) end
    table.sort(names)
    if #names > 0 then w.str(base .. ".diag#missing", table.concat(names, ",")) end
    return counts
end

function MartensFarmExport.onCareerSaved(missionInfo)
    if not isServer() then return end
    local xmlFile = missionInfo ~= nil and missionInfo.xmlFile or nil
    if xmlFile == nil or xmlFile == 0 then
        log("Kein offenes careerSavegame.xml beim Speichern gefunden, nichts geschrieben.")
        return
    end
    local ok, counts = pcall(MartensFarmExport.writeBlock, xmlFile, missionInfo.xmlKey or "careerSavegame", missionInfo)
    if ok then
        log("Block <martensFarm> geschrieben: %d Höfe, %d Ställe, %d Lager.", counts.farms or 0, counts.husbandries or 0, counts.storages or 0)
    else
        log("Fehler beim Schreiben: %s", tostring(counts))
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
