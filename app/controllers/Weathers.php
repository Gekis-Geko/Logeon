<?php

declare(strict_types=1);

use App\Models\Weather;
use App\Services\WeatherClimateService;
use App\Services\WeatherGenerationService;
use App\Services\WeatherOverrideService;
use App\Services\WeatherResolverService;
use Core\Http\ApiResponse;
use Core\Http\AppError;
use Core\Http\InputValidator;
use Core\Http\RequestData;

use Core\Http\ResponseEmitter;
use Core\SessionStore;

class Weathers extends Weather
{
    /** @var WeatherGenerationService */
    private $gen;
    /** @var WeatherOverrideService */
    private $overrideService;
    /** @var WeatherResolverService */
    private $resolver;
    /** @var WeatherClimateService */
    private $climateService;

    public function __construct()
    {
        $this->gen = new WeatherGenerationService();
        $this->climateService = new WeatherClimateService();
        $this->overrideService = new WeatherOverrideService();
        $this->resolver = new WeatherResolverService($this->gen, $this->overrideService, $this->climateService);
    }

    // -------------------------------------------------------------------------
    // Public endpoints
    // -------------------------------------------------------------------------

    public function weather()
    {
        $data = $this->decodePayload();
        $locationId = isset($data->location_id) ? (int) $data->location_id : 0;
        $worldId = isset($data->world_id) ? (int) $data->world_id : null;
        if ($worldId !== null && $worldId <= 0) {
            $worldId = null;
        }
        $state = $this->resolveState($locationId, $worldId);
        ResponseEmitter::emit(ApiResponse::json($state));
        return $state;
    }

    public function options()
    {
        \Core\AuthGuard::api()->requireCharacter();

        $conditions = [];
        if ($this->climateService->isAvailable()) {
            $weatherTypes = $this->climateService->listWeatherTypes(true);
            foreach ($weatherTypes as $row) {
                $rowArr = (array) $row;
                $conditions[] = [
                    'id' => (int) ($rowArr['id'] ?? 0),
                    'key' => (string) ($rowArr['slug'] ?? ''),
                    'title' => (string) ($rowArr['name'] ?? ($rowArr['slug'] ?? '')),
                    'visual_group' => (string) ($rowArr['visual_group'] ?? ''),
                ];
            }
        }
        if (empty($conditions)) {
            $conditions = array_map(
                fn ($r) => ['key' => $r['key'], 'title' => $r['title']],
                $this->gen->getConditions(),
            );
        }

        $moonPhases = array_map(
            fn ($r) => ['phase' => $r['phase'], 'title' => $r['title']],
            $this->gen->getMoonPhases(),
        );

        $seasons = [];
        if ($this->climateService->isAvailable()) {
            $seasonRows = $this->climateService->listSeasons(true);
            foreach ($seasonRows as $row) {
                $rowArr = (array) $row;
                $seasons[] = [
                    'id' => (int) ($rowArr['id'] ?? 0),
                    'slug' => (string) ($rowArr['slug'] ?? ''),
                    'title' => (string) ($rowArr['name'] ?? ''),
                ];
            }
        }

        ResponseEmitter::emit(ApiResponse::json([
            'dataset' => [
                'conditions' => $conditions,
                'moon_phases' => $moonPhases,
                'seasons' => $seasons,
            ],
        ]));
    }

    public function setGlobal()
    {
        $this->requireWeatherStaff('global');
        $data = $this->decodePayload();

        $weatherKey = $this->normalizeWeatherKey($data->weather_key ?? null);
        $degrees = $this->normalizeDegrees($data->degrees ?? null);
        $moonPhase = $this->normalizeMoonPhase($data->moon_phase ?? null);

        $this->overrideService->saveGlobalOverride($weatherKey, $degrees, $moonPhase);

        ResponseEmitter::emit(ApiResponse::json([
            'dataset' => $this->resolveState(0),
        ]));
    }

