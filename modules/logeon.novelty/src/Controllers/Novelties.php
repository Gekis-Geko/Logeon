<?php

declare(strict_types=1);

namespace Modules\Logeon\Novelty\Controllers;

use Modules\Logeon\Novelty\Models\Novelty;
use Modules\Logeon\Novelty\Services\NoveltyService;
use Core\AuditLogService;
use Core\HtmlSanitizer;
use Core\Http\ApiResponse;
use Core\Http\InputValidator;
use Core\Http\RequestData;
use Core\Http\ResponseEmitter;
use Core\Logging\LoggerInterface;

class Novelties extends Novelty
{
    /** @var LoggerInterface|null */
    private $logger = null;
    /** @var NoveltyService|null */
    private $noveltyService = null;

    public function setLogger(LoggerInterface $logger = null)
    {
        $this->logger = $logger;
        return $this;
    }

    public function setNoveltyService(NoveltyService $noveltyService = null)
    {
        $this->noveltyService = $noveltyService;
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

    private function noveltyService(): NoveltyService
    {
        if ($this->noveltyService instanceof NoveltyService) {
            return $this->noveltyService;
        }

        $this->noveltyService = new NoveltyService();
        return $this->noveltyService;
    }

    protected function trace($message, $context = false): void
    {
        $this->logger()->trace($message, $context);
    }

    private function requestDataObject()
    {
        $request = RequestData::fromGlobals();
        return InputValidator::postJsonObject($request, 'data', true);
    }

    public function list($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);

        $post = $this->requestDataObject();

        $type = null;
        if (isset($post->query) && isset($post->query->type)) {
            $type = (int) $post->query->type;
        }
        if (isset($post->type)) {
            $type = (int) $post->type;
        }

        $only_published = true;
        if (isset($post->only_published)) {
            $only_published = (bool) $post->only_published;
        }

        $is_admin = \Core\AppContext::authContext()->isAdmin();
        if ($is_admin) {
            $only_published = false === $only_published ? false : true;
        }

        $results = isset($post->results) ? (int) $post->results : null;
        if ($results === null && isset($post->limit)) {
            $results = (int) $post->limit;
        }
        if ($results === null || $results <= 0) {
            $results = 10;
        }
        if ($results > 50) {
            $results = 50;
        }

        $page = isset($post->page) ? (int) $post->page : 1;
        if ($page < 1) {
            $page = 1;
        }
        $offset = ($page - 1) * $results;

        $queryData = $this->noveltyService()->listPaginated(
            $this->fillable,
            $type,
            $only_published,
            $offset,
            $results,
        );

        $dataset = $queryData['dataset'] ?? [];
        if (!empty($dataset)) {
            foreach ($dataset as $row) {
                if (isset($row->body)) {
                    $row->body = HtmlSanitizer::sanitize((string) $row->body, ['allow_images' => true]);
                }
                if (isset($row->excerpt)) {
                    $row->excerpt = HtmlSanitizer::sanitize((string) $row->excerpt, ['allow_images' => false]);
                }
            }
        }

        $count = $queryData['count'] ?? null;

        $response = [
            'properties' => [
                'page' => $page,
                'results_page' => $results,
                'tot' => $count,
            ],
            'dataset' => $dataset,
        ];

        AuditLogService::writeFromUrl(\Core\Router::currentUri(), $response);

        if (true == $echo) {
            ResponseEmitter::emit(ApiResponse::json($response));
        }

        return $response;
    }

    public function adminList()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        \Core\AuthGuard::api()->requireAbility('settings.manage', [], 'Operazione non autorizzata');

        $data = $this->requestDataObject();
        $result = $this->noveltyService()->adminList($data);

        ResponseEmitter::emit(ApiResponse::json($result));
    }

    public function create()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        \Core\AuthGuard::api()->requireAbility('settings.manage', [], 'Operazione non autorizzata');

        $data = $this->requestDataObject();
        $title = trim((string) ($data->title ?? ''));
        if ($title === '') {
            throw \Core\Http\AppError::validation('Titolo obbligatorio', [], 'title_required');
        }

        $this->noveltyService()->adminCreate($data);

        ResponseEmitter::emit(ApiResponse::json(['success' => true, 'message' => 'Novità creata']));
    }

    public function update()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        \Core\AuthGuard::api()->requireAbility('settings.manage', [], 'Operazione non autorizzata');

        $data = $this->requestDataObject();
        $id = (int) ($data->id ?? 0);
        $title = trim((string) ($data->title ?? ''));
        if ($id <= 0) {
            throw \Core\Http\AppError::validation('ID non valido', [], 'id_invalid');
        }
        if ($title === '') {
            throw \Core\Http\AppError::validation('Titolo obbligatorio', [], 'title_required');
        }

        $this->noveltyService()->adminUpdate($data);

        ResponseEmitter::emit(ApiResponse::json(['success' => true, 'message' => 'Novità aggiornata']));
    }

    public function adminDelete()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        \Core\AuthGuard::api()->requireAbility('settings.manage', [], 'Operazione non autorizzata');

        $data = $this->requestDataObject();
        $id = (int) ($data->id ?? 0);
        if ($id <= 0) {
            throw \Core\Http\AppError::validation('ID non valido', [], 'id_invalid');
        }

        $this->noveltyService()->adminDelete($id);

        ResponseEmitter::emit(ApiResponse::json(['success' => true, 'message' => 'Novità eliminata']));
    }
}


