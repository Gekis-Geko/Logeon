<?php

declare(strict_types=1);

use App\Services\LocationPositionTagService;
use Core\Http\ApiResponse;
use Core\Http\AppError;
use Core\Http\InputValidator;
use Core\Http\RequestData;
use Core\Http\ResponseEmitter;
use Core\Logging\LoggerInterface;

class LocationPositionTags
{
    /** @var LoggerInterface|null */
    private $logger = null;
    /** @var LocationPositionTagService|null */
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
        $this->logger = \Core\AppContext::logger();
        return $this->logger;
    }

    protected function trace($message, $context = false): void
    {
        $this->logger()->trace($message, $context);
    }

    private function tagService(): LocationPositionTagService
    {
        if ($this->tagService instanceof LocationPositionTagService) {
            return $this->tagService;
        }
        $this->tagService = new LocationPositionTagService();
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

    // ── Game endpoint ─────────────────────────────────────────────────────

    public function list(): void
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        \Core\AuthGuard::api()->requireCharacter();

        $data       = $this->requestDataObject();
        $locationId = isset($data->location_id) ? (int) $data->location_id : 0;

        if ($locationId <= 0) {
            throw AppError::validation('location_id obbligatorio', [], 'location_id_required');
        }

        $tags = $this->tagService()->listForLocation($locationId);
        $this->emitJson(['dataset' => $tags]);
    }

    // ── Admin ─────────────────────────────────────────────────────────────

    public function adminList(): void
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();

        $data  = $this->requestDataObject();
        $query = (isset($data->query) && is_object($data->query)) ? $data->query : $data;

        $filters = [
            'search'      => isset($query->search) ? (string) $query->search : '',
            'location_id' => isset($query->location_id) ? (int) $query->location_id : 0,
            'is_active'   => isset($query->is_active) && $query->is_active !== '' ? $query->is_active : '',
        ];
        $limit   = isset($data->results) ? (int) $data->results : (isset($data->results_page) ? (int) $data->results_page : 25);
        $page    = isset($data->page) ? (int) $data->page : 1;
        $orderBy = isset($data->orderBy) ? (string) $data->orderBy : 'lpt.name|ASC';

        $result = $this->tagService()->adminList($filters, $limit, $page, $orderBy);

        $this->emitJson([
            'dataset' => $result['rows'],
            'properties' => [
                'query'        => $filters['search'],
                'page'         => $result['page'],
                'results_page' => $result['limit'],
                'orderBy'      => $orderBy,
                'tot'          => $result['total'],
            ],
        ]);
    }

    public function adminCreate(): void
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();

        $data = $this->requestDataObject();
        $tag  = $this->tagService()->adminCreate((array) $data);
        $this->emitJson(['status' => 'ok', 'dataset' => $tag]);
    }

    public function adminUpdate(): void
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();

        $data = $this->requestDataObject();
        $id   = isset($data->id) ? (int) $data->id : 0;
        if ($id <= 0) {
            throw AppError::validation('ID tag mancante', [], 'missing_id');
        }

        $tag = $this->tagService()->adminUpdate($id, (array) $data);
        $this->emitJson(['status' => 'ok', 'dataset' => $tag]);
    }

    public function adminDelete(): void
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();

        $data = $this->requestDataObject();
        $id   = isset($data->id) ? (int) $data->id : 0;
        if ($id <= 0) {
            throw AppError::validation('ID tag mancante', [], 'missing_id');
        }

        $result = $this->tagService()->adminDelete($id);
        $this->emitJson(['status' => 'ok', 'deleted' => $result]);
    }
}