    public function clearGlobal()
    {
        $this->requireWeatherStaff('global');
        $this->overrideService->saveGlobalOverride(null, null, null);

        ResponseEmitter::emit(ApiResponse::json([
            'dataset' => $this->resolveState(0),
        ]));
    }

    public function setWorld()
    {
        $this->requireWeatherStaff('global');
        $data = $this->decodePayload();

        $worldId = $this->normalizeWorldId($data->world_id ?? 0);
        $weatherKey = $this->normalizeWeatherKey($data->weather_key ?? null);
        $degrees = $this->normalizeDegrees($data->degrees ?? null);
        $moonPhase = $this->normalizeMoonPhase($data->moon_phase ?? null);

        $this->overrideService->saveWorldOverride($worldId, $weatherKey, $degrees, $moonPhase);

        ResponseEmitter::emit(ApiResponse::json([
            'dataset' => $this->resolveState(0, $worldId),
        ]));
    }

    public function clearWorld()
    {
        $this->requireWeatherStaff('global');
        $data = $this->decodePayload();

        $worldId = $this->normalizeWorldId($data->world_id ?? 0);
        $this->overrideService->clearWorldOverride($worldId);

        ResponseEmitter::emit(ApiResponse::json([
            'dataset' => $this->resolveState(0, $worldId),
        ]));
    }

    public function setLocation()
    {
        $this->requireWeatherStaff('location');
        $userId = \Core\AuthGuard::api()->requireUser();
        $data = $this->decodePayload();

        $locationId = isset($data->location_id) ? (int) $data->location_id : 0;
        if ($locationId <= 0) {
            $this->failValidation('Luogo non valido');
        }
        $this->ensureLocationExists($locationId);

        $weatherKey = $this->normalizeWeatherKey($data->weather_key ?? null);
        $degrees = $this->normalizeDegrees($data->degrees ?? null);
        $moonPhase = $this->normalizeMoonPhase($data->moon_phase ?? null);
        $expiresAt = $this->normalizeExpiresAt($data->expires_at ?? null);
        $note = isset($data->note) ? (trim((string) $data->note) ?: null) : null;

        if ($weatherKey === null && $degrees === null && $moonPhase === null) {
            $this->overrideService->deleteLocationOverride($locationId);
        } else {
            $this->overrideService->upsertLocationOverride(
                $locationId,
                $weatherKey,
                $degrees,
                $moonPhase,
                (int) $userId,
                $expiresAt,
                $note,
            );
        }

        ResponseEmitter::emit(ApiResponse::json([
            'dataset' => $this->resolveState($locationId),
        ]));
    }

    public function clearLocation()
    {
        $this->requireWeatherStaff('location');
        $data = $this->decodePayload();

        $locationId = isset($data->location_id) ? (int) $data->location_id : 0;
        if ($locationId <= 0) {
            $this->failValidation('Luogo non valido');
        }
        $this->ensureLocationExists($locationId);

        $this->overrideService->deleteLocationOverride($locationId);

        ResponseEmitter::emit(ApiResponse::json([
            'dataset' => $this->resolveState($locationId),
        ]));
    }

    // -------------------------------------------------------------------------
    // Climate area admin endpoints
    // -------------------------------------------------------------------------

    public function climateAreaList()
    {
        \Core\AuthGuard::api()->requireAbility('settings.manage', [], 'Accesso non autorizzato');
        $data = $this->decodePayload();
        $filters = [];
        if (isset($data->query) && !is_array($data->query) && !is_object($data->query)) {
            $filters['query'] = trim((string) $data->query);
        }
        if (isset($data->is_active)) {
            $filters['is_active'] = (int) $data->is_active;
        }
        if (isset($data->active_only)) {
            $filters['active_only'] = (int) $data->active_only;
        }
        $areas = $this->overrideService->listClimateAreas($filters);

        ResponseEmitter::emit(ApiResponse::json(['dataset' => $areas]));
    }

