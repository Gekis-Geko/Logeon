<?php

declare(strict_types=1);

use App\Services\NarrativeEventService;
use Core\Http\ApiResponse;
use Core\Http\AppError;
use Core\Http\InputValidator;
use Core\Http\RequestData;
use Core\Http\ResponseEmitter;
use Core\Logging\LegacyLoggerAdapter;

use Core\Logging\LoggerInterface;

class NarrativeEvents
{
    /** @var LoggerInterface|null */
    private $logger = null;
    /** @var NarrativeEventService|null */
    private $narrativeEventService = null;

    public function setLogger(LoggerInterface $logger = null)
    {
        $this->logger = $logger;
        return $this;
    }

    public function setNarrativeEventService(NarrativeEventService $service = null)
    {
        $this->narrativeEventService = $service;
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

    private function narrativeEventService(): NarrativeEventService
    {
        if ($this->narrativeEventService instanceof NarrativeEventService) {
            return $this->narrativeEventService;
        }
        $this->narrativeEventService = new NarrativeEventService();
        return $this->narrativeEventService;
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

    private function viewerIsStaff(): bool
    {
        return \Core\AppContext::authContext()->isStaff();
    }

    /**
     * Staff (Admin, Master, Moderator) e Superuser hanno accesso diretto
     * alle capability narrative senza passare per il sistema di delega.
     * Superuser è escluso da isStaff() per design — va incluso esplicitamente.
     */
    private function isNarrativeActor(): bool
    {
        return $this->viewerIsStaff() || \Core\AuthGuard::isSuperuser();
    }

    private function requireAdmin(): void
    {
        \Core\AuthGuard::api()->requireAbility('settings.manage');
    }

    // -------------------------------------------------------------------------
    // Game-facing endpoints
    // -------------------------------------------------------------------------

    public function list($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $viewerCharacterId = $this->requireCharacter();
        \Core\AuthGuard::releaseSession();

        $data = $this->requestDataObject();
        $filters = [
            'location_id' => (int) ($data->location_id ?? 0) ?: null,
            'event_type' => trim((string) ($data->event_type ?? '')),
            'scope' => trim((string) ($data->scope ?? '')),
            'source_system' => trim((string) ($data->source_system ?? '')),
            'tag_ids' => $data->tag_ids ?? ($data->tag_id ?? []),
        ];
        $limit = max(1, min(50, (int) ($data->limit ?? 50)));
        $page = max(1, (int) ($data->page ?? 1));

        $result = $this->narrativeEventService()->listForViewer(
            array_filter($filters),
            $viewerCharacterId,
            $this->viewerIsStaff(),
            $limit,
            $page,
        );
        $response = ['dataset' => $result];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function get($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $viewerCharacterId = $this->requireCharacter();

        $data = $this->requestDataObject();
        $eventId = (int) ($data->event_id ?? $data->id ?? 0);

        $event = $this->narrativeEventService()->getEventForViewer(
            $eventId,
            $viewerCharacterId,
            $this->viewerIsStaff(),
        );

        $response = ['dataset' => $event];
        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function gameCapabilities($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $characterId = $this->requireCharacter();
        \Core\AuthGuard::releaseSession();

        // Staff e Superuser bypassa il sistema di delega — AuthGuard gestisce la loro auth
        if ($this->isNarrativeActor()) {
            $response = ['dataset' => ['capabilities' => ['narrative.event.create', 'narrative.npc.spawn']]];
            if ($echo) {
                $this->emitJson($response);
            }
            return $response;
        }

        $capSvc = new \App\Services\NarrativeCapabilityService();
        $capabilities = $capSvc->listActorCapabilities($characterId);

        $response = ['dataset' => ['capabilities' => $capabilities]];
        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function gameLocationsList($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        \Core\AuthGuard::api()->requireUser();
        \Core\AuthGuard::releaseSession();

        if (!$this->isNarrativeActor()) {
            throw \Core\Http\AppError::unauthorized('Accesso negato.', [], 'forbidden');
        }

        $db = \Core\Database\DbAdapterFactory::createFromConfig();
        $rows = $db->fetchAllPrepared(
            'SELECT `id`, `name` FROM `locations` WHERE `date_deleted` IS NULL ORDER BY `name` ASC',
            [],
        );

        $dataset = array_map(static function ($row) {
            $r = is_object($row) ? (array) $row : (array) $row;
            return ['id' => (int) ($r['id'] ?? 0), 'name' => (string) ($r['name'] ?? '')];
        }, $rows ?: []);

        $response = ['dataset' => $dataset];
        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function gameCreate($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $characterId = $this->requireCharacter();
        \Core\AuthGuard::releaseSession();

        $data = $this->requestDataObject();
        $scope = trim((string) ($data->scope ?? 'local'));

        // Staff e Superuser possono sempre creare eventi; i delegati necessitano di un grant
        if (!$this->isNarrativeActor()) {
            $capSvc = new \App\Services\NarrativeCapabilityService();
            $capSvc->requireActor($characterId, 'narrative.event.create', $scope);
        }

        // I delegati non possono creare eventi ad alto impatto (max 1)
        $isPrivileged = $this->isNarrativeActor();
        $impactMax = $isPrivileged ? 2 : 1;
        $impactLevel = min($impactMax, max(0, (int) ($data->impact_level ?? 0)));
        $locationId = (int) ($data->location_id ?? 0);

        $params = [
            'title' => trim((string) ($data->title ?? '')),
            'description' => trim((string) ($data->description ?? '')),
            'scope' => $scope,
            'impact_level' => $impactLevel,
            'location_id' => $locationId,
            'visibility' => 'public',
            'source_system' => 'manual',
            'event_type' => 'manual',
            'event_mode' => 'scene',
            'created_by' => $characterId,
        ];

        $event = $this->narrativeEventService()->createEvent($params);

        // impact_level >= 1: messaggio di sistema in chat
        if ($impactLevel >= 1 && $locationId > 0) {
            $this->postSceneSystemMessage($event, $characterId, $locationId, $scope);
        }

        $response = ['dataset' => $event, 'message' => 'Scena narrativa aperta con successo.'];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function gameClose($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $characterId = $this->requireCharacter();
        \Core\AuthGuard::releaseSession();

        $data = $this->requestDataObject();
        $eventId = (int) ($data->event_id ?? $data->id ?? 0);

        $event = $this->narrativeEventService()->closeScene(
            $eventId,
            $characterId,
            $this->isNarrativeActor(),
        );

        // Messaggio di sistema alla chiusura (se impact_level >= 1 e location assegnata)
        $locationId = (int) ($event['location_id'] ?? 0);
        $impactLevel = (int) ($event['impact_level'] ?? 0);
        $scope = (string) ($event['scope'] ?? 'local');
        if ($impactLevel >= 1 && $locationId > 0) {
            $this->postSceneCloseSystemMessage($event, $characterId, $locationId, $scope);
        }

        $response = ['dataset' => $event, 'message' => 'Scena chiusa.'];
        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    private function postSceneCloseSystemMessage(array $event, int $characterId, int $locationId, string $scope): void
    {
        $title = \Core\Filter::html((string) ($event['title'] ?? ''));
        $body = '<div class="text-center py-1">'
            . '<p class="mb-0 fw-bold text-muted"><i class="bi bi-calendar-x me-1"></i> Scena conclusa: ' . $title . '</p>'
            . '</div>';

        $meta = json_encode([
            'command' => 'narrative_scene_close',
            'event_id' => (int) ($event['id'] ?? 0),
            'scope' => $scope,
        ], JSON_UNESCAPED_UNICODE);

        $svc = new \App\Services\LocationMessageService();

        if ($scope === 'regional') {
            $locationIds = $this->narrativeEventService()->getLocationIdsInSameMap($locationId);
        } else {
            $locationIds = [$locationId];
        }

        foreach ($locationIds as $lid) {
            if ($lid <= 0) {
                continue;
            }
            $svc->insertMessage($lid, $characterId, 3 /* TYPE_SYSTEM */, $body, $meta);
        }
    }

    public function gameListScenes($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireCharacter();
        \Core\AuthGuard::releaseSession();

        $data = $this->requestDataObject();
        $locationId = (int) ($data->location_id ?? 0);

        $scenes = $this->narrativeEventService()->listActiveScenes($locationId);
        $response = ['dataset' => $scenes];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    /**
     * Invia un messaggio di sistema in chat alla/e location della scena.
     * - scope=local: solo la location assegnata
     * - scope=regional: tutte le location della stessa mappa
     * - scope=global: solo la location assegnata (se presente)
     */
    private function postSceneSystemMessage(array $event, int $characterId, int $locationId, string $scope): void
    {
        $title = \Core\Filter::html((string) ($event['title'] ?? ''));
        $desc = trim((string) ($event['description'] ?? ''));
        $body = '<div class="text-center py-1">'
            . '<p class="mb-1 fw-bold"><i class="bi bi-calendar-event me-1"></i> Scena narrativa: ' . $title . '</p>'
            . ($desc !== '' ? '<p class="mb-0 small text-muted">' . \Core\Filter::html($desc) . '</p>' : '')
            . '</div>';

        $meta = json_encode([
            'command' => 'narrative_scene_open',
            'event_id' => (int) ($event['id'] ?? 0),
            'scope' => $scope,
        ], JSON_UNESCAPED_UNICODE);

        $svc = new \App\Services\LocationMessageService();

        if ($scope === 'regional') {
            $locationIds = $this->narrativeEventService()->getLocationIdsInSameMap($locationId);
        } else {
            $locationIds = [$locationId];
        }

        foreach ($locationIds as $lid) {
            if ($lid <= 0) {
                continue;
            }
            $svc->insertMessage($lid, $characterId, 3 /* TYPE_SYSTEM */, $body, $meta);
        }
    }

    // -------------------------------------------------------------------------
    // Admin endpoints
    // -------------------------------------------------------------------------

    public function adminList($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();

        $data = $this->requestDataObject();
        $query = (isset($data->query) && is_object($data->query)) ? $data->query : (object) [];
        $filters = [
            'event_type' => trim((string) ($query->event_type ?? $data->event_type ?? '')),
            'scope' => trim((string) ($query->scope ?? $data->scope ?? '')),
            'visibility' => trim((string) ($query->visibility ?? $data->visibility ?? '')),
            'location_id' => (int) ($query->location_id ?? $data->location_id ?? 0) ?: null,
            'source_system' => trim((string) ($query->source_system ?? $data->source_system ?? '')),
            'search' => trim((string) ($query->search ?? $data->search ?? '')),
            'tag_ids' => $query->tag_ids ?? ($data->tag_ids ?? ($query->tag_id ?? ($data->tag_id ?? []))),
        ];
        $limit = max(1, min(100, (int) ($data->results ?? $data->limit ?? 20)));
        $page = max(1, (int) ($data->page ?? 1));
        $sort = trim((string) ($data->orderBy ?? $data->sort ?? 'created_at|DESC'));

        $result = $this->narrativeEventService()->adminList(array_filter($filters), $limit, $page, $sort);
        $rows = isset($result['rows']) && is_array($result['rows']) ? $result['rows'] : [];
        $rows = array_map(static function ($row): array {
            $normalized = is_array($row) ? $row : (array) $row;
            if (empty($normalized['created_at']) && !empty($normalized['date_created'])) {
                $normalized['created_at'] = $normalized['date_created'];
            }
            if (!isset($normalized['source_system']) || trim((string) $normalized['source_system']) === '') {
                $normalized['source_system'] = 'manual';
            }
            return $normalized;
        }, $rows);

        $response = [
            'properties' => [
                'query' => [
                    'event_type' => $filters['event_type'],
                    'scope' => $filters['scope'],
                    'visibility' => $filters['visibility'],
                    'location_id' => (int) ($filters['location_id'] ?? 0),
                    'source_system' => $filters['source_system'],
                    'search' => $filters['search'],
                    'tag_ids' => $filters['tag_ids'],
                ],
                'page' => (int) ($result['page'] ?? $page),
                'results_page' => (int) ($result['limit'] ?? $limit),
                'orderBy' => $sort,
                'tot' => [
                    'count' => (int) ($result['total'] ?? count($rows)),
                ],
            ],
            'dataset' => $rows,
        ];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function adminGet($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();

        $data = $this->requestDataObject();
        $eventId = (int) ($data->event_id ?? $data->id ?? 0);

        $event = $this->narrativeEventService()->getEvent($eventId);
        if (empty($event['created_at']) && !empty($event['date_created'])) {
            $event['created_at'] = $event['date_created'];
        }
        if (!isset($event['source_system']) || trim((string) $event['source_system']) === '') {
            $event['source_system'] = 'manual';
        }
        $response = ['dataset' => $event];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function adminCreate($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();
        throw AppError::unauthorized(
            'Le Attivita recenti sono generate automaticamente. Creazione manuale disabilitata.',
            [],
            'narrative_event_manual_create_disabled',
        );
    }

    public function adminUpdate($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();

        $data = $this->requestDataObject();
        $eventId = (int) ($data->event_id ?? $data->id ?? 0);
        $vis = trim((string) ($data->visibility ?? ''));

        if ($vis === '') {
            throw AppError::validation('Campo visibility obbligatorio', [], 'visibility_required');
        }

        $event = $this->narrativeEventService()->updateVisibility($eventId, $vis);
        $response = ['dataset' => $event];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function adminAttach($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();

        $data = $this->requestDataObject();
        $eventId = (int) ($data->event_id ?? $data->id ?? 0);
        $refs = isset($data->entity_refs) ? (array) $data->entity_refs : [];

        if (empty($refs)) {
            throw AppError::validation('Almeno un entity_ref è obbligatorio', [], 'refs_required');
        }

        $event = $this->narrativeEventService()->attachEntities($eventId, $refs);
        $response = ['dataset' => $event];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function adminTagsSet($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();

        $data = $this->requestDataObject();
        $eventId = (int) ($data->event_id ?? $data->id ?? 0);
        $tagIds = isset($data->tag_ids) && is_array($data->tag_ids) ? $data->tag_ids : [];

        $event = $this->narrativeEventService()->syncTags($eventId, $tagIds);
        $response = ['dataset' => $event];

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
        $eventId = (int) ($data->event_id ?? $data->id ?? 0);

        $this->narrativeEventService()->adminDelete($eventId);
        $response = ['status' => 'ok'];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }
}
