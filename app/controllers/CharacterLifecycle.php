<?php

declare(strict_types=1);

use App\Models\LifecyclePhaseDefinition;
use App\Services\LifecycleService;
use Core\Http\ApiResponse;
use Core\Http\AppError;
use Core\Http\InputValidator;
use Core\Http\RequestData;
use Core\Http\ResponseEmitter;

use Core\Logging\LegacyLoggerAdapter;
use Core\Logging\LoggerInterface;

class CharacterLifecycle extends LifecyclePhaseDefinition
{
    /** @var LoggerInterface|null */
    private $logger = null;
    /** @var LifecycleService|null */
    private $lifecycleService = null;

    public function setLogger(LoggerInterface $logger = null)
    {
        $this->logger = $logger;
        return $this;
    }

    public function setLifecycleService(LifecycleService $service = null)
    {
        $this->lifecycleService = $service;
        return $this;
    }

    private function logger(): LoggerInterface
    {
        if ($this->logger instanceof LoggerInterface) {
            return $this->logger;
        }
        $this->logger = new LegacyLoggerAdapter();
        return $this->logger;
    }

    protected function trace($message, $context = false): void
    {
        $this->logger()->trace($message, $context);
    }

    private function lifecycleService(): LifecycleService
    {
        if ($this->lifecycleService instanceof LifecycleService) {
            return $this->lifecycleService;
        }
        $this->lifecycleService = new LifecycleService();
        return $this->lifecycleService;
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

    // -------------------------------------------------------------------------
    // Game-facing endpoints
    // -------------------------------------------------------------------------

    public function currentPhase($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $characterId = $this->requireCharacter();

        $phase = $this->lifecycleService()->getCurrentPhase($characterId);
        $response = ['dataset' => $phase];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function history($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $characterId = $this->requireCharacter();

        $data = $this->requestDataObject();
        $limit = max(1, min(50, InputValidator::integer($data, 'limit', 20)));

        $rows = $this->lifecycleService()->getHistory($characterId, $limit);
        $response = ['dataset' => $rows];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    // -------------------------------------------------------------------------
    // Admin — phase definition management
    // -------------------------------------------------------------------------

    public function adminPhaseList($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();

        $data = $this->requestDataObject();
        $includeInactive = !property_exists($data, 'include_inactive')
            || InputValidator::integer($data, 'include_inactive', 1) === 1;

        $rows = $this->lifecycleService()->adminPhaseList($includeInactive);
        $response = ['dataset' => $rows];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function adminPhaseCreate($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();

        $phase = $this->lifecycleService()->adminPhaseCreate($this->requestDataObject());
        $response = ['dataset' => $phase];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function adminPhaseUpdate($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();

        $phase = $this->lifecycleService()->adminPhaseUpdate($this->requestDataObject());
        $response = ['dataset' => $phase];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function adminPhaseDelete($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();

        $data = $this->requestDataObject();
        $id = InputValidator::integer($data, 'id', 0);
        if ($id <= 0) {
            $id = InputValidator::integer($data, 'phase_id', 0);
        }

        $this->lifecycleService()->adminPhaseDelete($id);
        $response = ['status' => 'ok'];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    // -------------------------------------------------------------------------
    // Admin — character lifecycle management
    // -------------------------------------------------------------------------

    public function adminCurrentPhase($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();

        $data = $this->requestDataObject();
        $characterId = InputValidator::integer($data, 'character_id', 0);

        if ($characterId <= 0) {
            throw AppError::validation('ID personaggio obbligatorio', [], 'character_id_required');
        }

        $phase = $this->lifecycleService()->getCurrentPhase($characterId);
        $response = ['dataset' => $phase];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function adminHistory($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();

        $data = $this->requestDataObject();
        $characterId = InputValidator::integer($data, 'character_id', 0);
        $limit = max(1, min(200, InputValidator::integer($data, 'limit', 50)));

        if ($characterId <= 0) {
            throw AppError::validation('ID personaggio obbligatorio', [], 'character_id_required');
        }

        $rows = $this->lifecycleService()->getHistory($characterId, $limit);
        $response = ['dataset' => $rows];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function adminTransition($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();

        $data = $this->requestDataObject();
        $session = \Core\AuthGuard::api()->requireCharacter();

        $result = $this->lifecycleService()->applyTransition([
            'character_id' => InputValidator::integer($data, 'character_id', 0),
            'to_phase_id' => InputValidator::integer($data, 'to_phase_id', 0),
            'to_phase_code' => InputValidator::string($data, 'to_phase_code', ''),
            'triggered_by' => InputValidator::string($data, 'triggered_by', 'admin'),
            'triggered_by_event_id' => InputValidator::integer($data, 'triggered_by_event_id', 0),
            'skip_narrative_event' => InputValidator::integer($data, 'skip_narrative_event', 0),
            'notes' => InputValidator::string($data, 'notes', ''),
            'applied_by' => (int) $session,
            'meta_json' => isset($data->meta_json) ? (array) $data->meta_json : [],
        ]);

        $response = ['dataset' => $result];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }
}
