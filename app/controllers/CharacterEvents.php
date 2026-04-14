<?php

declare(strict_types=1);

use App\Models\CharacterEvent;
use App\Services\CharacterEventService;
use Core\Http\ApiResponse;
use Core\Http\AppError;
use Core\Http\InputValidator;
use Core\Http\RequestData;
use Core\Http\ResponseEmitter;

use Core\Logging\LegacyLoggerAdapter;
use Core\Logging\LoggerInterface;

class CharacterEvents extends CharacterEvent
{
    /** @var LoggerInterface|null */
    private $logger = null;
    /** @var CharacterEventService|null */
    private $eventService = null;

    public function setLogger(LoggerInterface $logger = null)
    {
        $this->logger = $logger;
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

    public function setEventService(CharacterEventService $service = null)
    {
        $this->eventService = $service;
        return $this;
    }

    private function eventService(): CharacterEventService
    {
        if ($this->eventService instanceof CharacterEventService) {
            return $this->eventService;
        }

        $this->eventService = new CharacterEventService();
        return $this->eventService;
    }

    protected function trace($message, $context = false): void
    {
        $this->logger()->trace($message, $context);
    }

    private function failValidation($message, string $errorCode = 'validation_error')
    {
        throw AppError::validation((string) $message, [], $errorCode);
    }

    private function failUnauthorized($message = 'Operazione non autorizzata', string $errorCode = 'unauthorized')
    {
        throw AppError::unauthorized((string) $message, [], $errorCode);
    }

    private function failNotFound($message = 'Risorsa non trovata', string $errorCode = 'not_found')
    {
        throw AppError::notFound((string) $message, [], $errorCode);
    }

    private function requestDataObject()
    {
        $request = RequestData::fromGlobals();
        return InputValidator::postJsonObject($request, 'data', true);
    }

    private function requireCharacter()
    {
        $guard = \Core\AuthGuard::api();
        $guard->requireUser();
        return $guard->requireCharacter();
    }

    private function isStaff()
    {
        return \Core\AppContext::authContext()->isStaff();
    }

    private function canManage($character_id, $currentCharacterId = null)
    {
        if ($this->isStaff()) {
            return true;
        }
        if ($currentCharacterId === null) {
            $currentCharacterId = $this->requireCharacter();
        }
        return ((int) $character_id === (int) $currentCharacterId);
    }

    private function buildPayload($data): array
    {
        $title = InputValidator::string($data, 'title', '');
        $body = InputValidator::string($data, 'body', '');
        if ($title === '' || $body === '') {
            $this->failValidation('Titolo e descrizione obbligatori', 'event_required_fields');
        }

        $locationIdValue = InputValidator::integer($data, 'location_id', 0);
        $locationId = $locationIdValue > 0 ? $locationIdValue : null;
        $dateEvent = InputValidator::string($data, 'date_event', '');
        if ($dateEvent === '') {
            $dateEvent = null;
        }

        $isVisible = property_exists($data, 'is_visible') ? InputValidator::integer($data, 'is_visible', 1) : 1;
        if ($isVisible !== 1) {
            $isVisible = 0;
        }

        return [
            'title' => $title,
            'body' => $body,
            'location_id' => $locationId,
            'date_event' => $dateEvent,
            'is_visible' => $isVisible,
        ];
    }

    public function list($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);

        $me = $this->requireCharacter();
        $data = $this->requestDataObject();

        $character_id = InputValidator::integer($data, 'character_id', $me);
        if (!$this->canManage($character_id, $me)) {
            $this->failUnauthorized('Accesso non autorizzato', 'event_forbidden');
        }

        $rows = $this->eventService()->listByCharacter($character_id);

        $can_manage = $this->canManage($character_id, $me);
        if (!empty($rows)) {
            foreach ($rows as $row) {
                $row->can_edit = $can_manage ? 1 : 0;
                $row->can_delete = $can_manage ? 1 : 0;
            }
        }

        $response = [
            'dataset' => $rows,
            'can_manage' => $can_manage ? 1 : 0,
        ];

        if (true == $echo) {
            ResponseEmitter::emit(ApiResponse::json($response));
        }

        return $response;
    }

    public function create()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);

        $me = $this->requireCharacter();
        $data = $this->requestDataObject();

        $character_id = InputValidator::integer($data, 'character_id', $me);
        if (!$this->canManage($character_id, $me)) {
            $this->failUnauthorized('Accesso non autorizzato', 'event_forbidden');
        }

        $payload = $this->buildPayload($data);

        $userId = \Core\AuthGuard::api()->requireUser();

        $this->eventService()->create($character_id, $userId, $me, $payload);

        ResponseEmitter::emit(ApiResponse::json([
            'status' => 'ok',
        ]));
    }

    public function update()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);

        $me = $this->requireCharacter();
        $data = $this->requestDataObject();

        $event_id = InputValidator::integer($data, 'id', 0);
        if ($event_id <= 0) {
            $this->failValidation('Avvenimento non valido', 'event_invalid');
        }
        $event = $this->eventService()->getById($event_id);
        if (empty($event)) {
            $this->failNotFound('Avvenimento non trovato', 'event_not_found');
        }

        if (!$this->canManage($event->character_id, $me)) {
            $this->failUnauthorized('Accesso non autorizzato', 'event_forbidden');
        }

        $payload = $this->buildPayload($data);
        $this->eventService()->update($event_id, $payload);

        ResponseEmitter::emit(ApiResponse::json([
            'status' => 'ok',
        ]));
    }

    public function delete($operator = '=')
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);

        $me = $this->requireCharacter();
        $data = $this->requestDataObject();

        $event_id = InputValidator::integer($data, 'id', 0);
        if ($event_id <= 0) {
            $this->failValidation('Avvenimento non valido', 'event_invalid');
        }
        $event = $this->eventService()->getById($event_id);
        if (empty($event)) {
            $this->failNotFound('Avvenimento non trovato', 'event_not_found');
        }

        if (!$this->canManage($event->character_id, $me)) {
            $this->failUnauthorized('Accesso non autorizzato', 'event_forbidden');
        }

        $this->eventService()->delete($event_id);

        ResponseEmitter::emit(ApiResponse::json([
            'status' => 'ok',
        ]));
    }
}
