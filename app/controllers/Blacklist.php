<?php

declare(strict_types=1);

use App\Services\BlacklistAdminService;
use Core\Http\ApiResponse;
use Core\Http\InputValidator;
use Core\Http\RequestData;
use Core\Http\ResponseEmitter;

class Blacklist
{
    /** @var BlacklistAdminService|null */
    private $blacklistAdminService = null;

    public function setBlacklistAdminService(BlacklistAdminService $service = null)
    {
        $this->blacklistAdminService = $service;
        return $this;
    }

    private function blacklistAdminService(): BlacklistAdminService
    {
        if ($this->blacklistAdminService instanceof BlacklistAdminService) {
            return $this->blacklistAdminService;
        }

        $this->blacklistAdminService = new BlacklistAdminService();
        return $this->blacklistAdminService;
    }

    private function requireAdmin(): void
    {
        \Core\AuthGuard::api()->requireAbility('settings.manage');
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

    public function list()
    {
        $this->requireAdmin();

        $data = $this->requestDataObject();
        $query = (isset($data->query) && is_object($data->query)) ? $data->query : (object) [];

        $email = InputValidator::string($query, 'email', '');
        $status = InputValidator::string($query, 'status', 'all');
        $page = max(1, InputValidator::integer($data, 'page', 1));
        $results = max(1, InputValidator::integer($data, 'results', 20));
        $orderBy = InputValidator::string($data, 'orderBy', 'date_start|DESC');

        $result = $this->blacklistAdminService()->listEntries($email, $status, $page, $results, $orderBy);

        $this->emitJson([
            'success' => true,
            'properties' => [
                'query' => (object) $result['query'],
                'page' => (int) $result['page'],
                'results_page' => (int) $result['results_page'],
                'orderBy' => (string) $result['orderBy'],
                'tot' => $result['tot'],
            ],
            'dataset' => $result['dataset'],
        ]);

        return $this;
    }

    public function create()
    {
        $this->requireAdmin();

        $data = $this->requestDataObject();
        $authorId = \Core\AuthGuard::api()->requireUser();

        $this->blacklistAdminService()->create($data, (int) $authorId);
        $this->emitJson([
            'success' => true,
            'message' => 'Ban registrato',
        ]);

        return $this;
    }

    public function update()
    {
        $this->requireAdmin();

        $data = $this->requestDataObject();
        $this->blacklistAdminService()->update($data);
        $this->emitJson([
            'success' => true,
            'message' => 'Ban aggiornato',
        ]);

        return $this;
    }

    public function delete()
    {
        $this->requireAdmin();

        $data = $this->requestDataObject();
        $id = InputValidator::integer($data, 'id', 0);
        $this->blacklistAdminService()->delete($id);
        $this->emitJson([
            'success' => true,
            'message' => 'Record blacklist eliminato',
        ]);

        return $this;
    }
}
