<?php

declare(strict_types=1);

use App\Services\NarrativeCapabilityService;
use App\Services\NarrativeEphemeralNpcService;
use Core\Http\ApiResponse;
use Core\Http\AppError;
use Core\Http\InputValidator;
use Core\Http\RequestData;
use Core\Http\ResponseEmitter;

use Core\Logging\LoggerInterface;

class NarrativeEphemeralNpcs
{
    /** @var LoggerInterface|null */
    private $logger = null;
    /** @var NarrativeEphemeralNpcService|null */
    private $npcService = null;

    private function logger(): LoggerInterface
    {
        if ($this->logger instanceof LoggerInterface) {
            return $this->logger;
        }
        $this->logger = \Core\AppContext::logger();
        return $this->logger;
    }

    private function npcService(): NarrativeEphemeralNpcService
    {
        if ($this->npcService instanceof NarrativeEphemeralNpcService) {
            return $this->npcService;
        }
        $this->npcService = new NarrativeEphemeralNpcService();
        return $this->npcService;
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

    private function isNarrativeActor(): bool
    {
        return \Core\AppContext::authContext()->isStaff() || \Core\AuthGuard::isSuperuser();
    }

    // -------------------------------------------------------------------------
    // Endpoints
    // -------------------------------------------------------------------------

    public function spawn($echo = true)
    {
        $this->logger()->trace('Richiamato il metodo: ' . __METHOD__);
        $characterId = $this->requireCharacter();
        \Core\AuthGuard::releaseSession();

        // Staff e Superuser possono spawnare sempre; i delegati necessitano del grant
        if (!$this->isNarrativeActor()) {
            $capSvc = new NarrativeCapabilityService();
            $capSvc->requireActor($characterId, 'narrative.npc.spawn');
        }

        $data = $this->requestDataObject();
        $eventId = (int) ($data->event_id ?? 0);

        if ($eventId <= 0) {
            throw AppError::validation('event_id obbligatorio.', [], 'event_id_required');
        }

        $params = [
            'name' => trim((string) ($data->name ?? '')),
            'description' => trim((string) ($data->description ?? '')),
            'image' => trim((string) ($data->image ?? '')),
            'location_id' => (int) ($data->location_id ?? 0),
            'created_by' => $characterId,
        ];

        $npc = $this->npcService()->spawn($eventId, $params);
        $response = ['dataset' => $npc, 'message' => 'PNG Effimero spawnato con successo.'];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function list($echo = true)
    {
        $this->logger()->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireCharacter();
        \Core\AuthGuard::releaseSession();

        $data = $this->requestDataObject();
        $eventId = (int) ($data->event_id ?? 0);
        $locationId = (int) ($data->location_id ?? 0);

        if ($eventId > 0) {
            $npcs = $this->npcService()->listForEvent($eventId);
        } elseif ($locationId > 0) {
            $npcs = $this->npcService()->listForLocation($locationId);
        } else {
            $npcs = [];
        }

        $response = ['dataset' => $npcs];
        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function delete($echo = true)
    {
        $this->logger()->trace('Richiamato il metodo: ' . __METHOD__);
        $characterId = $this->requireCharacter();
        \Core\AuthGuard::releaseSession();

        $data = $this->requestDataObject();
        $npcId = (int) ($data->id ?? $data->npc_id ?? 0);

        if ($npcId <= 0) {
            throw AppError::validation('id obbligatorio.', [], 'npc_id_required');
        }

        $this->npcService()->delete($npcId, $characterId, $this->isNarrativeActor());
        $response = ['status' => 'ok', 'message' => 'PNG Effimero rimosso.'];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }
}