    public function climateAreaCreate()
    {
        \Core\AuthGuard::api()->requireAbility('settings.manage', [], 'Accesso non autorizzato');
        $data = $this->decodePayload();

        $area = $this->overrideService->createClimateArea($data);

        ResponseEmitter::emit(ApiResponse::json(['dataset' => $area]));
    }

    public function climateAreaUpdate()
    {
        \Core\AuthGuard::api()->requireAbility('settings.manage', [], 'Accesso non autorizzato');
        $data = $this->decodePayload();

        $area = $this->overrideService->updateClimateArea($data);

        ResponseEmitter::emit(ApiResponse::json(['dataset' => $area]));
    }

    public function climateAreaDelete()
    {
        \Core\AuthGuard::api()->requireAbility('settings.manage', [], 'Accesso non autorizzato');
        $data = $this->decodePayload();

        $id = (int) ($data->id ?? 0);
        if ($id <= 0) {
            $this->failValidation('Area climatica non valida', 'climate_area_invalid');
        }

        $this->overrideService->deleteClimateArea($id);

        ResponseEmitter::emit(ApiResponse::json(['success' => true]));
    }

    public function climateAreaAssign()
    {
        \Core\AuthGuard::api()->requireAbility('settings.manage', [], 'Accesso non autorizzato');
        $data = $this->decodePayload();

        $locationId = (int) ($data->location_id ?? 0);
        $climateAreaId = isset($data->climate_area_id) && $data->climate_area_id !== null && $data->climate_area_id !== ''
            ? (int) $data->climate_area_id
            : null;

        if ($locationId <= 0) {
            $this->failValidation('Luogo non valido');
        }

        $this->overrideService->assignLocationToClimateArea($locationId, $climateAreaId);

        ResponseEmitter::emit(ApiResponse::json(['success' => true]));
    }

    // -------------------------------------------------------------------------
    // Weather & Climate v2 admin endpoints
    // -------------------------------------------------------------------------

    public function weatherTypeList()
    {
        \Core\AuthGuard::api()->requireAbility('settings.manage', [], 'Accesso non autorizzato');
        $data = $this->decodePayload();
        $filters = [];
        if (isset($data->active_only)) {
            $filters['active_only'] = (int) $data->active_only;
        }
        if (isset($data->is_active)) {
            $filters['is_active'] = (int) $data->is_active;
        }
        if (isset($data->query) && !is_array($data->query) && !is_object($data->query)) {
            $filters['query'] = trim((string) $data->query);
        }
        $rows = $this->climateService->listWeatherTypes($filters);
        ResponseEmitter::emit(ApiResponse::json(['dataset' => $rows]));
    }

    public function weatherTypeCreate()
    {
        \Core\AuthGuard::api()->requireAbility('settings.manage', [], 'Accesso non autorizzato');
        $data = $this->decodePayload();
        $row = $this->climateService->createWeatherType($data);
        ResponseEmitter::emit(ApiResponse::json(['dataset' => $row]));
    }

    public function weatherTypeUpdate()
    {
        \Core\AuthGuard::api()->requireAbility('settings.manage', [], 'Accesso non autorizzato');
        $data = $this->decodePayload();
        $row = $this->climateService->updateWeatherType($data);
        ResponseEmitter::emit(ApiResponse::json(['dataset' => $row]));
    }

    public function weatherTypeDelete()
    {
        \Core\AuthGuard::api()->requireAbility('settings.manage', [], 'Accesso non autorizzato');
        $data = $this->decodePayload();
        $id = (int) ($data->id ?? 0);
        if ($id <= 0) {
            $this->failValidation('Tipo meteo non valido', 'weather_type_invalid');
        }
        $this->climateService->deleteWeatherType($id);
        ResponseEmitter::emit(ApiResponse::json(['success' => true]));
    }

