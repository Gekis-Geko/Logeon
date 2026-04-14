<?php

declare(strict_types=1);

use App\Services\NarrativeTagService;
use Core\Http\ApiResponse;
use Core\Http\AppError;
use Core\Http\InputValidator;
use Core\Http\RequestData;
use Core\Http\ResponseEmitter;
use Core\Logging\LegacyLoggerAdapter;

use Core\Logging\LoggerInterface;

class NarrativeTags
{
    /** @var LoggerInterface|null */
    private $logger = null;
    /** @var NarrativeTagService|null */
    private $tagService = null;

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

    protected function trace($message, $context = false): void
    {
        $this->logger()->trace($message, $context);
    }

    private function tagService(): NarrativeTagService
    {
        if ($this->tagService instanceof NarrativeTagService) {
            return $this->tagService;
        }
        $this->tagService = new NarrativeTagService();
        return $this->tagService;
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

    private function requireAdmin(): void
    {
        if (!\Core\AppContext::authContext()->isAdmin()) {
            throw AppError::unauthorized('Accesso negato');
        }
    }

    // ── Endpoint pubblici (game) ───────────────────────────────

    /**
     * GET /list/narrative-tags
     * Restituisce il catalogo tag attivi, opzionalmente filtrati per entity_type.
     */
    public function publicList(): void
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);

        $data = $this->requestDataObject();
        $entityType = isset($data->entity_type) ? (string) $data->entity_type : null;
        $search = isset($data->search) ? (string) $data->search : '';

        $filters = [];
        if ($search !== '') {
            $filters['search'] = $search;
        }

        try {
            $tags = $this->tagService()->listActiveCatalog($entityType, $filters);
        } catch (\Throwable $e) {
            $tags = [];
        }

        $this->emitJson(['dataset' => $tags]);
    }

    /**
     * POST /narrative-tags/entity
     * Restituisce i tag assegnati a una specifica entità.
     */
    public function entityTags(): void
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);

        $data = $this->requestDataObject();
        $entityType = isset($data->entity_type) ? (string) $data->entity_type : '';
        $entityId = isset($data->entity_id) ? (int) $data->entity_id : 0;

        if ($entityType === '' || $entityId <= 0) {
            throw AppError::validation('entity_type e entity_id sono obbligatori', [], 'missing_params');
        }

        $tags = $this->tagService()->listAssignments($entityType, $entityId, false);
        $this->emitJson(['dataset' => $tags]);
    }

    // ── Admin: catalogo ───────────────────────────────────────

    public function adminList(): void
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();

        $data = $this->requestDataObject();
        $query = (isset($data->query) && is_object($data->query)) ? $data->query : $data;
        $filters = [
            'search' => isset($query->search) ? (string) $query->search : '',
            'category' => isset($query->category) ? (string) $query->category : '',
            'is_active' => isset($query->is_active) ? $query->is_active : '',
        ];
        $limit = isset($data->results) ? (int) $data->results : (isset($data->results_page) ? (int) $data->results_page : 25);
        $page = isset($data->page) ? (int) $data->page : 1;
        $orderBy = isset($data->orderBy) ? (string) $data->orderBy : 'label|ASC';

        $result = $this->tagService()->listCatalog($filters, $limit, $page, $orderBy, true);

        $this->emitJson([
            'dataset' => $result['rows'],
            'properties' => [
                'query' => $filters['search'],
                'page' => $result['page'],
                'results_page' => $result['limit'],
                'orderBy' => $orderBy,
                'tot' => $result['total'],
            ],
        ]);
    }

    public function adminCreate(): void
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();

        $data = $this->requestDataObject();
        $tag = $this->tagService()->create((array) $data);

        $this->emitJson(['status' => 'ok', 'tag' => $tag]);
    }

    public function adminUpdate(): void
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();

        $data = $this->requestDataObject();
        $tagId = isset($data->id) ? (int) $data->id : 0;
        if ($tagId <= 0) {
            throw AppError::validation('ID tag mancante', [], 'missing_id');
        }

        $tag = $this->tagService()->update($tagId, (array) $data);
        $this->emitJson(['status' => 'ok', 'tag' => $tag]);
    }

    public function adminDelete(): void
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();

        $data = $this->requestDataObject();
        $tagId = isset($data->id) ? (int) $data->id : 0;
        if ($tagId <= 0) {
            throw AppError::validation('ID tag mancante', [], 'missing_id');
        }

        $result = $this->tagService()->delete($tagId);
        $this->emitJson(['status' => 'ok', 'deleted' => $result]);
    }

    // ── Admin: assegnazioni ───────────────────────────────────

    /**
     * Restituisce i tag assegnati a un'entità (vista admin, include inattivi).
     */
    public function adminEntityTags(): void
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();

        $data = $this->requestDataObject();
        $entityType = isset($data->entity_type) ? (string) $data->entity_type : '';
        $entityId = isset($data->entity_id) ? (int) $data->entity_id : 0;

        if ($entityType === '' || $entityId <= 0) {
            throw AppError::validation('entity_type e entity_id sono obbligatori', [], 'missing_params');
        }

        $tags = $this->tagService()->listAssignments($entityType, $entityId, true);
        $this->emitJson(['dataset' => $tags]);
    }

    /**
     * Sincronizza l'intero set di tag per un'entità (replace completo).
     */
    public function adminSyncTags(): void
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();

        $data = $this->requestDataObject();
        $entityType = isset($data->entity_type) ? (string) $data->entity_type : '';
        $entityId = isset($data->entity_id) ? (int) $data->entity_id : 0;
        $tagIds = $this->tagService()->parseTagIds($data->tag_ids ?? []);

        if ($entityType === '' || $entityId <= 0) {
            throw AppError::validation('entity_type e entity_id sono obbligatori', [], 'missing_params');
        }

        $tags = $this->tagService()->syncAssignments($entityType, $entityId, $tagIds);
        $this->emitJson(['status' => 'ok', 'dataset' => $tags]);
    }

    /**
     * Ricerca entità per il widget di assegnazione.
     */
    public function adminSearchEntities(): void
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();

        $data = $this->requestDataObject();
        $entityType = isset($data->entity_type) ? (string) $data->entity_type : '';
        $query = isset($data->query) ? (string) $data->query : '';
        $limit = isset($data->limit) ? (int) $data->limit : 20;

        if ($entityType === '') {
            throw AppError::validation('entity_type obbligatorio', [], 'missing_params');
        }

        $results = $this->tagService()->searchEntities($entityType, $query, $limit);
        $this->emitJson(['dataset' => $results]);
    }
}
