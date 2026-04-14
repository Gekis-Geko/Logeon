<?php

declare(strict_types=1);

use App\Services\FactionService;
use Core\Http\ApiResponse;
use Core\Http\AppError;
use Core\Http\InputValidator;
use Core\Http\RequestData;
use Core\Http\ResponseEmitter;
use Core\Logging\LegacyLoggerAdapter;

use Core\Logging\LoggerInterface;

class Factions
{
    /** @var LoggerInterface|null */
    private $logger = null;
    /** @var FactionService|null */
    private $factionService = null;

    public function setLogger(LoggerInterface $logger = null)
    {
        $this->logger = $logger;
        return $this;
    }

    public function setFactionService(FactionService $service = null)
    {
        $this->factionService = $service;
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

    private function factionService(): FactionService
    {
        if ($this->factionService instanceof FactionService) {
            return $this->factionService;
        }
        $this->factionService = new FactionService();
        return $this->factionService;
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
        $this->requireCharacter();

        $data = $this->requestDataObject();
        $limit = max(1, min(50, (int) ($data->limit ?? 20)));
        $page = max(1, (int) ($data->page ?? 1));

        $result = $this->factionService()->list(false, $limit, $page);
        $response = ['dataset' => $result];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function get($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireCharacter();

        $data = $this->requestDataObject();
        $factionId = (int) ($data->faction_id ?? $data->id ?? 0);

        $faction = $this->factionService()->get($factionId, false);
        $response = ['dataset' => $faction];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function myFactions($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $characterId = $this->requireCharacter();

        $rows = $this->factionService()->myFactions($characterId);
        $response = ['dataset' => $rows];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function getFactionMembers($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireCharacter();

        $data = $this->requestDataObject();
        $factionId = (int) ($data->faction_id ?? $data->id ?? 0);

        $rows = $this->factionService()->getFactionMembers($factionId);
        $response = ['dataset' => $rows];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function getFactionRelations($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireCharacter();

        $data = $this->requestDataObject();
        $factionId = (int) ($data->faction_id ?? $data->id ?? 0);

        $rows = $this->factionService()->getFactionRelations($factionId);
        $response = ['dataset' => $rows];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function leaveFaction($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $characterId = $this->requireCharacter();

        $data = $this->requestDataObject();
        $factionId = (int) ($data->faction_id ?? $data->id ?? 0);

        $this->factionService()->leaveFaction($characterId, $factionId);
        $response = ['status' => 'ok'];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function sendJoinRequest($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $characterId = $this->requireCharacter();

        $data = $this->requestDataObject();
        $factionId = (int) ($data->faction_id ?? 0);
        $message = trim((string) ($data->message ?? ''));

        $result = $this->factionService()->sendJoinRequest($characterId, $factionId, $message);
        $response = ['dataset' => $result];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function withdrawJoinRequest($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $characterId = $this->requireCharacter();

        $data = $this->requestDataObject();
        $requestId = (int) ($data->request_id ?? $data->id ?? 0);

        $this->factionService()->withdrawJoinRequest($characterId, $requestId);
        $response = ['status' => 'ok'];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function myJoinRequests($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $characterId = $this->requireCharacter();

        $rows = $this->factionService()->myJoinRequests($characterId);
        $response = ['dataset' => $rows];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function leaderListJoinRequests($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $characterId = $this->requireCharacter();

        $data = $this->requestDataObject();
        $factionId = (int) ($data->faction_id ?? $data->id ?? 0);

        $rows = $this->factionService()->leaderListJoinRequests($characterId, $factionId);
        $response = ['dataset' => $rows];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function reviewJoinRequest($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $characterId = $this->requireCharacter();

        $data = $this->requestDataObject();
        $requestId = (int) ($data->request_id ?? $data->id ?? 0);
        $decision = trim((string) ($data->decision ?? ''));

        $result = $this->factionService()->reviewJoinRequest($characterId, $requestId, $decision);
        $response = ['dataset' => $result];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function leaderInviteMember($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $characterId = $this->requireCharacter();

        $data = $this->requestDataObject();
        $factionId = (int) ($data->faction_id ?? 0);
        $targetCharacterId = (int) ($data->target_character_id ?? $data->character_id ?? 0);

        $rows = $this->factionService()->leaderInviteMember($characterId, $factionId, $targetCharacterId);
        $response = ['dataset' => $rows];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function leaderExpelMember($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $characterId = $this->requireCharacter();

        $data = $this->requestDataObject();
        $factionId = (int) ($data->faction_id ?? 0);
        $targetCharacterId = (int) ($data->target_character_id ?? $data->character_id ?? 0);

        $this->factionService()->leaderExpelMember($characterId, $factionId, $targetCharacterId);
        $response = ['status' => 'ok'];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function leaderProposeRelation($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $characterId = $this->requireCharacter();

        $data = $this->requestDataObject();
        $factionId = (int) ($data->faction_id ?? 0);
        $targetFactionId = (int) ($data->target_faction_id ?? 0);
        $relationType = trim((string) ($data->relation_type ?? 'neutral'));
        $notes = trim((string) ($data->notes ?? ''));

        $rows = $this->factionService()->leaderProposeRelation($characterId, $factionId, $targetFactionId, $relationType, $notes);
        $response = ['dataset' => $rows];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    // -------------------------------------------------------------------------
    // Admin — faction CRUD
    // -------------------------------------------------------------------------

    public function adminList($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();

        $data = $this->requestDataObject();
        $query = (isset($data->query) && is_object($data->query)) ? $data->query : (object) [];

        $filters = [
            'type' => trim((string) ($query->type ?? $data->type ?? '')),
            'scope' => trim((string) ($query->scope ?? $data->scope ?? '')),
            'search' => trim((string) ($query->search ?? $data->search ?? '')),
        ];
        $isActiveRaw = $query->is_active ?? ($data->is_active ?? null);
        if ($isActiveRaw !== null && $isActiveRaw !== '') {
            $filters['is_active'] = ((int) $isActiveRaw === 1) ? 1 : 0;
        }
        $isPublicRaw = $query->is_public ?? ($data->is_public ?? null);
        if ($isPublicRaw !== null && $isPublicRaw !== '') {
            $filters['is_public'] = ((int) $isPublicRaw === 1) ? 1 : 0;
        }

        $limit = max(1, min(100, (int) ($data->results ?? $data->limit ?? 20)));
        $page = max(1, (int) ($data->page ?? 1));
        $sort = trim((string) ($data->orderBy ?? $data->sort ?? 'name|ASC'));

        $serviceFilters = [];
        if ($filters['type'] !== '') {
            $serviceFilters['type'] = $filters['type'];
        }
        if ($filters['scope'] !== '') {
            $serviceFilters['scope'] = $filters['scope'];
        }
        if ($filters['search'] !== '') {
            $serviceFilters['search'] = $filters['search'];
        }
        if (array_key_exists('is_active', $filters)) {
            $serviceFilters['is_active'] = $filters['is_active'];
        }
        if (array_key_exists('is_public', $filters)) {
            $serviceFilters['is_public'] = $filters['is_public'];
        }

        $result = $this->factionService()->adminList($serviceFilters, $limit, $page, $sort);
        $response = [
            'properties' => [
                'query' => [
                    'type' => $filters['type'],
                    'scope' => $filters['scope'],
                    'search' => $filters['search'],
                    'is_active' => array_key_exists('is_active', $filters) ? (int) $filters['is_active'] : '',
                    'is_public' => array_key_exists('is_public', $filters) ? (int) $filters['is_public'] : '',
                ],
                'page' => (int) ($result['page'] ?? $page),
                'results_page' => (int) ($result['limit'] ?? $limit),
                'orderBy' => $sort,
                'tot' => [
                    'count' => (int) ($result['total'] ?? 0),
                ],
            ],
            'dataset' => isset($result['rows']) && is_array($result['rows']) ? $result['rows'] : [],
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
        $factionId = (int) ($data->faction_id ?? $data->id ?? 0);

        $faction = $this->factionService()->get($factionId, true);
        $response = ['dataset' => $faction];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function adminCreate($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();

        $session = \Core\AuthGuard::api()->requireCharacter();
        $data = $this->requestDataObject();
        $data->actor_character_id = (int) $session;

        $faction = $this->factionService()->adminCreate($data);
        $response = ['dataset' => $faction];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function adminUpdate($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();

        $session = \Core\AuthGuard::api()->requireCharacter();
        $data = $this->requestDataObject();
        $data->actor_character_id = (int) $session;

        $faction = $this->factionService()->adminUpdate($data);
        $response = ['dataset' => $faction];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function adminDelete($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();
        $session = \Core\AuthGuard::api()->requireCharacter();

        $data = $this->requestDataObject();
        $factionId = (int) ($data->faction_id ?? $data->id ?? 0);

        $this->factionService()->adminDelete($factionId, (int) $session);
        $response = ['status' => 'ok'];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    // -------------------------------------------------------------------------
    // Admin — membership
    // -------------------------------------------------------------------------

    public function adminMemberList($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();

        $data = $this->requestDataObject();
        $factionId = (int) ($data->faction_id ?? $data->id ?? 0);

        $rows = $this->factionService()->adminMemberList($factionId);
        $response = ['dataset' => $rows];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function adminMemberAdd($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();
        $session = \Core\AuthGuard::api()->requireCharacter();

        $data = $this->requestDataObject();
        $factionId = (int) ($data->faction_id ?? 0);
        $characterId = (int) ($data->character_id ?? 0);
        $role = trim((string) ($data->role ?? 'member'));
        $rank = trim((string) ($data->rank ?? ''));

        if ($characterId <= 0) {
            throw AppError::validation('ID personaggio obbligatorio', [], 'character_id_required');
        }

        $rows = $this->factionService()->adminMemberAdd($factionId, $characterId, $role, $rank, (int) $session);
        $response = ['dataset' => $rows];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function adminMemberUpdate($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();
        $session = \Core\AuthGuard::api()->requireCharacter();

        $data = $this->requestDataObject();
        $membershipId = (int) ($data->membership_id ?? $data->id ?? 0);

        $rows = $this->factionService()->adminMemberUpdate($membershipId, $data, (int) $session);
        $response = ['dataset' => $rows];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function adminMemberRemove($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();
        $session = \Core\AuthGuard::api()->requireCharacter();

        $data = $this->requestDataObject();
        $factionId = (int) ($data->faction_id ?? 0);
        $characterId = (int) ($data->character_id ?? 0);

        $this->factionService()->adminMemberRemove($factionId, $characterId, (int) $session);
        $response = ['status' => 'ok'];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    // -------------------------------------------------------------------------
    // Admin — relationships
    // -------------------------------------------------------------------------

    public function adminRelationList($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();

        $data = $this->requestDataObject();
        $factionId = (int) ($data->faction_id ?? $data->id ?? 0);

        $rows = $this->factionService()->adminRelationList($factionId);
        $response = ['dataset' => $rows];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function adminRelationSet($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();
        $session = \Core\AuthGuard::api()->requireCharacter();

        $data = $this->requestDataObject();
        $factionId = (int) ($data->faction_id ?? 0);
        $targetFactionId = (int) ($data->target_faction_id ?? 0);
        $relationType = trim((string) ($data->relation_type ?? 'neutral'));
        $intensity = (int) ($data->intensity ?? 5);
        $notes = trim((string) ($data->notes ?? ''));

        $rows = $this->factionService()->adminRelationSet($factionId, $targetFactionId, $relationType, $intensity, $notes, (int) $session);
        $response = ['dataset' => $rows];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function adminRelationRemove($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();

        $data = $this->requestDataObject();
        $factionId = (int) ($data->faction_id ?? 0);
        $targetFactionId = (int) ($data->target_faction_id ?? 0);

        $this->factionService()->adminRelationRemove($factionId, $targetFactionId);
        $response = ['status' => 'ok'];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }
}
