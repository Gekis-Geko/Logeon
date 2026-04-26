<?php

declare(strict_types=1);

use App\Models\Location;
use App\Services\LocationAdminService;
use App\Services\LocationService;
use Core\Http\ApiResponse;
use Core\Http\AppError;
use Core\Http\InputValidator;
use Core\Http\RequestData;
use Core\Http\ResponseEmitter;

use Core\Logging\LoggerInterface;

use Core\RateLimiter;

class Locations extends Location
{
    /** @var LoggerInterface|null */
    private $logger = null;
    /** @var LocationService|null */
    private $locationService = null;
    /** @var LocationAdminService|null */
    private $locationAdminService = null;

    public function setLogger(LoggerInterface $logger = null)
    {
        $this->logger = $logger;
        return $this;
    }

    public function setLocationService(LocationService $locationService = null)
    {
        $this->locationService = $locationService;
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

    private function locationService(): LocationService
    {
        if ($this->locationService instanceof LocationService) {
            return $this->locationService;
        }

        $this->locationService = new LocationService();
        return $this->locationService;
    }

    protected function trace($message, $context = false): void
    {
        $this->logger()->trace($message, $context);
    }

    private function failValidation($message, string $errorCode = 'validation_error')
    {
        throw AppError::validation((string) $message, [], $errorCode);
    }

    private function locationAdminService(): LocationAdminService
    {
        if ($this->locationAdminService instanceof LocationAdminService) {
            return $this->locationAdminService;
        }
        $this->locationAdminService = new LocationAdminService();
        return $this->locationAdminService;
    }

    private function requestDataObject()
    {
        $request = RequestData::fromGlobals();
        $data = $request->postJson('data', (object) [], false);
        if (!is_object($data)) {
            $data = (object) [];
        }
        return $data;
    }

    private function requireAdmin()
    {
        \Core\AuthGuard::api()->requireAbility('settings.manage');
    }

    private function enforceRate($bucket, $limit, $windowSeconds, $identifier, $message = null, string $errorCode = 'rate_limited')
    {
        $rate = RateLimiter::hit($bucket, $limit, $windowSeconds, $identifier);
        if (!empty($rate['allowed'])) {
            return;
        }

        if ($message === null || trim((string) $message) === '') {
            $message = 'Troppi tentativi. Riprova tra ' . (int) $rate['retry_after'] . ' secondi';
        } else {
            $message .= ' Riprova tra ' . (int) $rate['retry_after'] . ' secondi';
        }
        $this->failValidation($message, $errorCode);
    }

    public function list($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $character_id = \Core\AuthGuard::api()->requireCharacter();

        $request = RequestData::fromGlobals();
        $post = $request->postJson('data', (object) [], false);
        if ($post === null) {
            $post = (object) [];
        }
        if (!isset($post->query)) {
            $post->query = [];
        } elseif (is_object($post->query)) {
            $post->query = (array) $post->query;
        }
        $post->query[] = 'locations.date_deleted IS NULL';
        $post->cache = false;
        $post->cache_ttl = 0;
        $response = $this->listWithPayload($post, false);
        $dataset = $response['dataset'] ?? [];

        if (!empty($dataset)) {
            $character = $this->getCharacter($character_id);
            $invited = $this->getAcceptedInvites($character_id);
            $guildAccess = $this->getGuildAccessSet($character_id);

            foreach ($dataset as $location) {
                $access = $this->evaluateAccess($location, $character, $invited, $guildAccess);
                $location->access = $access['allowed'];
                $location->access_reason = $access['reason'];
                $location->access_reason_code = $access['reason_code'];
                $location->is_owner = $access['is_owner'] ? 1 : 0;
                $location->is_invited = $access['is_invited'] ? 1 : 0;
                $location->is_full = $access['is_full'] ? 1 : 0;
                $location->guests_count = $access['guests_count'];
            }
        }

        $response['dataset'] = $dataset;

        if ($echo) {
            ResponseEmitter::emit(ApiResponse::json($response));
        }

        return $response;
    }

    public function adminList()
    {
        $this->requireAdmin();

        $request = RequestData::fromGlobals();
        $post = $request->postJson('data', (object) [], false);
        if ($post === null) {
            $post = (object) [];
        }
        $query = (isset($post->query) && is_object($post->query))
            ? (array) $post->query
            : ((isset($post->query) && is_array($post->query)) ? $post->query : []);

        $search = '';
        if (isset($post->search) && trim((string) $post->search) !== '') {
            $search = trim((string) $post->search);
        } elseif (isset($query['name']) && trim((string) $query['name']) !== '') {
            $search = trim((string) $query['name']);
        }

        $page = isset($post->page) ? (int) $post->page : 1;
        if ($page < 1) {
            $page = 1;
        }
        $results = isset($post->results) ? (int) $post->results : 20;
        if ($results < 1) {
            $results = 20;
        }
        if ($results > 500) {
            $results = 500;
        }
        $offset = ($page - 1) * $results;

        $orderByRaw = isset($post->orderBy) ? trim((string) $post->orderBy) : 'locations.id|ASC';
        if ($orderByRaw === '') {
            $orderByRaw = 'locations.id|ASC';
        }
        $orderParts = explode('|', $orderByRaw);
        $orderFieldRaw = trim((string) $orderParts[0]);
        $orderDir = strtoupper(trim((string) ($orderParts[1] ?? 'ASC')));
        if ($orderDir !== 'DESC') {
            $orderDir = 'ASC';
        }
        $allowedOrderFields = [
            'id' => 'locations.id',
            'locations.id' => 'locations.id',
            'name' => 'locations.name',
            'locations.name' => 'locations.name',
            'status' => 'locations.status',
            'locations.status' => 'locations.status',
            'map_name' => 'maps.name',
            'maps.name' => 'maps.name',
            'is_house' => 'locations.is_house',
            'locations.is_house' => 'locations.is_house',
            'is_chat' => 'locations.is_chat',
            'locations.is_chat' => 'locations.is_chat',
            'access_policy' => 'locations.access_policy',
            'locations.access_policy' => 'locations.access_policy',
            'date_created' => 'locations.date_created',
            'locations.date_created' => 'locations.date_created',
        ];
        $orderField = $allowedOrderFields[$orderFieldRaw] ?? 'locations.id';
        $orderSql = ' ORDER BY ' . $orderField . ' ' . $orderDir;

        $whereParts = ['locations.date_deleted IS NULL'];
        $params = [];

        if ($search !== '') {
            $whereParts[] = '(LOWER(locations.name) LIKE ? OR LOWER(COALESCE(maps.name, "")) LIKE ?)';
            $like = '%' . strtolower($search) . '%';
            $params[] = $like;
            $params[] = $like;
        }

        $intFilterMap = [
            'id' => 'locations.id',
            'map_id' => 'locations.map_id',
            'owner_id' => 'locations.owner_id',
            'is_house' => 'locations.is_house',
            'is_chat' => 'locations.is_chat',
            'is_private' => 'locations.is_private',
            'min_socialstatus_id' => 'locations.min_socialstatus_id',
        ];
        foreach ($intFilterMap as $key => $field) {
            if (!array_key_exists($key, $query) || $query[$key] === '' || $query[$key] === null) {
                continue;
            }
            $whereParts[] = $field . ' = ?';
            $params[] = (int) $query[$key];
        }

        $stringFilterMap = [
            'status' => 'locations.status',
            'chat_type' => 'locations.chat_type',
            'access_policy' => 'locations.access_policy',
            'booking' => 'locations.booking',
        ];
        foreach ($stringFilterMap as $key => $field) {
            if (!array_key_exists($key, $query)) {
                continue;
            }
            $value = trim((string) $query[$key]);
            if ($value === '') {
                continue;
            }
            $whereParts[] = $field . ' = ?';
            $params[] = $value;
        }

        $joins = (!empty($this->joins)) ? implode('', $this->joins) : '';
        $whereSql = ' WHERE ' . implode(' AND ', $whereParts);

        $dataset = static::db()->fetchAllPrepared(
            'SELECT ' . implode(', ', $this->fillable) . ' FROM ' . $this->table . $joins . $whereSql . $orderSql . ' LIMIT ? OFFSET ?',
            array_merge($params, [$results, $offset]),
        );
        $countRow = static::db()->fetchOnePrepared(
            'SELECT COUNT(DISTINCT locations.id) AS count FROM ' . $this->table . $joins . $whereSql,
            $params,
        );
        $tot = (int) ($countRow->count ?? 0);

        ResponseEmitter::emit(ApiResponse::json([
            'properties' => [
                'query' => empty($query) ? null : (object) $query,
                'page' => $page,
                'results_page' => $results,
                'orderBy' => $orderFieldRaw . '|' . $orderDir,
                'tot' => (object) ['count' => $tot],
            ],
            'dataset' => $dataset,
        ]));

        return $this;
    }

    public function adminCreate()
    {
        $this->requireAdmin();
        $data = $this->requestDataObject();
        if (empty(trim((string) ($data->name ?? '')))) {
            throw \Core\Http\AppError::validation('Nome luogo obbligatorio', 'name_required');
        }
        $this->locationAdminService()->create($data);
        ResponseEmitter::emit(ApiResponse::json(['success' => true, 'message' => 'Luogo creato']));
        return $this;
    }

    public function adminEdit()
    {
        $this->requireAdmin();
        $data = $this->requestDataObject();
        if ((int) ($data->id ?? 0) <= 0) {
            throw \Core\Http\AppError::validation('ID non valido', 'id_invalid');
        }
        if (empty(trim((string) ($data->name ?? '')))) {
            throw \Core\Http\AppError::validation('Nome luogo obbligatorio', 'name_required');
        }
        $this->locationAdminService()->update($data);
        ResponseEmitter::emit(ApiResponse::json(['success' => true, 'message' => 'Luogo aggiornato']));
        return $this;
    }

    public function adminGet()
    {
        $this->requireAdmin();
        $data = $this->requestDataObject();
        $id = (int) ($data->id ?? 0);
        if ($id <= 0) {
            throw \Core\Http\AppError::validation('ID non valido', 'id_invalid');
        }
        $row = $this->locationAdminService()->getById($id);
        ResponseEmitter::emit(ApiResponse::json(['dataset' => $row]));
        return $this;
    }

    public function adminDelete()
    {
        $this->requireAdmin();
        $data = $this->requestDataObject();
        $id = (int) ($data->id ?? 0);
        if ($id <= 0) {
            throw \Core\Http\AppError::validation('ID non valido', 'id_invalid');
        }
        $this->locationAdminService()->delete($id);
        ResponseEmitter::emit(ApiResponse::json(['success' => true, 'message' => 'Luogo eliminato']));
        return $this;
    }

    public function adminUpdate()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();

        $request = RequestData::fromGlobals();
        $data = $request->postJson('data', (object) [], false);
        if ($data === null || !isset($data->id)) {
            $this->failValidation('Dati non validi', 'payload_invalid');
        }

        $mapX = null;
        if (isset($data->map_x) && $data->map_x !== '') {
            $mapX = floatval($data->map_x);
            if ($mapX < 0 || $mapX > 100) {
                $this->failValidation('Map X fuori limite', 'map_x_out_of_range');
            }
        }

        $mapY = null;
        if (isset($data->map_y) && $data->map_y !== '') {
            $mapY = floatval($data->map_y);
            if ($mapY < 0 || $mapY > 100) {
                $this->failValidation('Map Y fuori limite', 'map_y_out_of_range');
            }
        }

        $this->locationService()->updateMapCoordinates(
            (int) $data->id,
            $mapX,
            $mapY,
        );

        return $this;
    }