    public function seasonList()
    {
        \Core\AuthGuard::api()->requireAbility('settings.manage', [], 'Accesso non autorizzato');
        $data = $this->decodePayload();
        $filters = [];
        if (isset($data->active_only)) {
            $filters['active_only'] = (int) $data->active_only;
        }
        if (isset($data->is_active)) {
            $filters['is_active'] = (int) $data->is_active;
        }
        if (isset($data->query) && !is_array($data->query) && !is_object($data->query)) {
            $filters['query'] = trim((string) $data->query);
        }
        $rows = $this->climateService->listSeasons($filters);
        ResponseEmitter::emit(ApiResponse::json(['dataset' => $rows]));
    }

    public function seasonCreate()
    {
        \Core\AuthGuard::api()->requireAbility('settings.manage', [], 'Accesso non autorizzato');
        $row = $this->climateService->createSeason($this->decodePayload());
        ResponseEmitter::emit(ApiResponse::json(['dataset' => $row]));
    }

    public function seasonUpdate()
    {
        \Core\AuthGuard::api()->requireAbility('settings.manage', [], 'Accesso non autorizzato');
        $row = $this->climateService->updateSeason($this->decodePayload());
        ResponseEmitter::emit(ApiResponse::json(['dataset' => $row]));
    }

    public function seasonDelete()
    {
        \Core\AuthGuard::api()->requireAbility('settings.manage', [], 'Accesso non autorizzato');
        $data = $this->decodePayload();
        $id = (int) ($data->id ?? 0);
        if ($id <= 0) {
            $this->failValidation('Stagione non valida', 'season_invalid');
        }
        $this->climateService->deleteSeason($id);
        ResponseEmitter::emit(ApiResponse::json(['success' => true]));
    }

    public function climateZoneList()
    {
        \Core\AuthGuard::api()->requireAbility('settings.manage', [], 'Accesso non autorizzato');
        $data = $this->decodePayload();
        $filters = [];
        if (isset($data->active_only)) {
            $filters['active_only'] = (int) $data->active_only;
        }
        if (isset($data->is_active)) {
            $filters['is_active'] = (int) $data->is_active;
        }
        if (isset($data->query) && !is_array($data->query) && !is_object($data->query)) {
            $filters['query'] = trim((string) $data->query);
        }
        $rows = $this->climateService->listClimateZones($filters);
        ResponseEmitter::emit(ApiResponse::json(['dataset' => $rows]));
    }

    public function climateZoneCreate()
    {
        \Core\AuthGuard::api()->requireAbility('settings.manage', [], 'Accesso non autorizzato');
        $row = $this->climateService->createClimateZone($this->decodePayload());
        ResponseEmitter::emit(ApiResponse::json(['dataset' => $row]));
    }

    public function climateZoneUpdate()
    {
        \Core\AuthGuard::api()->requireAbility('settings.manage', [], 'Accesso non autorizzato');
        $row = $this->climateService->updateClimateZone($this->decodePayload());
        ResponseEmitter::emit(ApiResponse::json(['dataset' => $row]));
    }

    public function climateZoneDelete()
    {
        \Core\AuthGuard::api()->requireAbility('settings.manage', [], 'Accesso non autorizzato');
        $data = $this->decodePayload();
        $id = (int) ($data->id ?? 0);
        if ($id <= 0) {
            $this->failValidation('Zona climatica non valida', 'climate_zone_invalid');
        }
        $this->climateService->deleteClimateZone($id);
        ResponseEmitter::emit(ApiResponse::json(['success' => true]));
    }

    public function profileList()
    {
        \Core\AuthGuard::api()->requireAbility('settings.manage', [], 'Accesso non autorizzato');
        $data = $this->decodePayload();
        $filters = [];
        if (isset($data->query) && !is_array($data->query) && !is_object($data->query)) {
            $filters['query'] = trim((string) $data->query);
        }
        if (isset($data->climate_zone_id)) {
            $filters['climate_zone_id'] = (int) $data->climate_zone_id;
        }
        if (isset($data->season_id)) {
            $filters['season_id'] = (int) $data->season_id;
        }
        if (isset($data->is_active)) {
            $filters['is_active'] = (int) $data->is_active;
        }
        $rows = $this->climateService->listSeasonProfiles($filters);
        ResponseEmitter::emit(ApiResponse::json(['dataset' => $rows]));
    }

