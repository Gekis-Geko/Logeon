<?php

declare(strict_types=1);

use App\Models\Character;
use App\Services\CharacterAttributesFacadeService;
use App\Services\CharacterBondService;
use App\Services\CharacterDirectoryService;
use App\Services\CharacterProfileService;
use App\Services\CharacterStateService;
use App\Services\UserService;
use Core\AuditLogService;
use Core\Http\ApiResponse;

use Core\Http\AppError;
use Core\Http\InputValidator;
use Core\Http\RequestContext;
use Core\Http\RequestData;
use Core\Http\ResponseEmitter;
use Core\Logging\LegacyLoggerAdapter;
use Core\Logging\LoggerInterface;
use Core\SessionStore;

class Characters extends Character
{
    /** @var LoggerInterface|null */
    private $logger = null;
    /** @var CharacterProfileService|null */
    private $characterProfileService = null;
    /** @var CharacterDirectoryService|null */
    private $characterDirectoryService = null;
    /** @var CharacterStateService|null */
    private $characterStateService = null;
    /** @var CharacterBondService|null */
    private $characterBondService = null;
    /** @var CharacterAttributesFacadeService|null */
    private $characterAttributesFacadeService = null;

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

    public function setCharacterProfileService(CharacterProfileService $service = null)
    {
        $this->characterProfileService = $service;
        return $this;
    }

    public function setCharacterDirectoryService(CharacterDirectoryService $service = null)
    {
        $this->characterDirectoryService = $service;
        return $this;
    }

    public function setCharacterStateService(CharacterStateService $service = null)
    {
        $this->characterStateService = $service;
        return $this;
    }

    public function setCharacterBondService(CharacterBondService $service = null)
    {
        $this->characterBondService = $service;
        return $this;
    }

    public function setCharacterAttributesFacadeService(CharacterAttributesFacadeService $service = null)
    {
        $this->characterAttributesFacadeService = $service;
        return $this;
    }

    private function characterProfileService(): CharacterProfileService
    {
        if ($this->characterProfileService instanceof CharacterProfileService) {
            return $this->characterProfileService;
        }

        $this->characterProfileService = new CharacterProfileService();
        return $this->characterProfileService;
    }

    private function characterDirectoryService(): CharacterDirectoryService
    {
        if ($this->characterDirectoryService instanceof CharacterDirectoryService) {
            return $this->characterDirectoryService;
        }

        $this->characterDirectoryService = new CharacterDirectoryService();
        return $this->characterDirectoryService;
    }

    private function characterStateService(): CharacterStateService
    {
        if ($this->characterStateService instanceof CharacterStateService) {
            return $this->characterStateService;
        }

        $this->characterStateService = new CharacterStateService();
        return $this->characterStateService;
    }

    private function characterBondService(): CharacterBondService
    {
        if ($this->characterBondService instanceof CharacterBondService) {
            return $this->characterBondService;
        }

        $this->characterBondService = new CharacterBondService();
        return $this->characterBondService;
    }

    private function characterAttributesFacadeService(): CharacterAttributesFacadeService
    {
        if ($this->characterAttributesFacadeService instanceof CharacterAttributesFacadeService) {
            return $this->characterAttributesFacadeService;
        }

        $this->characterAttributesFacadeService = new CharacterAttributesFacadeService();
        return $this->characterAttributesFacadeService;
    }

    protected function trace($message, $context = false): void
    {
        $this->logger()->trace($message, $context);
    }

    private function failValidation($message, string $errorCode = 'validation_error')
    {
        throw AppError::validation((string) $message, [], $errorCode);
    }

    private function failUnauthorized($message = 'Operazione non autorizzata', string $errorCode = 'unauthorized')
    {
        throw AppError::unauthorized((string) $message, [], $errorCode);
    }

    private function getSessionValue($key)
    {
        return SessionStore::get($key);
    }

    private function setSessionValue($key, $value): void
    {
        SessionStore::set($key, $value);
    }

    private function getServerValue(string $key, $default = null)
    {
        $server = RequestContext::server();
        return array_key_exists($key, $server) ? $server[$key] : $default;
    }

    private function isStaffUser(): bool
    {
        return ((int) $this->getSessionValue('user_is_administrator') === 1)
            || ((int) $this->getSessionValue('user_is_moderator') === 1)
            || ((int) $this->getSessionValue('user_is_master') === 1);
    }

    private function isAdminOrModerator(): bool
    {
        return ((int) $this->getSessionValue('user_is_administrator') === 1)
            || ((int) $this->getSessionValue('user_is_moderator') === 1);
    }
    private function resolveLogTargetCharacterId(object $data, int $fallbackCharacterId): int
    {
        $query = (isset($data->query) && is_object($data->query)) ? $data->query : null;

        $candidates = [];
        $candidates[] = InputValidator::integer($data, 'character_id', 0);
        $candidates[] = InputValidator::integer($data, 'id', 0);
        if ($query !== null) {
            $candidates[] = InputValidator::integer($query, 'character_id', 0);
            $candidates[] = InputValidator::integer($query, 'id', 0);
        }

        foreach ($candidates as $candidate) {
            if ((int) $candidate > 0) {
                return (int) $candidate;
            }
        }

        return $fallbackCharacterId;
    }

    private function parseLogGridRequest(object $post, string $defaultOrderBy = 'date_created|DESC', int $defaultResults = 10): array
    {
        $page = max(1, InputValidator::integer($post, 'page', 1));
        $results = InputValidator::integer($post, 'results', 0);
        if ($results <= 0) {
            $results = InputValidator::integer($post, 'results_page', 0);
        }
        if ($results <= 0) {
            $results = InputValidator::integer($post, 'limit', $defaultResults);
        }
        if ($results < 1) {
            $results = $defaultResults;
        }
        if ($results > 100) {
            $results = 100;
        }

        $orderBy = InputValidator::string($post, 'orderBy', $defaultOrderBy);
        if ($orderBy === '') {
            $orderBy = $defaultOrderBy;
        }

        $query = (isset($post->query) && is_object($post->query)) ? $post->query : (object) [];

        return [$page, $results, trim($orderBy), $query];
    }

    private function normalizeAdminCharactersVisibilityFilter($raw): string
    {
        $value = strtolower(trim((string) $raw));
        if ($value !== 'visible' && $value !== 'hidden') {
            $value = 'all';
        }

        return $value;
    }

    private function normalizeAdminCharactersOnlineFilter($raw): string
    {
        $value = strtolower(trim((string) $raw));
        if ($value !== 'online' && $value !== 'offline') {
            $value = 'all';
        }

        return $value;
    }

    private function normalizeAdminCharactersOrderBy($raw): array
    {
        $map = [
            'name' => 'characters.name',
            'date_created' => 'characters.date_created',
            'date_last_signin' => 'characters.date_last_signin',
            'date_last_seed' => 'characters.date_last_seed',
            'is_visible' => 'characters.is_visible',
        ];

        $defaultField = 'name';
        $defaultDir = 'ASC';

        $parts = explode('|', (string) $raw);
        $field = trim((string) ($parts[0] ?? ''));
        $dir = strtoupper(trim((string) ($parts[1] ?? $defaultDir)));

        if (!isset($map[$field])) {
            $field = $defaultField;
        }
        if ($dir !== 'DESC') {
            $dir = 'ASC';
        }

        return [
            'raw' => $field . '|' . $dir,
            'sql' => ' ORDER BY ' . $map[$field] . ' ' . $dir . ', characters.id DESC',
        ];
    }

