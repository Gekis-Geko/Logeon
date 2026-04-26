<?php

declare(strict_types=1);

namespace Modules\Logeon\Weather\Controllers;

use App\Contracts\WeatherAdvancedInterface;
use Modules\Logeon\Weather\Services\WeatherProviderRegistry;
use Core\Http\ApiResponse;
use Core\Http\AppError;
use Core\Http\InputValidator;
use Core\Http\RequestData;

use Core\Http\ResponseEmitter;
use Core\SessionStore;

class Weathers
{
    /** @var WeatherAdvancedInterface|null */
    private $weatherProvider = null;

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
        ResponseEmitter::emit(ApiResponse::json($this->weatherProvider()->options()));
    }

    public function setGlobal()
    {
        $this->requireWeatherStaff('global');
        $data = $this->decodePayload();

        $weatherKey = $this->normalizeWeatherKey($data->weather_key ?? null);
        $degrees = $this->normalizeDegrees($data->degrees ?? null);
        $moonPhase = $this->normalizeMoonPhase($data->moon_phase ?? null);

        $this->weatherProvider()->saveGlobalOverride($weatherKey, $degrees, $moonPhase);

        ResponseEmitter::emit(ApiResponse::json([
            'dataset' => $this->resolveState(0),
        ]));
    }

    public function clearGlobal()
    {
        $this->requireWeatherStaff('global');
        $this->weatherProvider()->saveGlobalOverride(null, null, null);

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

        $this->weatherProvider()->saveWorldOverride($worldId, $weatherKey, $degrees, $moonPhase);

        ResponseEmitter::emit(ApiResponse::json([
            'dataset' => $this->resolveState(0, $worldId),
        ]));
    }

    public function clearWorld()
    {
        $this->requireWeatherStaff('global');
        $data = $this->decodePayload();

        $worldId = $this->normalizeWorldId($data->world_id ?? 0);
        $this->weatherProvider()->clearWorldOverride($worldId);

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
            $this->weatherProvider()->deleteLocationOverride($locationId);
        } else {
            $this->weatherProvider()->upsertLocationOverride(
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

        $this->weatherProvider()->deleteLocationOverride($locationId);

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
        $areas = $this->weatherProvider()->listClimateAreas($filters);

        ResponseEmitter::emit(ApiResponse::json(['dataset' => $areas]));
    }

    public function climateAreaCreate()
    {
        \Core\AuthGuard::api()->requireAbility('settings.manage', [], 'Accesso non autorizzato');
        $data = $this->decodePayload();

        $area = $this->weatherProvider()->createClimateArea($data);

        ResponseEmitter::emit(ApiResponse::json(['dataset' => $area]));
    }

    public function climateAreaUpdate()
    {
        \Core\AuthGuard::api()->requireAbility('settings.manage', [], 'Accesso non autorizzato');
        $data = $this->decodePayload();

        $area = $this->weatherProvider()->updateClimateArea($data);

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

        $this->weatherProvider()->deleteClimateArea($id);

        ResponseEmitter::emit(ApiResponse::json(['success' => true]));
    }

    public function climateAreaAssign()
    {
        \Core\AuthGuard::api()->requireAbility('settings.manage', [], 'Accesso non autorizzato');
        $data = $this->decodePayload();

        $locationId = (int) ($data->location_id ?? 0);
        $climateAreaId = isset($data->climate_area_id) && $data->climate_area_id !== ''
            ? (int) $data->climate_area_id
            : null;

        if ($locationId <= 0) {
            $this->failValidation('Luogo non valido');
        }

        $this->weatherProvider()->assignLocationToClimateArea($locationId, $climateAreaId);

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
        $rows = $this->weatherProvider()->listWeatherTypes($filters);
        ResponseEmitter::emit(ApiResponse::json(['dataset' => $rows]));
    }

    public function weatherTypeCreate()
    {
        \Core\AuthGuard::api()->requireAbility('settings.manage', [], 'Accesso non autorizzato');
        $data = $this->decodePayload();
        $row = $this->weatherProvider()->createWeatherType($data);
        ResponseEmitter::emit(ApiResponse::json(['dataset' => $row]));
    }

    public function weatherTypeUpdate()
    {
        \Core\AuthGuard::api()->requireAbility('settings.manage', [], 'Accesso non autorizzato');
        $data = $this->decodePayload();
        $row = $this->weatherProvider()->updateWeatherType($data);
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
        $this->weatherProvider()->deleteWeatherType($id);
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
        $rows = $this->weatherProvider()->listSeasons($filters);
        ResponseEmitter::emit(ApiResponse::json(['dataset' => $rows]));
    }

    public function seasonCreate()
    {
        \Core\AuthGuard::api()->requireAbility('settings.manage', [], 'Accesso non autorizzato');
        $row = $this->weatherProvider()->createSeason($this->decodePayload());
        ResponseEmitter::emit(ApiResponse::json(['dataset' => $row]));
    }

    public function seasonUpdate()
    {
        \Core\AuthGuard::api()->requireAbility('settings.manage', [], 'Accesso non autorizzato');
        $row = $this->weatherProvider()->updateSeason($this->decodePayload());
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
        $this->weatherProvider()->deleteSeason($id);
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
        $rows = $this->weatherProvider()->listClimateZones($filters);
        ResponseEmitter::emit(ApiResponse::json(['dataset' => $rows]));
    }

    public function climateZoneCreate()
    {
        \Core\AuthGuard::api()->requireAbility('settings.manage', [], 'Accesso non autorizzato');
        $row = $this->weatherProvider()->createClimateZone($this->decodePayload());
        ResponseEmitter::emit(ApiResponse::json(['dataset' => $row]));
    }

    public function climateZoneUpdate()
    {
        \Core\AuthGuard::api()->requireAbility('settings.manage', [], 'Accesso non autorizzato');
        $row = $this->weatherProvider()->updateClimateZone($this->decodePayload());
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
        $this->weatherProvider()->deleteClimateZone($id);
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
        $rows = $this->weatherProvider()->listSeasonProfiles($filters);
        ResponseEmitter::emit(ApiResponse::json(['dataset' => $rows]));
    }

    public function profileUpsert()
    {
        \Core\AuthGuard::api()->requireAbility('settings.manage', [], 'Accesso non autorizzato');
        $row = $this->weatherProvider()->upsertSeasonProfile($this->decodePayload());
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
        $this->weatherProvider()->deleteSeasonProfile($id);
        ResponseEmitter::emit(ApiResponse::json(['success' => true]));
    }

    public function profileWeightsList()
    {
        \Core\AuthGuard::api()->requireAbility('settings.manage', [], 'Accesso non autorizzato');
        $data = $this->decodePayload();
        $profileId = (int) ($data->profile_id ?? 0);
        $rows = $this->weatherProvider()->listProfileWeights($profileId);
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
        $rows = $this->weatherProvider()->syncProfileWeights($profileId, $weights);
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
        $rows = $this->weatherProvider()->listAssignments($filters);
        ResponseEmitter::emit(ApiResponse::json(['dataset' => $rows]));
    }

    public function assignmentUpsert()
    {
        \Core\AuthGuard::api()->requireAbility('settings.manage', [], 'Accesso non autorizzato');
        $row = $this->weatherProvider()->upsertAssignment($this->decodePayload());
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
        $this->weatherProvider()->deleteAssignment($id);
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
        $rows = $this->weatherProvider()->listOverrides($filters);
        ResponseEmitter::emit(ApiResponse::json(['dataset' => $rows]));
    }

    public function weatherOverrideUpsert()
    {
        \Core\AuthGuard::api()->requireAbility('settings.manage', [], 'Accesso non autorizzato');
        $userId = (int) \Core\AuthGuard::api()->requireUser();
        $row = $this->weatherProvider()->upsertOverride($this->decodePayload(), $userId);
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
        $this->weatherProvider()->deleteOverride($id);
        ResponseEmitter::emit(ApiResponse::json(['success' => true]));
    }

    public function worldOptions()
    {
        \Core\AuthGuard::api()->requireAbility('settings.manage', [], 'Accesso non autorizzato');
        $options = $this->weatherProvider()->worldOptions();
        ResponseEmitter::emit(ApiResponse::json(['dataset' => $options]));
    }

    // -------------------------------------------------------------------------
    // Metodo pubblico mantenuto per compatibilita con chiamanti esistenti
    // -------------------------------------------------------------------------

    public function moonPhases()
    {
        return $this->weatherProvider()->moonPhases($this->getBaseTemperature());
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

        return $this->weatherProvider()->resolveState(
            $locationId,
            $worldId,
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

        $this->weatherProvider()->clearExpiredOverrides();
        SessionStore::set('weather_override_maintenance_at', $now);
    }

    private function weatherProvider(): WeatherAdvancedInterface
    {
        if ($this->weatherProvider instanceof WeatherAdvancedInterface) {
            return $this->weatherProvider;
        }

        $this->weatherProvider = WeatherProviderRegistry::provider();
        return $this->weatherProvider;
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
        if (!$this->weatherProvider()->locationExists($locationId)) {
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
        if ($this->weatherProvider()->getConditionByKey($key) === null) {
            if ($this->weatherProvider()->isClimateAvailable()) {
                $type = $this->weatherProvider()->getWeatherTypeBySlug($key);
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
        if ($this->weatherProvider()->getMoonPhaseByPhase($phase) === null) {
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
