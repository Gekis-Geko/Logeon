<?php

declare(strict_types=1);

use App\Services\NarrativeCapabilityService;
use Core\Http\ApiResponse;
use Core\Http\RequestData;
use Core\Http\ResponseEmitter;

class NarrativeDelegationAdmin
{
    /** @var NarrativeCapabilityService|null */
    private $service = null;

    private function requireAdmin(): void
    {
        \Core\AuthGuard::api()->requireAbility('settings.manage');
    }

    private function service(): NarrativeCapabilityService
    {
        if ($this->service instanceof NarrativeCapabilityService) {
            return $this->service;
        }
        $this->service = new NarrativeCapabilityService();
        return $this->service;
    }

    private function requestData(): object
    {
        $request = RequestData::fromGlobals();
        $raw = $request->postJson('data', [], true);
        return is_object($raw) ? $raw : (object) (is_array($raw) ? $raw : []);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Capabilities
    // ─────────────────────────────────────────────────────────────────────────

    public function listCapabilities(): void
    {
        $this->requireAdmin();
        $rows = $this->service()->adminListCapabilities();
        ResponseEmitter::emit(ApiResponse::json(['dataset' => $rows]));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Grants
    // ─────────────────────────────────────────────────────────────────────────

    public function listGrants(): void
    {
        $this->requireAdmin();

        $request = RequestData::fromGlobals();
        $data = $request->postJson('data', [], true);
        $query = (isset($data->query) && is_object($data->query)) ? $data->query : $data;

        $filters = [
            'grantee_type' => isset($query->grantee_type) ? (string) $query->grantee_type : '',
            'capability' => isset($query->capability) ? (string) $query->capability : '',
        ];
        $limit = isset($data->results) ? (int) $data->results : (isset($data->results_page) ? (int) $data->results_page : 25);
        $page = isset($data->page) ? (int) $data->page : 1;
        $orderBy = isset($data->orderBy) ? (string) $data->orderBy : 'id|ASC';

        $result = $this->service()->adminListGrants($filters, $limit, $page, $orderBy);

        ResponseEmitter::emit(ApiResponse::json([
            'dataset' => $result['rows'],
            'properties' => [
                'query' => '',
                'page' => $result['page'],
                'results_page' => $result['limit'],
                'orderBy' => $orderBy,
                'tot' => $result['total'],
            ],
        ]));
    }

    public function createGrant(): void
    {
        $this->requireAdmin();
        $data = $this->requestData();
        $grant = $this->service()->adminCreateGrant((array) $data);
        ResponseEmitter::emit(ApiResponse::json(['status' => 'ok', 'grant' => $grant]));
    }

    public function updateGrant(): void
    {
        $this->requireAdmin();
        $data = $this->requestData();
        $id = (int) ($data->id ?? 0);
        $grant = $this->service()->adminUpdateGrant($id, (array) $data);
        ResponseEmitter::emit(ApiResponse::json(['status' => 'ok', 'grant' => $grant]));
    }

    public function deleteGrant(): void
    {
        $this->requireAdmin();
        $data = $this->requestData();
        $id = (int) ($data->id ?? 0);
        $this->service()->adminDeleteGrant($id);
        ResponseEmitter::emit(ApiResponse::json(['status' => 'ok']));
    }
}
