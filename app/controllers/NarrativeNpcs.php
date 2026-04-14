<?php

declare(strict_types=1);

use App\Services\NarrativeNpcService;
use Core\Http\ApiResponse;
use Core\Http\RequestData;
use Core\Http\ResponseEmitter;

class NarrativeNpcs
{
    /** @var NarrativeNpcService|null */
    private $service = null;

    private function service(): NarrativeNpcService
    {
        if ($this->service instanceof NarrativeNpcService) {
            return $this->service;
        }
        $this->service = new NarrativeNpcService();
        return $this->service;
    }

    private function requireAdmin(): void
    {
        \Core\AuthGuard::api()->requireAbility('settings.manage');
    }

    private function requestData(): object
    {
        $request = RequestData::fromGlobals();
        $raw = $request->postJson('data', [], true);
        return is_object($raw) ? $raw : (object) (is_array($raw) ? $raw : []);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Game side
    // ─────────────────────────────────────────────────────────────────────────

    public function publicList(): void
    {
        \Core\AuthGuard::api()->requireUser();

        $data = $this->requestData();
        $filters = [
            'group_type' => isset($data->group_type) ? (string) $data->group_type : '',
            'group_id' => isset($data->group_id) ? (int) $data->group_id : 0,
        ];

        $rows = $this->service()->publicList($filters);
        ResponseEmitter::emit(ApiResponse::json(['dataset' => $rows]));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Admin
    // ─────────────────────────────────────────────────────────────────────────

    public function adminList(): void
    {
        $this->requireAdmin();

        $request = RequestData::fromGlobals();
        $data = $request->postJson('data', [], true);
        $query = (isset($data->query) && is_object($data->query)) ? $data->query : $data;

        $filters = [
            'search' => isset($query->search) ? (string) $query->search : '',
            'group_type' => isset($query->group_type) ? (string) $query->group_type : '',
            'is_active' => isset($query->is_active) ? $query->is_active : '',
        ];
        $limit = isset($data->results) ? (int) $data->results : (isset($data->results_page) ? (int) $data->results_page : 25);
        $page = isset($data->page) ? (int) $data->page : 1;
        $orderBy = isset($data->orderBy) ? (string) $data->orderBy : 'name|ASC';

        $result = $this->service()->adminList($filters, $limit, $page, $orderBy);

        ResponseEmitter::emit(ApiResponse::json([
            'dataset' => $result['rows'],
            'properties' => [
                'query' => $filters['search'],
                'page' => $result['page'],
                'results_page' => $result['limit'],
                'orderBy' => $orderBy,
                'tot' => $result['total'],
            ],
        ]));
    }

    public function adminCreate(): void
    {
        $this->requireAdmin();
        $data = $this->requestData();
        $npc = $this->service()->create((array) $data);
        ResponseEmitter::emit(ApiResponse::json(['status' => 'ok', 'npc' => $npc]));
    }

    public function adminUpdate(): void
    {
        $this->requireAdmin();
        $data = $this->requestData();
        $id = (int) ($data->id ?? 0);
        $npc = $this->service()->update($id, (array) $data);
        ResponseEmitter::emit(ApiResponse::json(['status' => 'ok', 'npc' => $npc]));
    }

    public function adminDelete(): void
    {
        $this->requireAdmin();
        $data = $this->requestData();
        $id = (int) ($data->id ?? 0);
        $this->service()->delete($id);
        ResponseEmitter::emit(ApiResponse::json(['status' => 'ok']));
    }
}