    private function emitLogGridResponse($query, array $result, int $fallbackPage, int $fallbackResults, string $fallbackOrderBy): void
    {
        $dataset = isset($result['dataset']) && is_array($result['dataset']) ? $result['dataset'] : [];

        $totCount = 0;
        if (isset($result['count']) && is_object($result['count']) && isset($result['count']->count)) {
            $totCount = (int) $result['count']->count;
        }

        $page = isset($result['page']) ? (int) $result['page'] : $fallbackPage;
        if ($page < 1) {
            $page = 1;
        }

        $results = isset($result['results']) ? (int) $result['results'] : $fallbackResults;
        if ($results < 1) {
            $results = $fallbackResults;
        }

        $orderBy = isset($result['orderBy']) && is_string($result['orderBy']) && trim($result['orderBy']) !== ''
            ? trim($result['orderBy'])
            : $fallbackOrderBy;

        $this->emitJson([
            'success' => true,
            'properties' => [
                'query' => $query,
                'page' => $page,
                'results_page' => $results,
                'orderBy' => $orderBy,
                'tot' => (object) ['count' => max(0, $totCount)],
            ],
            'dataset' => $dataset,
        ]);
    }

    private function assertCanViewCharacterLogs(int $viewerCharacterId, int $targetCharacterId): void
    {
        if ($targetCharacterId <= 0) {
            $this->failValidation('Personaggio non valido', 'character_invalid');
        }

        if ($viewerCharacterId === $targetCharacterId) {
            return;
        }

        if ($this->isStaffUser()) {
            return;
        }

        $this->failUnauthorized('Operazione non autorizzata', 'profile_logs_forbidden');
    }

    private function requestDataObject($default = null, $preserveNull = false)
    {
        $request = RequestData::fromGlobals();
        if ($default === null) {
            $default = (object) [];
        }
        if ($preserveNull) {
            $data = $request->postJson('data', null, false);
            if ($data === null) {
                return null;
            }
        } else {
            $data = InputValidator::postJsonObject($request, 'data', true);
        }
        if (!is_object($data)) {
            $data = (object) [];
        }
        return $data;
    }

    private function cryptKey(): string
    {
        if (defined('DB') && isset(DB['crypt_key'])) {
            return (string) DB['crypt_key'];
        }

        return '';
    }

    private function getNameChangeCooldownDays(): int
    {
        $cooldownDays = (int) $this->getSessionValue('config_name_change_cooldown_days');
        if ($cooldownDays <= 0) {
            $cooldownDays = 30;
        }
        return $cooldownDays;
    }

    private function getLoanfaceChangeCooldownDays(): int
    {
        $cooldownDays = (int) $this->getSessionValue('config_loanface_change_cooldown_days');
        if ($cooldownDays <= 0) {
            $cooldownDays = 90;
        }
        return $cooldownDays;
    }

    private function getAvailabilitySeedIntervalSeconds(): int
    {
        $interval = (int) $this->getSessionValue('config_availability_seed_interval_seconds');
        if ($interval <= 0) {
            $interval = 300;
        }
        return $interval;
    }

    private function getAvailabilitySeedCheckedAt(): ?int
    {
        $last = $this->getSessionValue('availability_seed_checked_at');
        if ($last === null || $last === '') {
            return null;
        }
        return (int) $last;
    }

    private function setAvailabilitySeedCheckedAt(int $timestamp): void
    {
        $this->setSessionValue('availability_seed_checked_at', $timestamp);
    }

    private function getSessionLastLocationId(): int
    {
        return (int) $this->getSessionValue('character_last_location');
    }

    private function getSessionLastMapId(): int
    {
        return (int) $this->getSessionValue('character_last_map');
    }

    private function getViewerPresenceContext(): array
    {
        return [
            'character_id' => (int) $this->getSessionValue('character_id'),
            'is_administrator' => ((int) $this->getSessionValue('user_is_administrator') === 1) ? 1 : 0,
            'is_moderator' => ((int) $this->getSessionValue('user_is_moderator') === 1) ? 1 : 0,
            'is_master' => ((int) $this->getSessionValue('user_is_master') === 1) ? 1 : 0,
        ];
    }

    public function getByID($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);

        $characterId = \Core\AuthGuard::api()->requireCharacter();
        $post = $this->requestDataObject((object) [], true);
        if ($post === null) {
            $post = (object) [];
        }

        if (empty($post->id)) {
            $this->failValidation('Personaggio non valido', 'character_invalid');
        }

        $response = parent::getByID(false);
        $dataset = $response['dataset'] ?? null;
        if (!empty($dataset)) {
            $status = $this->characterStateService()->syncSocialStatus(
                (int) $dataset->id,
                $dataset->fame ?? 0,
                $dataset->socialstatus_id ?? null,
            );
            if (!empty($status)) {
                $dataset->socialstatus_id = $status->id;
                $dataset->socialstatus_name = $status->name;
                $dataset->socialstatus_description = $status->description;
                $dataset->socialstatus_icon = $status->icon;
                $dataset->socialstatus_shop_discount = $status->shop_discount;
                $dataset->socialstatus_unlock_home = $status->unlock_home;
                $dataset->socialstatus_quest_tier = $status->quest_tier;
            }

            $dataset->guilds = $this->characterStateService()->getGuildMemberships((int) $dataset->id);
            $dataset->currency_default = $this->characterStateService()->getDefaultCurrency();
            $dataset->wallets = $this->characterStateService()->getWallets((int) $dataset->id);

            $canViewRegistrationDate = ((int) $characterId === (int) $dataset->id) || $this->isAdminOrModerator();
            if (!$canViewRegistrationDate) {
                $dataset->date_created = null;
            }

            if ((int) $characterId === (int) $dataset->id) {
                $dataset->name_request = $this->characterStateService()->getLatestNameRequest((int) $dataset->id);
                $dataset->name_request_cooldown_days = $this->getNameChangeCooldownDays();
                $dataset->loanface_request = $this->characterStateService()->getLatestLoanfaceRequest((int) $dataset->id);
                $dataset->loanface_request_cooldown_days = $this->getLoanfaceChangeCooldownDays();
                $dataset->identity_request = $this->characterStateService()->getLatestIdentityRequest((int) $dataset->id);
                $userId = \Core\AuthGuard::api()->requireUser();
                $revokedAt = $this->characterStateService()->getUserSessionsRevokedAt((int) $userId);
                if ($revokedAt !== null) {
                    $dataset->date_sessions_revoked = $revokedAt;
                }
            }

            $dataset = $this->characterAttributesFacadeService()->decorateCharacterDataset($dataset);
        }

        $response['dataset'] = $dataset;

        if (true == $echo) {
            $this->emitJson($response);
        }