    public function profileUpsert()
    {
        \Core\AuthGuard::api()->requireAbility('settings.manage', [], 'Accesso non autorizzato');
        $row = $this->climateService->upsertSeasonProfile($this->decodePayload());
        ResponseEmitter::emit(ApiResponse::json(['dataset' => $row]));
    }

    public function profileDelete()
    {
        \Core\AuthGuard::api()->requireAbility('settings.manage', [], 'Accesso non autorizzato');
        $data = $this->decodePayload();
        $id = (int) ($data->id ?? 0);
        if ($id <= 0) {
            $this->failValidation('Profilo non valido', 'climate_profile_invalid');
        }
        $this->climateService->deleteSeasonProfile($id);
        ResponseEmitter::emit(ApiResponse::json(['success' => true]));
    }

    public function profileWeightsList()
    {
        \Core\AuthGuard::api()->requireAbility('settings.manage', [], 'Accesso non autorizzato');
        $data = $this->decodePayload();
        $profileId = (int) ($data->profile_id ?? 0);
        $rows = $this->climateService->listProfileWeights($profileId);
        ResponseEmitter::emit(ApiResponse::json(['dataset' => $rows]));
    }

    public function profileWeightsSync()
    {
        \Core\AuthGuard::api()->requireAbility('settings.manage', [], 'Accesso non autorizzato');
        $data = $this->decodePayload();
        $profileId = (int) ($data->profile_id ?? 0);
        $weightsRaw = $data->weights ?? [];
        $weights = [];
        if (is_array($weightsRaw)) {
            $weights = $weightsRaw;
        } elseif (is_object($weightsRaw)) {
            $weights = (array) $weightsRaw;
        }
        $rows = $this->climateService->syncProfileWeights($profileId, $weights);
        ResponseEmitter::emit(ApiResponse::json(['dataset' => $rows]));
    }

    public function assignmentList()
    {
        \Core\AuthGuard::api()->requireAbility('settings.manage', [], 'Accesso non autorizzato');
        $data = $this->decodePayload();
        $filters = [];
        if (isset($data->query) && !is_array($data->query) && !is_object($data->query)) {
            $filters['query'] = trim((string) $data->query);
        }
        if (isset($data->scope_type)) {
            $filters['scope_type'] = (string) $data->scope_type;
        }
        if (isset($data->scope_id)) {
            $filters['scope_id'] = (int) $data->scope_id;
        }
        if (isset($data->climate_zone_id)) {
            $filters['climate_zone_id'] = (int) $data->climate_zone_id;
        }
        if (isset($data->is_active)) {
            $filters['is_active'] = (int) $data->is_active;
        }
        $rows = $this->climateService->listAssignments($filters);
        ResponseEmitter::emit(ApiResponse::json(['dataset' => $rows]));
    }

    public function assignmentUpsert()
    {
        \Core\AuthGuard::api()->requireAbility('settings.manage', [], 'Accesso non autorizzato');
        $row = $this->climateService->upsertAssignment($this->decodePayload());
        ResponseEmitter::emit(ApiResponse::json(['dataset' => $row]));
    }

    public function assignmentDelete()
    {
        \Core\AuthGuard::api()->requireAbility('settings.manage', [], 'Accesso non autorizzato');
        $data = $this->decodePayload();
        $id = (int) ($data->id ?? 0);
        if ($id <= 0) {
            $this->failValidation('Assegnazione non valida', 'climate_assignment_invalid');
        }
        $this->climateService->deleteAssignment($id);
        ResponseEmitter::emit(ApiResponse::json(['success' => true]));
    }