    public function canAccess($location_id, $character_id = null)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);

        if (empty($character_id)) {
            $character_id = \Core\AuthGuard::api()->requireCharacter();
        }

        $location = $this->locationService()->getLocationForAccess((int) $location_id);

        if (empty($location) || !empty($location->date_deleted)) {
            return [
                'allowed' => false,
                'reason' => 'Luogo non trovato',
                'reason_code' => 'not_found',
                'is_owner' => false,
                'is_invited' => false,
                'is_full' => false,
                'guests_count' => 0,
            ];
        }

        $character = $this->getCharacter($character_id);
        $invited = $this->getAcceptedInvites($character_id);
        $guildAccess = $this->getGuildAccessSet($character_id);
        $access = $this->evaluateAccess($location, $character, $invited, $guildAccess);
        if ($access['allowed']) {
            $this->logAccess($character_id, (int) $location->id, 1, $access['reason_code'] ?? null, $access['reason'] ?? null);
        } else {
            $this->logAccess($character_id, (int) $location->id, 0, $access['reason_code'], $access['reason']);
        }

        return $access;
    }

    public function invites()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $me = \Core\AuthGuard::api()->requireCharacter();

        $this->expirePendingInvites();
        $dataset = $this->locationService()->listPendingInvitesForCharacter((int) $me);

        ResponseEmitter::emit(ApiResponse::json([
            'dataset' => $dataset,
        ]));
    }

    public function invite()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $me = \Core\AuthGuard::api()->requireCharacter();
        $data = InputValidator::postJsonObject(RequestData::fromGlobals(), 'data', true);

        $location_id = InputValidator::integer($data, 'location_id');
        $invited_id = InputValidator::integer($data, 'character_id');

        if ($location_id <= 0 || $invited_id <= 0) {
            $this->failValidation('Dati invito non validi', 'invite_payload_invalid');
        }

        if ($invited_id === (int) $me) {
            $this->failValidation('Non puoi invitare te stesso', 'invite_self_not_allowed');
        }

        $rule = RateLimiter::getRule('location_invite', 6, 60, 1, 50, 1, 600);
        $this->enforceRate(
            'location.invite.send',
            $rule['limit'],
            $rule['window'],
            'character:' . (int) $me . ':target:' . (int) $invited_id,
            'Stai inviando inviti troppo velocemente.',
            'location_invite_rate_limited',
        );

        $this->locationService()->createOrRefreshInvite(
            (int) $me,
            (int) $location_id,
            (int) $invited_id,
        );

        ResponseEmitter::emit(ApiResponse::json([
            'status' => 'ok',
        ]));
    }

    public function respondInvite()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $me = \Core\AuthGuard::api()->requireCharacter();
        $data = InputValidator::postJsonObject(RequestData::fromGlobals(), 'data', true);

        $invite_id = InputValidator::integer($data, 'invite_id');
        $action = InputValidator::string($data, 'action');

        if ($invite_id <= 0 || ($action !== 'accept' && $action !== 'decline')) {
            $this->failValidation('Invito non valido', 'invite_invalid');
        }

        $rule = RateLimiter::getRule('location_invite_respond', 12, 60, 1, 100, 1, 600);
        $this->enforceRate(
            'location.invite.respond',
            $rule['limit'],
            $rule['window'],
            'character:' . (int) $me,
            'Stai rispondendo agli inviti troppo velocemente.',
            'location_invite_respond_rate_limited',
        );

        $this->expirePendingInvites();
        $newStatus = ($action === 'accept') ? 'accepted' : 'declined';
        $invite = $this->locationService()->respondInvite(
            (int) $invite_id,
            (int) $me,
            $newStatus,
        );

        $response = [
            'status' => $newStatus,
        ];
        if ($action === 'accept') {
            $response['location'] = [
                'id' => (int) $invite->location_id,
                'map_id' => (int) $invite->map_id,
            ];
        }

        ResponseEmitter::emit(ApiResponse::json($response));
    }

    public function inviteUpdates()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $me = \Core\AuthGuard::api()->requireCharacter();

        $this->expirePendingInvites();
        $dataset = $this->locationService()->listOwnerInviteUpdates((int) $me);

        if (!empty($dataset)) {
            $ids = [];
            foreach ($dataset as $row) {
                $ids[] = (int) $row->id;
            }
            $this->locationService()->markOwnerInviteUpdatesNotified($ids);
        }

        ResponseEmitter::emit(ApiResponse::json([
            'dataset' => $dataset,
        ]));
    }

    private function getCharacter($character_id)
    {
        return $this->locationService()->getCharacterById((int) $character_id);
    }

    private function getAcceptedInvites($character_id)
    {
        return $this->locationService()->getAcceptedInvitesSet((int) $character_id);
    }

    private function evaluateAccess($location, $character, $invitedSet, $guildAccessSet = null)
    {
        if (!is_array($invitedSet)) {
            $invitedSet = [];
        }
        if (!is_array($guildAccessSet) && $guildAccessSet !== null) {
            $guildAccessSet = null;
        }

        return $this->locationService()->evaluateAccess(
            $location,
            $character,
            $invitedSet,
            $guildAccessSet,
        );
    }

    private function getGuildAccessSet($character_id)
    {
        return $this->locationService()->getGuildAccessSet((int) $character_id);
    }

    private function expirePendingInvites()
    {
        $this->locationService()->expirePendingInvites();
    }

    private function logAccess($character_id, $location_id, $allowed, $reason_code, $reason = null)
    {
        $this->locationService()->logAccess(
            (int) $character_id,
            (int) $location_id,
            (int) $allowed,
            $reason_code === null ? null : (string) $reason_code,
            $reason === null ? null : (string) $reason,
        );
    }

    public function create()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);

        return $this;
    }

    public function update()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);

        return $this;
    }

}


