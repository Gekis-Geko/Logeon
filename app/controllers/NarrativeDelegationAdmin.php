<?php

declare(strict_types=1);

use App\Services\NarrativeCapabilityService;
use App\Services\SettingsService;
use Core\Http\ApiResponse;
use Core\Http\AppError;
use Core\Http\RequestData;
use Core\Http\ResponseEmitter;

class NarrativeDelegationAdmin
{
    /** @var NarrativeCapabilityService|null */
    private $service = null;
    /** @var SettingsService|null */
    private $settingsService = null;

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

    private function settingsService(): SettingsService
    {
        if ($this->settingsService instanceof SettingsService) {
            return $this->settingsService;
        }

        $this->settingsService = new SettingsService();
        return $this->settingsService;
    }

    private function requireDelegationEnabled(): void
    {
        $dataset = $this->settingsService()->getNarrativeDelegationDataset();
        $enabled = (int) ($dataset['narrative_delegation_enabled'] ?? 0);
        if ($enabled !== 1) {
            throw AppError::notFound('Funzionalita non disponibile', [], 'narrative_delegation_disabled');
        }
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
        $this->requireDelegationEnabled();
        $rows = $this->service()->adminListCapabilities();
        ResponseEmitter::emit(ApiResponse::json(['dataset' => $rows]));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Grants
    // ─────────────────────────────────────────────────────────────────────────

    public function listGrants(): void
    {
        $this->requireAdmin();
        $this->requireDelegationEnabled();

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
        $this->requireDelegationEnabled();
        $data = $this->requestData();
        $grant = $this->service()->adminCreateGrant((array) $data);
        ResponseEmitter::emit(ApiResponse::json(['status' => 'ok', 'grant' => $grant]));
    }

    public function updateGrant(): void
    {
        $this->requireAdmin();
        $this->requireDelegationEnabled();
        $data = $this->requestData();
        $id = (int) ($data->id ?? 0);
        $grant = $this->service()->adminUpdateGrant($id, (array) $data);
        ResponseEmitter::emit(ApiResponse::json(['status' => 'ok', 'grant' => $grant]));
    }

    public function deleteGrant(): void
    {
        $this->requireAdmin();
        $this->requireDelegationEnabled();
        $data = $this->requestData();
        $id = (int) ($data->id ?? 0);
        $this->service()->adminDeleteGrant($id);
        ResponseEmitter::emit(ApiResponse::json(['status' => 'ok']));
    }
}
