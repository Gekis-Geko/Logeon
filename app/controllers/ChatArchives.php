<?php

declare(strict_types=1);

use App\Services\ChatArchiveService;
use App\Services\CharacterEventService;
use Core\AuthGuard;
use Core\Http\ApiResponse;
use Core\Http\AppError;
use Core\Http\InputValidator;
use Core\Http\RequestData;
use Core\Http\ResponseEmitter;
use Core\Logging\LoggerInterface;

class ChatArchives
{
    /** @var LoggerInterface|null */
    private $logger = null;
    /** @var ChatArchiveService|null */
    private $archiveService = null;
    /** @var CharacterEventService|null */
    private $characterEventService = null;

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
        $this->logger = \Core\AppContext::logger();
        return $this->logger;
    }

    protected function trace($message, $context = false): void
    {
        $this->logger()->trace($message, $context);
    }

    private function archiveService(): ChatArchiveService
    {
        if ($this->archiveService instanceof ChatArchiveService) {
            return $this->archiveService;
        }
        $this->archiveService = new ChatArchiveService();
        return $this->archiveService;
    }

    private function characterEventService(): CharacterEventService
    {
        if ($this->characterEventService instanceof CharacterEventService) {
            return $this->characterEventService;
        }
        $this->characterEventService = new CharacterEventService();
        return $this->characterEventService;
    }

    private function requestDataObject()
    {
        $request = RequestData::fromGlobals();
        return InputValidator::postJsonObject($request, 'data', true);
    }

    private function requireUser(): int
    {
        return (int) \Core\AuthGuard::api()->requireUser();
    }

    private function requireCharacter(): int
    {
        return (int) \Core\AuthGuard::api()->requireCharacter();
    }

    private function currentCharacterIdForViewer(): int
    {
        if (AuthGuard::isStaff()) {
            return (int) \Core\AppContext::session()->get('character_id');
        }

        return $this->requireCharacter();
    }

    private function emitJson(array $payload): void
    {
        ResponseEmitter::emit(ApiResponse::json($payload));
    }

    // -------------------------------------------------------------------------

    public function list($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);

        $characterId = $this->requireCharacter();
        $userId      = $this->requireUser();

        $rows = $this->archiveService()->listByOwner($characterId, $userId);
        $response = ['dataset' => $rows];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function get($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);

        $characterId = $this->currentCharacterIdForViewer();
        $userId      = $this->requireUser();
        $isStaff     = AuthGuard::isStaff();
        $data        = $this->requestDataObject();
        $id          = InputValidator::integer($data, 'id', 0);
        $diaryEventId = InputValidator::integer($data, 'diary_event_id', 0);

        if ($id <= 0) {
            throw AppError::validation('ID archivio obbligatorio', [], 'archive_id_required');
        }

        $result = $this->archiveService()->getWithMessages(
            $id,
            $characterId,
            $userId,
            $isStaff,
            $diaryEventId > 0 ? $diaryEventId : null,
        );
        if ($result === null) {
            throw AppError::notFound('Archivio non trovato', [], 'archive_not_found');
        }

        $response = ['dataset' => $result];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function create($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);

        $characterId = $this->requireCharacter();
        $userId      = $this->requireUser();
        $data        = $this->requestDataObject();

        $messageIdsRaw = property_exists($data, 'message_ids') && is_array($data->message_ids)
            ? $data->message_ids
            : [];

        $payload = [
            'title'                        => InputValidator::string($data, 'title', ''),
            'description'                  => InputValidator::string($data, 'description', ''),
            'source_location_id'           => InputValidator::integer($data, 'source_location_id', 0),
            'started_at'                   => InputValidator::string($data, 'started_at', ''),
            'ended_at'                     => InputValidator::string($data, 'ended_at', ''),
            'message_ids'                  => array_map('intval', $messageIdsRaw),
        ];

        $archiveId = $this->archiveService()->create($userId, $characterId, $payload);
        $response  = ['dataset' => ['id' => $archiveId]];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function update($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);

        $characterId = $this->currentCharacterIdForViewer();
        $userId      = $this->requireUser();
        $isStaff     = AuthGuard::isStaff();
        $data        = $this->requestDataObject();
        $id          = InputValidator::integer($data, 'id', 0);

        if ($id <= 0) {
            throw AppError::validation('ID archivio obbligatorio', [], 'archive_id_required');
        }

        $payload = [
            'title'       => InputValidator::string($data, 'title', ''),
            'description' => InputValidator::string($data, 'description', ''),
        ];

        $this->archiveService()->update($id, $characterId, $payload, $userId, $isStaff);
        $response = ['ok' => true];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function delete($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);

        $characterId = $this->currentCharacterIdForViewer();
        $userId      = $this->requireUser();
        $isStaff     = AuthGuard::isStaff();
        $data        = $this->requestDataObject();
        $id          = InputValidator::integer($data, 'id', 0);

        if ($id <= 0) {
            throw AppError::validation('ID archivio obbligatorio', [], 'archive_id_required');
        }

        $this->archiveService()->softDelete($id, $characterId, $userId, $isStaff);
        $response = ['ok' => true];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function setPublic($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);

        $characterId = $this->currentCharacterIdForViewer();
        $userId      = $this->requireUser();
        $isStaff     = AuthGuard::isStaff();
        $data        = $this->requestDataObject();
        $id          = InputValidator::integer($data, 'id', 0);
        $enabled     = InputValidator::integer($data, 'public_enabled', 0) === 1;

        if ($id <= 0) {
            throw AppError::validation('ID archivio obbligatorio', [], 'archive_id_required');
        }

        $archive  = $this->archiveService()->setPublic($id, $characterId, $enabled, $userId, $isStaff);
        $response = [
            'dataset' => [
                'public_token'   => $archive->public_token ?? null,
                'public_enabled' => isset($archive->public_enabled) ? (int) $archive->public_enabled : 0,
                'visibility'     => $archive->visibility ?? 'private',
            ],
        ];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function linkDiary($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);

        $characterId   = $this->currentCharacterIdForViewer();
        $userId        = $this->requireUser();
        $isStaff       = AuthGuard::isStaff();
        $data          = $this->requestDataObject();
        $id            = InputValidator::integer($data, 'id', 0);
        $diaryEventRaw = InputValidator::integer($data, 'diary_event_id', 0);
        $diaryEventId  = $diaryEventRaw > 0 ? $diaryEventRaw : null;

        if ($id <= 0) {
            throw AppError::validation('ID archivio obbligatorio', [], 'archive_id_required');
        }

        $this->archiveService()->linkDiary($id, $characterId, $diaryEventId, $userId, $isStaff);
        $response = ['ok' => true];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function searchDiaryEvents($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);

        $viewerCharacterId = $this->currentCharacterIdForViewer();
        $userId            = $this->requireUser();
        $isStaff           = AuthGuard::isStaff();
        $data              = $this->requestDataObject();
        $id                = InputValidator::integer($data, 'id', 0);
        $query             = InputValidator::string($data, 'query', '');

        if ($id <= 0) {
            throw AppError::validation('ID archivio obbligatorio', [], 'archive_id_required');
        }

        $archive = $this->archiveService()->getOwnedById($id, $viewerCharacterId, $userId, $isStaff);
        if ($archive === null) {
            throw AppError::notFound('Archivio non trovato', [], 'archive_not_found');
        }

        $ownerCharacterId = isset($archive->owner_character_id) ? (int) $archive->owner_character_id : 0;
        $rows = $this->characterEventService()->searchByCharacterTitle($ownerCharacterId, $query, 12);

        $response = ['dataset' => $rows];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }
}
