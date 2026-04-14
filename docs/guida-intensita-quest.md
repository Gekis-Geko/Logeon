# Guida Intensita Quest

Ultimo aggiornamento: 2026-03-21

## Scopo
Definire un livello di pressione narrativa per ogni quest, senza introdurre meccaniche numeriche di gameplay.

## Scala ufficiale
Codici interni (DB/API):
1. `CHILL`
2. `SOFT`
3. `STANDARD`
4. `HIGH`
5. `CRITICAL`

Label UI italiane:
1. `CHILL` -> Chill
2. `SOFT` -> Soft
3. `STANDARD` -> Standard
4. `HIGH` -> High
5. `CRITICAL` -> Critical

## Regole di ereditarieta
1. Definizione quest:
   - `quest_definitions.intensity_level` (default `STANDARD`)
   - `quest_definitions.intensity_visibility` (`visible|hidden`, default `visible`)
2. Istanza quest:
   - `quest_instances.intensity_level` (override opzionale)
3. Runtime:
   - `effective_intensity_level` = override istanza se presente, altrimenti livello definizione.

## Visibilita
1. Se `intensity_visibility = visible`, il player vede badge e livello.
2. Se `intensity_visibility = hidden`, il livello non viene mostrato al player.
3. Lo staff vede sempre i dati in contesto operativo/admin.

## Ambito funzionale
1. Intensita solo descrittiva/narrativa.
2. Nessun effetto automatico su:
   - difficolta
   - statistiche
   - drop
   - probabilita
   - ricompense

## Mapping colore UI
1. `CHILL`: verde
2. `SOFT`: intermedio freddo
3. `STANDARD`: giallo
4. `HIGH`: intermedio caldo
5. `CRITICAL`: rosso

## Endpoint coinvolti (estensione campi, nessun endpoint nuovo)
1. Admin:
   - `/admin/quests/definitions/create|update|list|get`
   - `/admin/quests/instances/assign|status/set|list|get`
2. Game:
   - `/quests/list|get`
   - `/quests/history/list|get`