    public function weatherOverrideList()
    {
        \Core\AuthGuard::api()->requireAbility('settings.manage', [], 'Accesso non autorizzato');
        $data = $this->decodePayload();
        $filters = [];
        if (isset($data->query) && !is_array($data->query) && !is_object($data->query)) {
            $filters['query'] = trim((string) $data->query);
        }
        if (isset($data->scope_type)) {
            $filters['scope_type'] = (string) $data->scope_type;
        }
        if (isset($data->scope_id)) {
            $filters['scope_id'] = (int) $data->scope_id;
        }
        if (isset($data->is_active)) {
            $filters['is_active'] = (int) $data->is_active;
        }
        $rows = $this->climateService->listOverrides($filters);
        ResponseEmitter::emit(ApiResponse::json(['dataset' => $rows]));
    }

    public function weatherOverrideUpsert()
    {
        \Core\AuthGuard::api()->requireAbility('settings.manage', [], 'Accesso non autorizzato');
        $userId = (int) \Core\AuthGuard::api()->requireUser();
        $row = $this->climateService->upsertOverride($this->decodePayload(), $userId);
        ResponseEmitter::emit(ApiResponse::json(['dataset' => $row]));
    }

    public function weatherOverrideDelete()
    {
        \Core\AuthGuard::api()->requireAbility('settings.manage', [], 'Accesso non autorizzato');
        $data = $this->decodePayload();
        $id = (int) ($data->id ?? 0);
        if ($id <= 0) {
            $this->failValidation('Forzatura non valida', 'weather_override_invalid');
        }
        $this->climateService->deleteOverride($id);
        ResponseEmitter::emit(ApiResponse::json(['success' => true]));
    }

    public function worldOptions()
    {
        \Core\AuthGuard::api()->requireAbility('settings.manage', [], 'Accesso non autorizzato');
        $options = $this->overrideService->listWorldOptions();
        ResponseEmitter::emit(ApiResponse::json(['dataset' => $options]));
    }

    // -------------------------------------------------------------------------
    // Metodo pubblico mantenuto per compatibilita con chiamanti esistenti
    // -------------------------------------------------------------------------

