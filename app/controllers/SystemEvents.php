<?php

declare(strict_types=1);

use App\Services\SystemEventService;
use Core\AuthGuard;
use Core\Http\ApiResponse;
use Core\Http\InputValidator;
use Core\Http\RequestData;
use Core\Http\ResponseEmitter;

class SystemEvents
{
    /** @var SystemEventService|null */
    private $service = null;

    public function setService(SystemEventService $service = null)
    {
        $this->service = $service;
        return $this;
    }

    private function service(): SystemEventService
    {
        if ($this->service instanceof SystemEventService) {
            return $this->service;
        }
        $this->service = new SystemEventService();
        return $this->service;
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
        return (int) AuthGuard::api()->requireCharacter();
    }

    private function viewerIsStaff(): bool
    {
        return \Core\AppContext::authContext()->isStaff();
    }

    public function list($echo = true)
    {
        $viewerCharacterId = $this->requireCharacter();
        \Core\AuthGuard::releaseSession();
        $data = $this->requestDataObject();

        $filters = [
            'status' => InputValidator::string($data, 'status', ''),
            'type' => InputValidator::string($data, 'type', ''),
            'scope_type' => InputValidator::string($data, 'scope_type', ''),
            'scope_id' => InputValidator::integer($data, 'scope_id', 0),
            'participant_mode' => InputValidator::string($data, 'participant_mode', ''),
            'tag_ids' => InputValidator::arrayOfValues(
                $data,
                'tag_ids',
                InputValidator::arrayOfValues($data, 'tag_id', []),
            ),
        ];
        $limit = max(1, min(100, InputValidator::integer($data, 'limit', 20)));
        $page = max(1, InputValidator::integer($data, 'page', 1));

        $viewerFactionIds = $this->service()->viewerFactionIds($viewerCharacterId);
        $dataset = $this->service()->listForGame(
            $filters,
            $viewerCharacterId,
            $this->viewerIsStaff(),
            $viewerFactionIds,
            $limit,
            $page,
        );
        $response = ['dataset' => $dataset];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function get($echo = true)
    {
        $viewerCharacterId = $this->requireCharacter();
        $data = $this->requestDataObject();
        $eventId = InputValidator::integer($data, 'event_id', 0);
        if ($eventId <= 0) {
            $eventId = InputValidator::positiveInt($data, 'id', 'Evento non valido', 'system_event_not_found');
        }

        $viewerFactionIds = $this->service()->viewerFactionIds($viewerCharacterId);
        $dataset = $this->service()->getForGame(
            $eventId,
            $viewerCharacterId,
            $this->viewerIsStaff(),
            $viewerFactionIds,
        );
        $response = ['dataset' => $dataset];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function participationJoin($echo = true)
    {
        $viewerCharacterId = $this->requireCharacter();
        $data = $this->requestDataObject();
        $eventId = InputValidator::integer($data, 'event_id', 0);
        if ($eventId <= 0) {
            $eventId = InputValidator::positiveInt($data, 'id', 'Evento non valido', 'system_event_not_found');
        }

        $dataset = $this->service()->joinParticipation(
            $eventId,
            (array) $data,
            $viewerCharacterId,
            $this->viewerIsStaff(),
        );
        $response = ['dataset' => $dataset];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function participationLeave($echo = true)
    {
        $viewerCharacterId = $this->requireCharacter();
        $data = $this->requestDataObject();
        $eventId = InputValidator::integer($data, 'event_id', 0);
        if ($eventId <= 0) {
            $eventId = InputValidator::positiveInt($data, 'id', 'Evento non valido', 'system_event_not_found');
        }

        $dataset = $this->service()->leaveParticipation(
            $eventId,
            (array) $data,
            $viewerCharacterId,
            $this->viewerIsStaff(),
        );
        $response = ['dataset' => $dataset];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }
}
