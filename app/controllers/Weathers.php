<?php

declare(strict_types=1);

use App\Contracts\WeatherProviderInterface;
use App\Services\WeatherProviderRegistry;
use Core\Http\ApiResponse;
use Core\Http\AppError;
use Core\Http\InputValidator;
use Core\Http\RequestData;
use Core\Http\ResponseEmitter;
use Core\SessionStore;

class Weathers
{
    /** @var WeatherProviderInterface|null */
    private $weatherProvider = null;

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

    public function worldOptions()
    {
        \Core\AuthGuard::api()->requireAbility('settings.manage', [], 'Accesso non autorizzato');
        $options = $this->weatherProvider()->worldOptions();
        ResponseEmitter::emit(ApiResponse::json(['dataset' => $options]));
    }

    public function moonPhases()
    {
        return $this->weatherProvider()->moonPhases($this->getBaseTemperature());
    }

    /**
     * @return array<string,mixed>
     */
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
        $last = (int) (SessionStore::get('weather_override_maintenance_at') ?? 0);
        if ($last > 0 && ($now - $last) < 300) {
            return;
        }

        $this->weatherProvider()->clearExpiredOverrides();
        SessionStore::set('weather_override_maintenance_at', $now);
    }

    private function weatherProvider(): WeatherProviderInterface
    {
        if ($this->weatherProvider instanceof WeatherProviderInterface) {
            return $this->weatherProvider;
        }

        $this->weatherProvider = WeatherProviderRegistry::provider();
        return $this->weatherProvider;
    }

    private function getBaseTemperature(): int
    {
        $value = SessionStore::get('base_temperature');
        if ($value === null || $value === '' || !is_numeric($value)) {
            return 12;
        }
        return (int) $value;
    }

    private function getWeatherRenderMode(): string
    {
        $mode = strtolower(trim((string) SessionStore::get('config_weather_render_mode')));
        return ($mode === 'image') ? 'image' : 'animated';
    }

    private function getWeatherImageBaseUrl(): string
    {
        return trim((string) SessionStore::get('config_weather_image_base_url'));
    }

    private function requireWeatherStaff(string $scope = 'location'): void
    {
        if ($scope === 'global') {
            \Core\AuthGuard::api()->requireAbility('settings.manage', [], 'Accesso non autorizzato');
            return;
        }
        \Core\AuthGuard::api()->requireStaff('Accesso non autorizzato');
    }

    private function ensureLocationExists(int $locationId): void
    {
        if (!$this->weatherProvider()->locationExists($locationId)) {
            $this->failNotFound('Luogo non trovato');
        }
    }

    private function decodePayload(): object
    {
        return InputValidator::postJsonObject(RequestData::fromGlobals(), 'data', true);
    }

    private function normalizeWeatherKey($key): ?string
    {
        if ($key === null) {
            return null;
        }
        $normalized = trim(strtolower((string) $key));
        if ($normalized === '' || $normalized === 'inherit' || $normalized === 'auto') {
            return null;
        }
        if ($this->weatherProvider()->getConditionByKey($normalized) === null) {
            if ($this->weatherProvider()->isClimateAvailable()) {
                $type = $this->weatherProvider()->getWeatherTypeBySlug($normalized);
                if (!empty($type)) {
                    return $normalized;
                }
            }
            $this->failValidation('Meteo non valido');
        }
        return $normalized;
    }

    private function normalizeMoonPhase($phase): ?string
    {
        if ($phase === null) {
            return null;
        }
        $normalized = trim(strtolower((string) $phase));
        if ($normalized === '' || $normalized === 'inherit' || $normalized === 'auto') {
            return null;
        }
        if ($this->weatherProvider()->getMoonPhaseByPhase($normalized) === null) {
            $this->failValidation('Fase lunare non valida');
        }
        return $normalized;
    }

    private function normalizeDegrees($degrees): ?int
    {
        if ($degrees === null) {
            return null;
        }
        if (is_string($degrees)) {
            $degrees = trim($degrees);
            $lower = strtolower($degrees);
            if ($degrees === '' || $lower === 'auto' || $lower === 'inherit') {
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

    private function failValidation($message, string $errorCode = ''): void
    {
        $text = (string) $message;
        if ($errorCode === '') {
            $errorCode = $this->resolveValidationErrorCode($text);
        }
        throw AppError::validation($text, [], $errorCode);
    }

    private function failNotFound($message = 'Risorsa non trovata', string $errorCode = ''): void
    {
        $text = (string) $message;
        if ($errorCode === '') {
            $errorCode = $this->resolveNotFoundErrorCode($text);
        }
        throw AppError::notFound($text, [], $errorCode);
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