    public function moonPhases()
    {
        return $this->resolver->resolveGlobal($this->getBaseTemperature())['moon'];
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    private function resolveState(int $locationId, ?int $worldId = null): array
    {
        \Core\AuthGuard::api()->requireCharacter();
        $this->runWeatherMaintenanceIfDue();

        $baseTemperature = $this->getBaseTemperature();
        $renderMode = $this->getWeatherRenderMode();
        $imageBaseUrl = $this->getWeatherImageBaseUrl();

        if ($locationId > 0) {
            return $this->resolver->resolveForLocation(
                $locationId,
                $baseTemperature,
                $renderMode,
                $imageBaseUrl,
                $worldId,
            );
        }

        if ($worldId !== null && $worldId > 0) {
            return $this->resolver->resolveForWorld(
                $worldId,
                $baseTemperature,
                $renderMode,
                $imageBaseUrl,
            );
        }

        return $this->resolver->resolveGlobal(
            $baseTemperature,
            $renderMode,
            $imageBaseUrl,
        );
    }

    private function runWeatherMaintenanceIfDue(): void
    {
        $now = time();
        $last = (int) ($this->getSessionValue('weather_override_maintenance_at') ?? 0);
        if ($last > 0 && ($now - $last) < 300) {
            return;
        }

        $this->overrideService->clearExpiredOverrides();
        SessionStore::set('weather_override_maintenance_at', $now);
    }

    private function getSessionValue($key)
    {
        return SessionStore::get($key);
    }

    private function getBaseTemperature(): int
    {
        $v = $this->getSessionValue('base_temperature');
        if ($v === null || $v === '' || !is_numeric($v)) {
            return 12;
        }
        return (int) $v;
    }

    private function getWeatherRenderMode(): string
    {
        $mode = strtolower(trim((string) $this->getSessionValue('config_weather_render_mode')));
        return ($mode === 'image') ? 'image' : 'animated';
    }

    private function getWeatherImageBaseUrl(): string
    {
        return trim((string) $this->getSessionValue('config_weather_image_base_url'));
    }

    private function requireWeatherStaff($scope = 'location')
    {
        if ($scope === 'global') {
            \Core\AuthGuard::api()->requireAbility('settings.manage', [], 'Accesso non autorizzato');
            return;
        }
        \Core\AuthGuard::api()->requireStaff('Accesso non autorizzato');
    }

    private function ensureLocationExists(int $locationId)
    {
        if (!$this->overrideService->locationExists($locationId)) {
            $this->failNotFound('Luogo non trovato');
        }
    }

    private function decodePayload()
    {
        $request = RequestData::fromGlobals();
        return InputValidator::postJsonObject($request, 'data', true);
    }

    private function normalizeWeatherKey($key)
    {
        if ($key === null) {
            return null;
        }
        $key = trim(strtolower((string) $key));
        if ($key === '' || $key === 'inherit' || $key === 'auto') {
            return null;
        }
        if ($this->gen->getConditionByKey($key) === null) {
            if ($this->climateService->isAvailable()) {
                $type = $this->climateService->getWeatherTypeBySlug($key);
                if (!empty($type)) {
                    return $key;
                }
            }
            $this->failValidation('Meteo non valido');
        }
        return $key;
    }

    private function normalizeMoonPhase($phase)
    {
        if ($phase === null) {
            return null;
        }
        $phase = trim(strtolower((string) $phase));
        if ($phase === '' || $phase === 'inherit' || $phase === 'auto') {
            return null;
        }
        if ($this->gen->getMoonPhaseByPhase($phase) === null) {
            $this->failValidation('Fase lunare non valida');
        }
        return $phase;
    }

    private function normalizeDegrees($degrees)
    {
        if ($degrees === null) {
            return null;
        }
        if (is_string($degrees)) {
            $degrees = trim($degrees);
            if ($degrees === '' || strtolower($degrees) === 'auto' || strtolower($degrees) === 'inherit') {
                return null;
            }
        }
        if (!is_numeric($degrees)) {
            $this->failValidation('Temperatura non valida');
        }
        $value = (int) $degrees;
        if ($value < -80 || $value > 80) {
            $this->failValidation('Temperatura fuori limite');
        }
        return $value;
    }

    private function normalizeExpiresAt($expiresAt): ?string
    {
        if ($expiresAt === null || trim((string) $expiresAt) === '') {
            return null;
        }
        $ts = strtotime((string) $expiresAt);
        if ($ts === false || $ts <= 0) {
            return null;
        }
        return date('Y-m-d H:i:s', $ts);
    }

    private function normalizeWorldId($worldId): int
    {
        if (!is_numeric($worldId)) {
            $this->failValidation('Mondo non valido', 'world_invalid');
        }
        $value = (int) $worldId;
        if ($value <= 0) {
            $this->failValidation('Mondo non valido', 'world_invalid');
        }
        return $value;
    }

    private function failValidation($message, string $errorCode = '')
    {
        $message = (string) $message;
        if ($errorCode === '') {
            $errorCode = $this->resolveValidationErrorCode($message);
        }
        throw AppError::validation($message, [], $errorCode);
    }

    private function failNotFound($message = 'Risorsa non trovata', string $errorCode = '')
    {
        $message = (string) $message;
        if ($errorCode === '') {
            $errorCode = $this->resolveNotFoundErrorCode($message);
        }
        throw AppError::notFound($message, [], $errorCode);
    }

    private function resolveValidationErrorCode(string $message): string
    {
        $map = [
            'Luogo non valido' => 'location_invalid',
            'Mondo non valido' => 'world_invalid',
            'Meteo non valido' => 'weather_invalid',
            'Fase lunare non valida' => 'moon_phase_invalid',
            'Temperatura non valida' => 'temperature_invalid',
            'Temperatura fuori limite' => 'temperature_out_of_range',
        ];
        return $map[$message] ?? 'validation_error';
    }

    private function resolveNotFoundErrorCode(string $message): string
    {
        $map = [
            'Luogo non trovato' => 'location_not_found',
        ];
        return $map[$message] ?? 'not_found';
    }
}