        return $response;
    }

    public function setSocialStatus()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);

        \Core\AuthGuard::api()->requireAbility('settings.manage', [], 'Operazione non autorizzata');
        $userId = \Core\AuthGuard::api()->requireUser();

        $data = $this->requestDataObject((object) [], true);
        if ($data === null) {
            $this->failValidation('Dati mancanti', 'payload_missing');
        }
        $character_id = InputValidator::positiveInt($data, 'character_id', 'Dati mancanti', 'payload_missing');
        $status_id = InputValidator::positiveInt($data, 'socialstatus_id', 'Dati mancanti', 'payload_missing');
        $reason = InputValidator::string($data, 'reason', '');
        if ($reason === '') {
            $reason = null;
        }

        $result = $this->characterStateService()->setSocialStatusByAdmin(
            $character_id,
            $status_id,
            $reason,
            (int) $userId,
        );

        $this->emitJson([
            'success' => true,
            'character_id' => $result['character_id'],
            'socialstatus_id' => $result['socialstatus_id'],
            'fame' => $result['fame'],
        ]);
    }

    public function listSocialStatus($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);

        \Core\AuthGuard::api()->requireAbility('settings.manage', [], 'Operazione non autorizzata');

        $rows = $this->characterStateService()->listSocialStatuses();

        $response = [
            'dataset' => $rows,
        ];

        if (true == $echo) {
            $this->emitJson($response);
        }

        return $response;
    }

    public function create()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);

        $data = $this->requestDataObject();
        $this->checkDataset($data);

        return $this;
    }

    public function update()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);

        $data = $this->requestDataObject();
        $this->checkDataset($data);

        return $this;
    }

    public function updateProfile()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);

        $guard = \Core\AuthGuard::api();
        $guard->requireUser();
        $character_id = $guard->requireCharacter();

        $data = $this->requestDataObject((object) [], true);
        if ($data === null) {
            $data = (object) [];
        }

        if (!empty($data->id) && (int) $data->id !== $character_id) {
            $this->failUnauthorized('Operazione non autorizzata', 'profile_forbidden');
        }

        $result = $this->characterProfileService()->updateProfile($character_id, $data);
        if (empty($result['updated'])) {
            $this->emitJson([
                'success' => true,
                'message' => 'Nessuna modifica',
            ]);
            return;
        }

        $this->emitJson([
            'success' => true,
        ]);
    }

    public function getFriendsKnowledgeHtml()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);

        $guard = \Core\AuthGuard::api();
        $guard->requireUserCharacter();
        $character_id = $guard->requireCharacter();

        $data = $this->requestDataObject((object) [], true);
        if ($data === null) {
            $data = (object) [];
        }

        $target_id = InputValidator::integer($data, 'id', (int) $character_id);
        if ($target_id <= 0) {
            $this->failValidation('Personaggio non valido', 'character_invalid');
        }

        $html = $this->characterProfileService()->getFriendsKnowledgeHtml((int) $target_id);

        $this->emitJson([
            'success' => true,
            'dataset' => [
                'friends_knowledge_html' => $html,
            ],
        ]);
    }

    public function updateFriendsKnowledgeHtml()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);

        $guard = \Core\AuthGuard::api();
        $guard->requireUser();
        $character_id = $guard->requireCharacter();

        $data = $this->requestDataObject((object) [], true);
        if ($data === null) {
            $this->failValidation('Dati mancanti', 'payload_missing');
        }

        if (!empty($data->id) && (int) $data->id !== (int) $character_id) {
            $this->failUnauthorized('Operazione non autorizzata', 'profile_forbidden');
        }

        if (!property_exists($data, 'friends_knowledge_html')) {
            $this->failValidation('Contenuto non valido', 'friends_knowledge_invalid');
        }

        $this->characterProfileService()->updateFriendsKnowledgeHtml((int) $character_id, $data->friends_knowledge_html);

        $this->emitJson([
            'success' => true,
        ]);
    }

    public function listBonds()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);

        $guard = \Core\AuthGuard::api();
        $guard->requireUserCharacter();
        $character_id = $guard->requireCharacter();

        $data = $this->requestDataObject((object) [], true);
        if ($data === null) {
            $data = (object) [];
        }

        $target_id = InputValidator::integer($data, 'character_id', (int) $character_id);
        if ($target_id <= 0) {
            $this->failValidation('Personaggio non valido', 'character_invalid');
        }

        $dataset = $this->characterBondService()->listBondsForProfile((int) $character_id, (int) $target_id);

        $this->emitJson([
            'success' => true,
            'dataset' => $dataset,
        ]);
    }

    public function requestBond()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);

        $guard = \Core\AuthGuard::api();
        $guard->requireUserCharacter();
        $character_id = $guard->requireCharacter();

        $data = $this->requestDataObject((object) [], true);
        if ($data === null) {
            $this->failValidation('Dati mancanti', 'payload_missing');
        }

        $targetId = InputValidator::positiveInt($data, 'target_id', 'Personaggio target non valido', 'character_invalid');

        $requestedType = property_exists($data, 'requested_type') ? InputValidator::string($data, 'requested_type', '') : null;
        if ($requestedType === '') {
            $requestedType = null;
        }
        $message = property_exists($data, 'message') ? InputValidator::string($data, 'message', '') : null;
        if ($message === '') {
            $message = null;
        }

        $result = $this->characterBondService()->createBondRequest(
            (int) $character_id,
            $targetId,
            InputValidator::string($data, 'action_type', 'create'),
            $requestedType,
            $message,
        );

        $this->emitJson([
            'success' => true,
            'dataset' => $result,
        ]);
    }

    public function respondBondRequest()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);

        $guard = \Core\AuthGuard::api();
        $guard->requireUserCharacter();
        $character_id = $guard->requireCharacter();

        $data = $this->requestDataObject((object) [], true);
        if ($data === null) {
            $this->failValidation('Dati mancanti', 'payload_missing');
        }

        $requestId = InputValidator::integer($data, 'request_id', 0);
        if ($requestId <= 0) {
            $this->failValidation('Richiesta non valida', 'bond_request_invalid');
        }

        $decision = InputValidator::string($data, 'decision', '');
        if ($decision === '') {
            $this->failValidation('Decisione non valida', 'bond_request_decision_invalid');
        }

        $result = $this->characterBondService()->respondBondRequest(
            (int) $character_id,
            $requestId,
            $decision,
        );

        $this->emitJson([
            'success' => true,
            'dataset' => $result,
        ]);
    }
    public function experienceLogs()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);

        $guard = \Core\AuthGuard::api();
        $guard->requireUserCharacter();
        $viewerCharacterId = (int) $guard->requireCharacter();

        $post = $this->requestDataObject((object) [], true);
        if ($post === null) {
            $post = (object) [];
        }

        [$page, $results, $orderBy, $query] = $this->parseLogGridRequest($post, 'date_created|DESC', 10);

        $targetCharacterId = $this->resolveLogTargetCharacterId($post, $viewerCharacterId);
        $this->assertCanViewCharacterLogs($viewerCharacterId, $targetCharacterId);

        $result = $this->characterProfileService()->listExperienceLogs((int) $targetCharacterId, $page, $results, $orderBy);
        $this->emitLogGridResponse($query, $result, $page, $results, $orderBy);
    }

    public function economyLogs()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);

        $guard = \Core\AuthGuard::api();
        $guard->requireUserCharacter();
        $viewerCharacterId = (int) $guard->requireCharacter();

        $post = $this->requestDataObject((object) [], true);
        if ($post === null) {
            $post = (object) [];
        }

        [$page, $results, $orderBy, $query] = $this->parseLogGridRequest($post, 'date_created|DESC', 10);

        $targetCharacterId = $this->resolveLogTargetCharacterId($post, $viewerCharacterId);
        $this->assertCanViewCharacterLogs($viewerCharacterId, $targetCharacterId);

        $result = $this->characterProfileService()->listEconomyLogs((int) $targetCharacterId, $page, $results, $orderBy);
        $this->emitLogGridResponse($query, $result, $page, $results, $orderBy);
    }

    public function sessionLogs()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);

        $guard = \Core\AuthGuard::api();
        $guard->requireUserCharacter();
        $viewerCharacterId = (int) $guard->requireCharacter();

        if (!$this->isAdminOrModerator()) {
            $this->failUnauthorized('Operazione non autorizzata', 'profile_session_logs_forbidden');
        }

        $post = $this->requestDataObject((object) [], true);
        if ($post === null) {
            $post = (object) [];
        }

        [$page, $results, $orderBy, $query] = $this->parseLogGridRequest($post, 'date_created|DESC', 10);

        $targetCharacterId = $this->resolveLogTargetCharacterId($post, $viewerCharacterId);
        $this->assertCanViewCharacterLogs($viewerCharacterId, $targetCharacterId);

        $result = $this->characterProfileService()->listSigninSignoutLogs((int) $targetCharacterId, $page, $results, $orderBy);
        $this->emitLogGridResponse($query, $result, $page, $results, $orderBy);
    }

    public function updateMasterNotes()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);

        $guard = \Core\AuthGuard::api();
        $guard->requireUserCharacter();
        $actorUserId = (int) $guard->requireUser();
        $viewerCharacterId = (int) $guard->requireCharacter();

        if (!$this->isStaffUser()) {
            $this->failUnauthorized('Operazione non autorizzata', 'profile_master_notes_forbidden');
        }

        $data = $this->requestDataObject((object) [], true);
        if ($data === null) {
            $this->failValidation('Dati mancanti', 'payload_missing');
        }

        $targetCharacterId = InputValidator::integer($data, 'character_id', 0);
        if ($targetCharacterId <= 0) {
            $targetCharacterId = InputValidator::integer($data, 'id', (int) $viewerCharacterId);
        }

        if ($targetCharacterId <= 0) {
            $this->failValidation('Personaggio non valido', 'character_invalid');
        }

        if (!property_exists($data, 'mod_status')) {
            $this->failValidation('Contenuto non valido', 'profile_master_notes_invalid');
        }

        $result = $this->characterProfileService()->updateMasterNotes((int) $targetCharacterId, $data->mod_status);

        if (!empty($result['updated'])) {
            AuditLogService::write(
                'profile',
                'master_notes_update',
                [
                    'target_character_id' => (int) $targetCharacterId,
                    'previous_mod_status' => $result['previous_mod_status'] ?? null,
                    'new_mod_status' => $result['mod_status'] ?? null,
                    'actor_user_id' => (int) $actorUserId,
                    'actor_character_id' => (int) $viewerCharacterId,
                    'actor_roles' => [
                        'is_administrator' => ((int) $this->getSessionValue('user_is_administrator') === 1),
                        'is_moderator' => ((int) $this->getSessionValue('user_is_moderator') === 1),
                        'is_master' => ((int) $this->getSessionValue('user_is_master') === 1),
                    ],
                ],
                'game/profile',
                \Core\Router::currentUri(),
                (int) $actorUserId,
            );
        }

        $this->emitJson([
            'success' => true,
            'dataset' => $result,
        ]);
    }

    public function updateHealth()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);

        $guard = \Core\AuthGuard::api();
        $guard->requireUserCharacter();
        $actorUserId = (int) $guard->requireUser();
        $viewerCharacterId = (int) $guard->requireCharacter();

        if (!$this->isStaffUser()) {
            $this->failUnauthorized('Operazione non autorizzata', 'profile_health_forbidden');
        }

        $data = $this->requestDataObject((object) [], true);
        if ($data === null) {
            $this->failValidation('Dati mancanti', 'payload_missing');
        }

        $targetCharacterId = InputValidator::integer($data, 'character_id', 0);
        if ($targetCharacterId <= 0) {
            $targetCharacterId = InputValidator::integer($data, 'id', (int) $viewerCharacterId);
        }

        if ($targetCharacterId <= 0) {
            $this->failValidation('Personaggio non valido', 'character_invalid');
        }

        if (!property_exists($data, 'health')) {
            $this->failValidation('Valore salute non valido', 'profile_health_invalid');
        }

        $healthMax = property_exists($data, 'health_max') ? InputValidator::string($data, 'health_max', '') : null;
        if ($healthMax === '') {
            $healthMax = null;
        }
        $result = $this->characterProfileService()->updateHealth((int) $targetCharacterId, $data->health, $healthMax);

        if (!empty($result['updated'])) {
            AuditLogService::write(
                'profile',
                'health_update',
                [
                    'target_character_id' => (int) $targetCharacterId,
                    'previous_health' => $result['previous_health'] ?? null,
                    'new_health' => $result['health'] ?? null,
                    'previous_health_max' => $result['previous_health_max'] ?? null,
                    'new_health_max' => $result['health_max'] ?? null,
                    'actor_user_id' => (int) $actorUserId,
                    'actor_character_id' => (int) $viewerCharacterId,
                    'actor_roles' => [
                        'is_administrator' => ((int) $this->getSessionValue('user_is_administrator') === 1),
                        'is_moderator' => ((int) $this->getSessionValue('user_is_moderator') === 1),
                        'is_master' => ((int) $this->getSessionValue('user_is_master') === 1),
                    ],
                ],
                'game/profile',
                \Core\Router::currentUri(),
                (int) $actorUserId,
            );
        }

        $this->emitJson([
            'success' => true,
            'dataset' => $result,
        ]);
    }

    public function assignExperience()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);

        $guard = \Core\AuthGuard::api();
        $guard->requireUserCharacter();
        $actorUserId = (int) $guard->requireUser();
        $viewerCharacterId = (int) $guard->requireCharacter();

        if (!$this->isStaffUser()) {
            $this->failUnauthorized('Operazione non autorizzata', 'profile_experience_assign_forbidden');
        }

        $data = $this->requestDataObject((object) [], true);
        if ($data === null) {
            $this->failValidation('Dati mancanti', 'payload_missing');
        }

        $targetCharacterId = InputValidator::integer($data, 'character_id', 0);
        if ($targetCharacterId <= 0) {
            $targetCharacterId = InputValidator::integer($data, 'id', (int) $viewerCharacterId);
        }

        if ($targetCharacterId <= 0) {
            $this->failValidation('Personaggio non valido', 'character_invalid');
        }

        if (!property_exists($data, 'delta')) {
            $this->failValidation('Quantita esperienza non valida', 'profile_experience_delta_invalid');
        }

        if (!property_exists($data, 'reason')) {
            $this->failValidation('Motivazione non valida', 'profile_experience_reason_invalid');
        }

        $result = $this->characterProfileService()->assignExperience(
            (int) $targetCharacterId,
            (int) $actorUserId,
            InputValidator::integer($data, 'delta', 0),
            InputValidator::string($data, 'reason', ''),
            'staff_assignment',
        );

        if (!empty($result['updated'])) {
            AuditLogService::write(
                'profile',
                'experience_assign',
                [
                    'target_character_id' => (int) $targetCharacterId,
                    'delta' => $result['delta'] ?? null,
                    'reason' => $result['reason'] ?? null,
                    'experience_before' => $result['experience_before'] ?? null,
                    'experience_after' => $result['experience_after'] ?? null,
                    'source' => $result['source'] ?? null,
                    'actor_user_id' => (int) $actorUserId,
                    'actor_character_id' => (int) $viewerCharacterId,
                    'actor_roles' => [
                        'is_administrator' => ((int) $this->getSessionValue('user_is_administrator') === 1),
                        'is_moderator' => ((int) $this->getSessionValue('user_is_moderator') === 1),
                        'is_master' => ((int) $this->getSessionValue('user_is_master') === 1),
                    ],
                ],
                'game/profile',
                \Core\Router::currentUri(),
                (int) $actorUserId,
            );
        }

        $this->emitJson([
            'success' => true,
            'dataset' => $result,
        ]);
    }
    public function setAvailability()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);

        $character_id = \Core\AuthGuard::api()->requireCharacter();
        $data = $this->requestDataObject((object) [], true);
        if ($data === null) {
            $data = (object) [];
        }
        $this->characterProfileService()->setAvailability($character_id, $data);

        $this->emitJson([
            'success' => true,
        ]);
    }

    public function setVisibility()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);

        $guard = \Core\AuthGuard::api();
        $guard->requireAbility('location.invisible', [], 'Operazione non autorizzata');
        $character_id = $guard->requireCharacter();

        $data = $this->requestDataObject((object) [], true);
        if ($data === null) {
            $data = (object) [];
        }

        if (InputValidator::string($data, 'mode', '') === 'get') {
            $value = $this->characterStateService()->getVisibility((int) $character_id);
            $this->emitJson([
                'success' => true,
                'is_visible' => $value,
            ]);
            return;
        }

        $is_visible = null;
        if (property_exists($data, 'is_visible')) {
            $is_visible = InputValidator::boolean($data, 'is_visible', true) ? 1 : 0;
        } else {
            $current = $this->characterStateService()->getVisibility((int) $character_id);
            $is_visible = ($current === 1) ? 0 : 1;
        }

        $this->characterStateService()->setVisibility((int) $character_id, (int) $is_visible);

        $this->emitJson([
            'success' => true,
            'is_visible' => $is_visible,
        ]);
    }

    public function updateSettings()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);

        $character_id = \Core\AuthGuard::api()->requireCharacter();
        $data = $this->requestDataObject((object) [], true);
        if ($data === null) {
            $this->failValidation('Dati mancanti', 'payload_missing');
        }
        $this->characterProfileService()->updateSettings($character_id, $data);

        $this->emitJson([
            'success' => true,
        ]);
    }

    public function requestLoanfaceChange()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);

        $guard = \Core\AuthGuard::api();
        $userId = $guard->requireUser();
        $character_id = $guard->requireCharacter();

        $data = $this->requestDataObject((object) [], true);
        if ($data === null) {
            $this->failValidation('Dati mancanti', 'payload_missing');
        }

        $new_loanface = isset($data->new_loanface) ? (string) $data->new_loanface : '';
        $reason = isset($data->reason) ? (string) $data->reason : null;
        $cooldownDays = $this->getLoanfaceChangeCooldownDays();

        $this->characterProfileService()->requestLoanfaceChange(
            $character_id,
            $userId,
            $new_loanface,
            $reason,
            $cooldownDays,
        );

        $this->emitJson([
            'success' => true,
        ]);
    }

    public function requestNameChange()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);

        $guard = \Core\AuthGuard::api();
        $userId = $guard->requireUser();
        $character_id = $guard->requireCharacter();

        $data = $this->requestDataObject((object) [], true);
        if ($data === null) {
            $this->failValidation('Dati mancanti', 'payload_missing');
        }

        $new_name = isset($data->new_name) ? (string) $data->new_name : '';
        $reason = isset($data->reason) ? (string) $data->reason : null;
        $cooldownDays = $this->getNameChangeCooldownDays();

        $this->characterProfileService()->requestNameChange(
            $character_id,
            $userId,
            $new_name,
            $reason,
            $cooldownDays,
        );

        $this->emitJson([
            'success' => true,
        ]);
    }

    public function adminList()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);

        \Core\AuthGuard::api()->requireAbility('settings.manage', [], 'Operazione non autorizzata');
        $isSuperuser = \Core\AppContext::authContext()->isSuperuser();

        $data = $this->requestDataObject((object) []);
        $query = (isset($data->query) && is_object($data->query)) ? $data->query : (object) [];

        $name = strtolower(InputValidator::string($query, 'name', ''));
        $email = $isSuperuser ? strtolower(InputValidator::string($query, 'email', '')) : '';
        $visibility = $this->normalizeAdminCharactersVisibilityFilter((string) ($query->visibility ?? 'all'));
        $online = $this->normalizeAdminCharactersOnlineFilter((string) ($query->online ?? 'all'));

        $page = max(1, InputValidator::integer($data, 'page', 1));
        $results = InputValidator::integer($data, 'results', 20);
        if ($results < 1) {
            $results = 20;
        } elseif ($results > 100) {
            $results = 100;
        }

        $order = $this->normalizeAdminCharactersOrderBy(InputValidator::string($data, 'orderBy', 'name|ASC'));
        $offset = ($page - 1) * $results;

        $onlineClause = '(characters.date_last_signin IS NOT NULL
            AND characters.date_last_signin > IFNULL(characters.date_last_signout, "1970-01-01 00:00:00")
            AND DATE_ADD(characters.date_last_seed, INTERVAL 20 MINUTE) > NOW())';

        $whereParts = [];
        $whereParams = [];
        if ($name !== '') {
            $whereParts[] = '(LOWER(characters.name) LIKE ?
                OR LOWER(IFNULL(characters.surname, "")) LIKE ?
                OR LOWER(CONCAT(characters.name, " ", IFNULL(characters.surname, ""))) LIKE ?)';
            $like = '%' . $name . '%';
            $whereParams[] = $like;
            $whereParams[] = $like;
            $whereParams[] = $like;
        }
        if ($isSuperuser && $email !== '') {
            $whereParts[] = 'LOWER(CAST(AES_DECRYPT(users.email, ?) AS CHAR(255))) LIKE ?';
            $whereParams[] = $this->cryptKey();
            $whereParams[] = '%' . $email . '%';
        }
        if ($visibility === 'visible') {
            $whereParts[] = 'characters.is_visible = 1';
        } elseif ($visibility === 'hidden') {
            $whereParts[] = 'characters.is_visible = 0';
        }
        if ($online === 'online') {
            $whereParts[] = $onlineClause;
        } elseif ($online === 'offline') {
            $whereParts[] = 'NOT ' . $onlineClause;
        }

        $whereSql = '';
        if (!empty($whereParts)) {
            $whereSql = ' WHERE ' . implode(' AND ', $whereParts);
        }

        $emailSelect = 'NULL AS email';
        $datasetParams = $whereParams;
        if ($isSuperuser) {
            $emailSelect = 'CAST(AES_DECRYPT(users.email, ?) AS CHAR(255)) AS email';
            $datasetParams = array_merge([$this->cryptKey()], $datasetParams);
        }

        $dataset = static::db()->fetchAllPrepared(
            'SELECT characters.id,
                    characters.user_id,
                    characters.name,
                    characters.surname,
                    characters.gender,
                    characters.avatar,
                    characters.is_visible,
                    characters.last_map,
                    characters.last_location,
                    maps.name AS map_name,
                    locations.name AS location_name,
                    ' . $emailSelect . ',
                    characters.date_created,
                    characters.date_last_signin,
                    characters.date_last_signout,
                    characters.date_last_seed,
                    CASE WHEN ' . $onlineClause . ' THEN "online" ELSE "offline" END AS online_status
             FROM characters
             LEFT JOIN users ON characters.user_id = users.id
             LEFT JOIN maps ON characters.last_map = maps.id
             LEFT JOIN locations ON characters.last_location = locations.id
             ' . $whereSql . '
             ' . $order['sql'] . '
             LIMIT ? OFFSET ?',
            array_merge($datasetParams, [$results, $offset]),
        );

        $count = static::db()->fetchOnePrepared(
            'SELECT COUNT(*) AS count
             FROM characters
             LEFT JOIN users ON characters.user_id = users.id
             ' . $whereSql,
            $whereParams,
        );

        $tot = (!empty($count) && isset($count->count)) ? (int) $count->count : 0;

        $this->emitJson([
            'success' => true,
            'properties' => [
                'query' => (object) [
                    'name' => $name,
                    'email' => $email,
                    'visibility' => $visibility,
                    'online' => $online,
                ],
                'page' => $page,
                'results_page' => $results,
                'orderBy' => $order['raw'],
                'tot' => (object) ['count' => max(0, $tot)],
            ],
            'dataset' => !empty($dataset) ? $dataset : [],
        ]);
    }

    public function adminSetVisibility()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);

        \Core\AuthGuard::api()->requireAbility('settings.manage', [], 'Operazione non autorizzata');

        $data = $this->requestDataObject((object) [], true);
        if ($data === null) {
            $this->failValidation('Dati mancanti', 'payload_missing');
        }

        $characterId = InputValidator::integer($data, 'character_id', 0);
        if ($characterId <= 0) {
            $this->failValidation('Personaggio non valido', 'character_invalid');
        }

        $isVisible = null;
        if (property_exists($data, 'is_visible')) {
            $isVisible = InputValidator::boolean($data, 'is_visible', true) ? 1 : 0;
        } else {
            $current = $this->characterStateService()->getVisibility((int) $characterId);
            $isVisible = ($current === 1) ? 0 : 1;
        }

        $this->characterStateService()->setVisibility((int) $characterId, (int) $isVisible);

        $this->emitJson([
            'success' => true,
            'dataset' => [
                'character_id' => (int) $characterId,
                'is_visible' => (int) $isVisible,
            ],
        ]);
    }

    public function adminGet()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);

        \Core\AuthGuard::api()->requireAbility('settings.manage', [], 'Operazione non autorizzata');
        $isSuperuser = \Core\AppContext::authContext()->isSuperuser();

        $data = $this->requestDataObject((object) [], true);
        if ($data === null) {
            $this->failValidation('Dati mancanti', 'payload_missing');
        }

        $characterId = InputValidator::integer($data, 'character_id', 0);
        if ($characterId <= 0) {
            $characterId = InputValidator::integer($data, 'id', 0);
        }

        if ($characterId <= 0) {
            $this->failValidation('Personaggio non valido', 'character_invalid');
        }

        $onlineClause = '(characters.date_last_signin IS NOT NULL
            AND characters.date_last_signin > IFNULL(characters.date_last_signout, "1970-01-01 00:00:00")
            AND DATE_ADD(characters.date_last_seed, INTERVAL 20 MINUTE) > NOW())';

        $emailSelect = 'NULL AS email';
        $params = [$characterId];
        if ($isSuperuser) {
            $emailSelect = 'CAST(AES_DECRYPT(users.email, ?) AS CHAR(255)) AS email';
            $params = [$this->cryptKey(), $characterId];
        }

        $row = static::db()->fetchOnePrepared(
            'SELECT characters.id,
                    characters.user_id,
                    characters.name,
                    characters.surname,
                    characters.gender,
                    characters.avatar,
                    characters.is_visible,
                    characters.last_map,
                    characters.last_location,
                    characters.health,
                    characters.health_max,
                    characters.experience,
                    characters.rank,
                    characters.fame,
                    characters.money,
                    characters.bank,
                    characters.height,
                    characters.weight,
                    characters.eyes,
                    characters.hair,
                    characters.skin,
                    characters.particular_signs,
                    characters.description_body,
                    characters.description_temper,
                    characters.background_story,
                    characters.background_music_url,
                    characters.mod_status,
                    characters.socialstatus_id,
                    characters.date_created,
                    characters.date_last_signin,
                    characters.date_last_signout,
                    characters.date_last_seed,
                    maps.name AS map_name,
                    locations.name AS location_name,
                    ' . $emailSelect . ',
                    CASE WHEN ' . $onlineClause . ' THEN "online" ELSE "offline" END AS online_status
             FROM characters
             LEFT JOIN users ON characters.user_id = users.id
             LEFT JOIN maps ON characters.last_map = maps.id
             LEFT JOIN locations ON characters.last_location = locations.id
             WHERE characters.id = ?
             LIMIT 1',
            $params,
        );

        if (empty($row)) {
            throw \Core\Http\AppError::notFound('Personaggio non trovato', [], 'character_not_found');
        }

        $this->emitJson([
            'success' => true,
            'dataset' => $row,
        ]);
    }

    public function adminExperienceLogs()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);

        \Core\AuthGuard::api()->requireAbility('settings.manage', [], 'Operazione non autorizzata');

        $post = $this->requestDataObject((object) [], true);
        if ($post === null) {
            $post = (object) [];
        }

        [$page, $results, $orderBy, $query] = $this->parseLogGridRequest($post, 'date_created|DESC', 10);
        $targetCharacterId = $this->resolveLogTargetCharacterId($post, 0);
        if ($targetCharacterId <= 0) {
            $this->failValidation('Personaggio non valido', 'character_invalid');
        }
        if (!is_object($query)) {
            $query = (object) [];
        }
        $query->character_id = (int) $targetCharacterId;

        $result = $this->characterProfileService()->listExperienceLogs((int) $targetCharacterId, $page, $results, $orderBy);
        $this->emitLogGridResponse($query, $result, $page, $results, $orderBy);
    }

    public function adminEconomyLogs()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);

        \Core\AuthGuard::api()->requireAbility('settings.manage', [], 'Operazione non autorizzata');

        $post = $this->requestDataObject((object) [], true);
        if ($post === null) {
            $post = (object) [];
        }

        [$page, $results, $orderBy, $query] = $this->parseLogGridRequest($post, 'date_created|DESC', 10);
        $targetCharacterId = $this->resolveLogTargetCharacterId($post, 0);
        if ($targetCharacterId <= 0) {
            $this->failValidation('Personaggio non valido', 'character_invalid');
        }
        if (!is_object($query)) {
            $query = (object) [];
        }
        $query->character_id = (int) $targetCharacterId;

        $result = $this->characterProfileService()->listEconomyLogs((int) $targetCharacterId, $page, $results, $orderBy);
        $this->emitLogGridResponse($query, $result, $page, $results, $orderBy);
    }

    public function adminSessionLogs()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);

        \Core\AuthGuard::api()->requireAbility('settings.manage', [], 'Operazione non autorizzata');

        $post = $this->requestDataObject((object) [], true);
        if ($post === null) {
            $post = (object) [];
        }

        [$page, $results, $orderBy, $query] = $this->parseLogGridRequest($post, 'date_created|DESC', 10);
        $targetCharacterId = $this->resolveLogTargetCharacterId($post, 0);
        if ($targetCharacterId <= 0) {
            $this->failValidation('Personaggio non valido', 'character_invalid');
        }
        if (!is_object($query)) {
            $query = (object) [];
        }
        $query->character_id = (int) $targetCharacterId;

        $result = $this->characterProfileService()->listSigninSignoutLogs((int) $targetCharacterId, $page, $results, $orderBy);
        $this->emitLogGridResponse($query, $result, $page, $results, $orderBy);
    }

    public function listNameRequests()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);

        \Core\AuthGuard::api()->requireAbility('settings.manage', [], 'Operazione non autorizzata');

        $data = $this->requestDataObject((object) []);
        $query = (isset($data->query) && is_object($data->query)) ? $data->query : (object) [];
        $status = InputValidator::firstString($query, ['status'], InputValidator::string($data, 'status', 'pending'));
        if ($status === '') {
            $status = 'pending';
        }

        $limit = InputValidator::integer($data, 'results', 0);
        if ($limit <= 0) {
            $limit = InputValidator::integer($data, 'results_page', 0);
        }
        if ($limit <= 0) {
            $limit = InputValidator::integer($data, 'limit', 20);
        }
        if ($limit < 1) {
            $limit = 20;
        }
        if ($limit > 100) {
            $limit = 100;
        }

        $page = max(1, InputValidator::integer($data, 'page', 1));
        $orderBy = InputValidator::string($data, 'orderBy', 'date_created|ASC');

        $result = $this->characterProfileService()->adminListNameRequests($status, $limit, $page);
        $this->emitJson([
            'success' => true,
            'properties' => [
                'query' => (object) ['status' => $status],
                'page' => (int) ($result['page'] ?? $page),
                'results_page' => (int) ($result['limit'] ?? $limit),
                'orderBy' => $orderBy,
                'tot' => (object) ['count' => (int) ($result['total'] ?? 0)],
            ],
            'dataset' => isset($result['rows']) && is_array($result['rows']) ? $result['rows'] : [],
        ]);
    }

    public function listLoanfaceRequests()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);

        \Core\AuthGuard::api()->requireAbility('settings.manage', [], 'Operazione non autorizzata');

        $data = $this->requestDataObject((object) []);
        $query = (isset($data->query) && is_object($data->query)) ? $data->query : (object) [];
        $status = InputValidator::firstString($query, ['status'], InputValidator::string($data, 'status', 'pending'));
        if ($status === '') {
            $status = 'pending';
        }

        $limit = InputValidator::integer($data, 'results', 0);
        if ($limit <= 0) {
            $limit = InputValidator::integer($data, 'results_page', 0);
        }
        if ($limit <= 0) {
            $limit = InputValidator::integer($data, 'limit', 20);
        }
        if ($limit < 1) {
            $limit = 20;
        }
        if ($limit > 100) {
            $limit = 100;
        }

        $page = max(1, InputValidator::integer($data, 'page', 1));
        $orderBy = InputValidator::string($data, 'orderBy', 'date_created|ASC');

        $result = $this->characterProfileService()->adminListLoanfaceRequests($status, $limit, $page);
        $this->emitJson([
            'success' => true,
            'properties' => [
                'query' => (object) ['status' => $status],
                'page' => (int) ($result['page'] ?? $page),
                'results_page' => (int) ($result['limit'] ?? $limit),
                'orderBy' => $orderBy,
                'tot' => (object) ['count' => (int) ($result['total'] ?? 0)],
            ],
            'dataset' => isset($result['rows']) && is_array($result['rows']) ? $result['rows'] : [],
        ]);
    }

    public function decideNameChange()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);

        \Core\AuthGuard::api()->requireAbility('settings.manage', [], 'Operazione non autorizzata');
        $adminUserId = \Core\AuthGuard::api()->requireUser();

        $data = $this->requestDataObject((object) [], true);
        if ($data === null) {
            $this->failValidation('Dati mancanti', 'payload_missing');
        }
        $requestId = InputValidator::positiveInt($data, 'request_id', 'Dati mancanti', 'payload_missing');
        $decision = InputValidator::string($data, 'decision', '');
        if ($decision === '') {
            $this->failValidation('Dati mancanti', 'payload_missing');
        }

        $result = $this->characterProfileService()->adminDecideNameChange(
            $requestId,
            (int) $adminUserId,
            $decision,
        );

        $this->emitJson(['success' => true, 'request_id' => $result['request_id'], 'decision' => $result['decision']]);
    }

    public function decideLoanfaceChange()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);

        \Core\AuthGuard::api()->requireAbility('settings.manage', [], 'Operazione non autorizzata');
        $adminUserId = \Core\AuthGuard::api()->requireUser();

        $data = $this->requestDataObject((object) [], true);
        if ($data === null) {
            $this->failValidation('Dati mancanti', 'payload_missing');
        }
        $requestId = InputValidator::positiveInt($data, 'request_id', 'Dati mancanti', 'payload_missing');
        $decision = InputValidator::string($data, 'decision', '');
        if ($decision === '') {
            $this->failValidation('Dati mancanti', 'payload_missing');
        }

        $result = $this->characterProfileService()->adminDecideLoanfaceChange(
            $requestId,
            (int) $adminUserId,
            $decision,
        );

        $this->emitJson(['success' => true, 'request_id' => $result['request_id'], 'decision' => $result['decision']]);
    }

    public function adminEditIdentity()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        \Core\AuthGuard::api()->requireAbility('settings.manage', [], 'Operazione non autorizzata');

        $data = $this->requestDataObject((object) [], true);
        if ($data === null) {
            $this->failValidation('Dati mancanti', 'payload_missing');
        }
        $characterId = InputValidator::positiveInt($data, 'character_id', 'Dati mancanti', 'payload_missing');

        $this->characterProfileService()->adminUpdateIdentity($characterId, $data);
        $this->emitJson(['success' => true]);
    }

    public function adminEditNarrative()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        \Core\AuthGuard::api()->requireAbility('settings.manage', [], 'Operazione non autorizzata');

        $data = $this->requestDataObject((object) [], true);
        if ($data === null) {
            $this->failValidation('Dati mancanti', 'payload_missing');
        }
        $characterId = InputValidator::positiveInt($data, 'character_id', 'Dati mancanti', 'payload_missing');

        $this->characterProfileService()->adminUpdateNarrative($characterId, $data);
        $this->emitJson(['success' => true]);
    }

    public function adminEditStats()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $actorUserId = \Core\AuthGuard::api()->requireUser();
        \Core\AuthGuard::api()->requireAbility('settings.manage', [], 'Operazione non autorizzata');

        $data = $this->requestDataObject((object) [], true);
        if ($data === null) {
            $this->failValidation('Dati mancanti', 'payload_missing');
        }

        $characterId = InputValidator::positiveInt($data, 'character_id', 'Dati mancanti', 'payload_missing');

        $health = (property_exists($data, 'health') && trim((string) $data->health) !== '') ? $data->health : null;
        $healthMax = (property_exists($data, 'health_max') && trim((string) $data->health_max) !== '') ? $data->health_max : null;
        if ($health !== null || $healthMax !== null) {
            $this->characterProfileService()->updateHealth($characterId, $health, $healthMax);
        }

        if (property_exists($data, 'experience_delta') && trim((string) $data->experience_delta) !== '') {
            $this->characterProfileService()->assignExperience(
                $characterId,
                (int) $actorUserId,
                InputValidator::integer($data, 'experience_delta', 0),
                InputValidator::string($data, 'experience_reason', 'Modifica admin'),
                'admin_edit',
            );
        }

        if (property_exists($data, 'rank') && trim((string) $data->rank) !== '') {
            $this->characterProfileService()->adminUpdateRank($characterId, InputValidator::integer($data, 'rank', 0));
        }

        $this->emitJson(['success' => true]);
    }

    public function adminEditEconomy()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $actorUserId = \Core\AuthGuard::api()->requireUser();
        \Core\AuthGuard::api()->requireAbility('settings.manage', [], 'Operazione non autorizzata');

        $data = $this->requestDataObject((object) [], true);
        if ($data === null) {
            $this->failValidation('Dati mancanti', 'payload_missing');
        }

        $characterId = InputValidator::positiveInt($data, 'character_id', 'Dati mancanti', 'payload_missing');
        $this->characterProfileService()->adminUpdateEconomy($characterId, $data);

        $socialStatusId = InputValidator::integer($data, 'social_status_id', 0);
        if ($socialStatusId > 0) {
            $this->characterStateService()->setSocialStatusByAdmin($characterId, $socialStatusId, null, (int) $actorUserId);
        }

        $this->emitJson(['success' => true]);
    }

    public function adminEditNotes()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        \Core\AuthGuard::api()->requireAbility('settings.manage', [], 'Operazione non autorizzata');

        $data = $this->requestDataObject((object) [], true);
        if ($data === null) {
            $this->failValidation('Dati mancanti', 'payload_missing');
        }
        $characterId = InputValidator::positiveInt($data, 'character_id', 'Dati mancanti', 'payload_missing');

        if (!property_exists($data, 'mod_status')) {
            $this->failValidation('Contenuto non valido', 'admin_edit_notes_invalid');
        }

        $this->characterProfileService()->updateMasterNotes($characterId, $data->mod_status);
        $this->emitJson(['success' => true]);
    }

    public function requestIdentityChange()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);

        $guard = \Core\AuthGuard::api();
        $userId = $guard->requireUser();
        $character_id = $guard->requireCharacter();

        $data = $this->requestDataObject((object) [], true);
        if ($data === null) {
            $this->failValidation('Dati mancanti', 'payload_missing');
        }

        $this->characterProfileService()->requestIdentityChange(
            $character_id,
            $userId,
            $data,
        );

        $this->emitJson(['success' => true]);
    }

    public function listIdentityRequests()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);

        \Core\AuthGuard::api()->requireAbility('settings.manage', [], 'Operazione non autorizzata');

        $data = $this->requestDataObject((object) []);
        $query = (isset($data->query) && is_object($data->query)) ? $data->query : (object) [];
        $status = InputValidator::firstString($query, ['status'], InputValidator::string($data, 'status', 'pending'));
        if ($status === '') {
            $status = 'pending';
        }

        $limit = InputValidator::integer($data, 'results', 0);
        if ($limit <= 0) {
            $limit = InputValidator::integer($data, 'limit', 20);
        }
        if ($limit < 1) {
            $limit = 20;
        }
        if ($limit > 100) {
            $limit = 100;
        }

        $page = max(1, InputValidator::integer($data, 'page', 1));
        $orderBy = InputValidator::string($data, 'orderBy', 'date_created|ASC');

        $result = $this->characterProfileService()->adminListIdentityRequests($status, $limit, $page);
        $this->emitJson([
            'success' => true,
            'properties' => [
                'query' => (object) ['status' => $status],
                'page' => (int) ($result['page'] ?? $page),
                'results_page' => (int) ($result['limit'] ?? $limit),
                'orderBy' => $orderBy,
                'tot' => (object) ['count' => (int) ($result['total'] ?? 0)],
            ],
            'dataset' => isset($result['rows']) && is_array($result['rows']) ? $result['rows'] : [],
        ]);
    }

    public function decideIdentityChange()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);

        \Core\AuthGuard::api()->requireAbility('settings.manage', [], 'Operazione non autorizzata');
        $adminUserId = \Core\AuthGuard::api()->requireUser();

        $data = $this->requestDataObject((object) [], true);
        if ($data === null) {
            $this->failValidation('Dati mancanti', 'payload_missing');
        }
        $requestId = InputValidator::positiveInt($data, 'request_id', 'Dati mancanti', 'payload_missing');
        $decision = InputValidator::string($data, 'decision', '');
        if ($decision === '') {
            $this->failValidation('Dati mancanti', 'payload_missing');
        }

        $result = $this->characterProfileService()->adminDecideIdentityChange(
            $requestId,
            (int) $adminUserId,
            $decision,
        );

        $this->emitJson(['success' => true, 'request_id' => $result['request_id'], 'decision' => $result['decision']]);
    }

    public function requestDelete()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);

        $guard = \Core\AuthGuard::api();
        $userId = $guard->requireUser();
        $character_id = $guard->requireCharacter();

        $data = $this->requestDataObject((object) [], true);
        if ($data === null) {
            $this->failValidation('Password non valida', 'password_invalid');
        }
        $password = InputValidator::string($data, 'password', '');
        if ($password === '') {
            $this->failValidation('Password non valida', 'password_invalid');
        }

        (new UserService())->assertPasswordValid((int) $userId, $password);
        $scheduledAt = $this->characterStateService()->requestDelete((int) $character_id);

        $this->emitJson([
            'success' => true,
            'delete_scheduled_at' => $scheduledAt,
        ]);
    }

    public function cancelDelete()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);

        $character_id = \Core\AuthGuard::api()->requireCharacter();
        $this->characterStateService()->cancelDelete((int) $character_id);

        $this->emitJson([
            'success' => true,
        ]);
    }

    public function onlines($echo = true)
    {
        $this->syncAvailabilityBySeed();
        $viewer = $this->getViewerPresenceContext();
        $location = $this->getSessionLastLocationId();
        $map = $this->getSessionLastMapId();
        \Core\AuthGuard::releaseSession();

        $dataset = $this->characterDirectoryService()->getOnlineSummary(
            $location,
            $map,
            $viewer,
        );
        $dataset['viewer_location_id'] = $location;
        $dataset['viewer_map_id'] = $map;

        $response = [
            'dataset' => $dataset,
        ];

        if (true == $echo) {
            $this->emitJson($response);
        }

        return $response;
    }

    public function onlinesComplete($echo = true)
    {
        $this->syncAvailabilityBySeed();

        \Core\AuthGuard::api()->requireCharacter();
        $viewer = $this->getViewerPresenceContext();
        \Core\AuthGuard::releaseSession();
        $dataset = $this->characterDirectoryService()->getOnlineComplete($viewer);

        $response = [
            'dataset' => $dataset,
        ];

        if (true == $echo) {
            $this->emitJson($response);
        }

        return $response;
    }

    public function search($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $me = \Core\AuthGuard::api()->requireCharacter();

        $post = $this->requestDataObject((object) [], true);
        if ($post === null) {
            $post = (object) [];
        }
        $query = InputValidator::string($post, 'query', '');

        if ($query === '' || strlen($query) < 2) {
            $response = [
                'dataset' => [],
            ];
            if (true == $echo) {
                $this->emitJson($response);
            }

            return $response;
        }

        $requestedLocationId = InputValidator::integer($post, 'location_id', 0);
        $searchLocationId = null;
        if ($requestedLocationId > 0) {
            if ($this->isStaffUser()) {
                $searchLocationId = $requestedLocationId;
            } else {
                $sessionLocationId = $this->getSessionLastLocationId();
                $searchLocationId = ($sessionLocationId > 0) ? $sessionLocationId : null;
            }
        }

        $dataset = $this->characterDirectoryService()->search((int) $me, (string) $query, 10, $searchLocationId, $this->isStaffUser());

        $response = [
            'dataset' => $dataset,
        ];

        if (true == $echo) {
            $this->emitJson($response);
        }

        return $response;
    }

    private function syncAvailabilityBySeed()
    {
        $now = (int) $this->getServerValue('REQUEST_TIME', time());
        $last = $this->getAvailabilitySeedCheckedAt();
        $interval = $this->getAvailabilitySeedIntervalSeconds();
        if (!empty($last) && ($now - (int) $last) < $interval) {
            return;
        }

        $idleMinutes = $this->characterDirectoryService()->resolveIdleMinutes();
        $this->characterDirectoryService()->syncAvailabilityByIdleMinutes($idleMinutes);

        $this->setAvailabilitySeedCheckedAt($now);
    }

    private function emitJson(array $payload): void
    {
        ResponseEmitter::emit(ApiResponse::json($payload));
    }

    protected function checkDataset($dataset)
    {
    }
}
