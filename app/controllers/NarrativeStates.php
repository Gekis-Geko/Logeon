<?php

declare(strict_types=1);

use App\Models\NarrativeState;
use App\Services\NarrativeStateApplicationService;
use App\Services\NarrativeStateService;
use Core\Http\ApiResponse;
use Core\Http\AppError;
use Core\Http\InputValidator;
use Core\Http\RequestData;

use Core\Http\ResponseEmitter;

use Core\Logging\LoggerInterface;

class NarrativeStates extends NarrativeState
{
    /** @var LoggerInterface|null */
    private $logger = null;
    /** @var NarrativeStateService|null */
    private $narrativeStateService = null;
    /** @var NarrativeStateApplicationService|null */
    private $narrativeStateApplicationService = null;

    public function setLogger(LoggerInterface $logger = null)
    {
        $this->logger = $logger;
        return $this;
    }

    public function setNarrativeStateService(NarrativeStateService $service = null)
    {
        $this->narrativeStateService = $service;
        return $this;
    }

    public function setNarrativeStateApplicationService(NarrativeStateApplicationService $service = null)
    {
        $this->narrativeStateApplicationService = $service;
        return $this;
    }

    private function logger(): LoggerInterface
    {
        if ($this->logger instanceof LoggerInterface) {
            return $this->logger;
        }

        $this->logger = \Core\AppContext::logger();
        return $this->logger;
    }

    protected function trace($message, $context = false): void
    {
        $this->logger()->trace($message, $context);
    }

    private function narrativeStateService(): NarrativeStateService
    {
        if ($this->narrativeStateService instanceof NarrativeStateService) {
            return $this->narrativeStateService;
        }

        $this->narrativeStateService = new NarrativeStateService();
        return $this->narrativeStateService;
    }

    private function narrativeStateApplicationService(): NarrativeStateApplicationService
    {
        if ($this->narrativeStateApplicationService instanceof NarrativeStateApplicationService) {
            return $this->narrativeStateApplicationService;
        }

        $this->narrativeStateApplicationService = new NarrativeStateApplicationService();
        return $this->narrativeStateApplicationService;
    }

    private function requestDataObject()
    {
        $request = RequestData::fromGlobals();
        return InputValidator::postJsonObject($request, 'data', true);
    }

    private function emitJson(array $payload): void
    {
        ResponseEmitter::emit(ApiResponse::json($payload));
    }

    private function requireCharacter(): int
    {
        return (int) \Core\AuthGuard::api()->requireCharacter();
    }

    private function requireAdmin(): void
    {
        \Core\AuthGuard::api()->requireAbility('settings.manage');
    }

    private function ensureLocationAccess(int $locationId, int $characterId): void
    {
        if ($locationId <= 0) {
            return;
        }

        $access = (new Locations())->canAccess($locationId, $characterId);
        if (empty($access['allowed'])) {
            throw AppError::validation('Accesso non consentito alla location', [], 'location_access_denied');
        }
    }

    public function catalog($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireCharacter();

        $data = $this->requestDataObject();
        $includeHidden = false;
        if (\Core\AppContext::authContext()->isStaff() && isset($data->include_hidden) && (int) $data->include_hidden === 1) {
            $includeHidden = true;
        }

        $dataset = $this->narrativeStateService()->catalog($includeHidden);
        $response = ['dataset' => $dataset];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function apply($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $characterId = $this->requireCharacter();
        $data = $this->requestDataObject();

        $sceneId = (int) ($data->scene_id ?? $data->location_id ?? 0);
        $targetType = strtolower(trim((string) ($data->target_type ?? 'character')));
        if ($targetType === 'scene' && $sceneId > 0) {
            $this->ensureLocationAccess($sceneId, $characterId);
        }

        $result = $this->narrativeStateApplicationService()->applyState([
            'state_id' => (int) ($data->state_id ?? 0),
            'state_code' => (string) ($data->state_code ?? $data->state ?? ''),
            'target_type' => $targetType,
            'target_id' => (int) ($data->target_id ?? 0),
            'scene_id' => $sceneId,
            'applier_character_id' => $characterId,
            'source_ability_id' => (int) ($data->source_ability_id ?? 0),
            'intensity' => $data->intensity ?? null,
            'duration_value' => $data->duration_value ?? null,
            'duration_unit' => $data->duration_unit ?? null,
            'meta_json' => isset($data->meta_json) ? (string) $data->meta_json : '{}',
        ]);

        $response = ['dataset' => $result];
        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function remove($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $characterId = $this->requireCharacter();
        $data = $this->requestDataObject();

        $sceneId = (int) ($data->scene_id ?? $data->location_id ?? 0);
        $targetType = strtolower(trim((string) ($data->target_type ?? 'character')));
        if ($targetType === 'scene' && $sceneId > 0) {
            $this->ensureLocationAccess($sceneId, $characterId);
        }

        $result = $this->narrativeStateApplicationService()->removeState([
            'applied_state_id' => (int) ($data->applied_state_id ?? 0),
            'state_id' => (int) ($data->state_id ?? 0),
            'state_code' => (string) ($data->state_code ?? $data->state ?? ''),
            'target_type' => $targetType,
            'target_id' => (int) ($data->target_id ?? 0),
            'scene_id' => $sceneId,
            'reason' => (string) ($data->reason ?? 'manual_remove'),
        ]);

        $response = ['dataset' => $result];
        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function adminList($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();

        $data = $this->requestDataObject();
        $query = [];
        if (isset($data->query)) {
            if (is_object($data->query)) {
                $query = (array) $data->query;
            } elseif (is_array($data->query)) {
                $query = $data->query;
            }
        }

        $source = !empty($query) ? (object) $query : $data;
        $includeInactive = !isset($source->include_inactive) || (int) $source->include_inactive === 1;
        $dataset = $this->narrativeStateService()->adminList($includeInactive, [
            'search' => (string) ($source->search ?? ''),
            'category' => (string) ($source->category ?? ''),
            'scope' => (string) ($source->scope ?? ''),
            'stack_mode' => (string) ($source->stack_mode ?? ''),
            'visible_to_players' => isset($source->visible_to_players) ? $source->visible_to_players : '',
        ]);
        $response = ['dataset' => $dataset];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function create($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();

        $this->narrativeStateService()->adminCreate($this->requestDataObject());
        $response = ['status' => 'ok'];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function update($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();

        $this->narrativeStateService()->adminUpdate($this->requestDataObject());
        $response = ['status' => 'ok'];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function myStates($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $characterId = $this->requireCharacter();
        $data = $this->requestDataObject();

        $targetId = $characterId;
        if (\Core\AppContext::authContext()->isStaff() && isset($data->character_id) && (int) $data->character_id > 0) {
            $targetId = (int) $data->character_id;
        }

        $result = $this->narrativeStateApplicationService()->getActiveForCharacter($targetId);
        $response = ['dataset' => $result];
        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function adminDelete($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();

        $data = $this->requestDataObject();
        $stateId = (int) ($data->id ?? $data->state_id ?? 0);
        $this->narrativeStateService()->adminDelete($stateId);

        $response = ['status' => 'ok'];
        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }
}


