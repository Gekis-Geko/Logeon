<?php

declare(strict_types=1);

use App\Services\ArchetypeService;
use App\Services\CharacterCreationService;
use Core\Http\ApiResponse;
use Core\Http\AppError;
use Core\Http\InputValidator;
use Core\Http\RequestData;
use Core\Http\ResponseEmitter;
use Core\Logging\LegacyLoggerAdapter;
use Core\Logging\LoggerInterface;

class Archetypes
{
    /** @var LoggerInterface|null */
    private $logger = null;
    /** @var ArchetypeService|null */
    private $archetypeService = null;
    /** @var CharacterCreationService|null */
    private $characterCreationService = null;

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

    private function archetypeService(): ArchetypeService
    {
        if ($this->archetypeService instanceof ArchetypeService) {
            return $this->archetypeService;
        }
        $this->archetypeService = new ArchetypeService();
        return $this->archetypeService;
    }

    private function characterCreationService(): CharacterCreationService
    {
        if ($this->characterCreationService instanceof CharacterCreationService) {
            return $this->characterCreationService;
        }
        $this->characterCreationService = new CharacterCreationService();
        return $this->characterCreationService;
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

    private function requireUser(): int
    {
        return (int) \Core\AuthGuard::api()->requireUser();
    }

    private function requireAdmin(): void
    {
        \Core\AuthGuard::api()->requireAbility('settings.manage');
    }

    // -------------------------------------------------------------------------
    // Game-facing endpoints
    // -------------------------------------------------------------------------

    public function publicList($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);

        $result = $this->archetypeService()->publicList();
        $response = $result;

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function characterArchetypes($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $characterId = $this->requireCharacter();

        $rows = $this->archetypeService()->getCharacterArchetypes($characterId);
        $response = ['dataset' => $rows];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function assignArchetype($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $characterId = $this->requireCharacter();

        $data = $this->requestDataObject();
        $archetypeId = InputValidator::integer($data, 'archetype_id', 0);

        if ($archetypeId <= 0) {
            throw AppError::validation('ID archetipo obbligatorio', [], 'archetype_id_required');
        }

        $config = $this->archetypeService()->getConfig();
        $multipleAllowed = (int) ($config['multiple_archetypes_allowed'] ?? 0) === 1;

        $this->archetypeService()->assignArchetype($characterId, $archetypeId, $multipleAllowed);

        $rows = $this->archetypeService()->getCharacterArchetypes($characterId);
        $response = ['dataset' => $rows];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function removeArchetype($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $characterId = $this->requireCharacter();

        $data = $this->requestDataObject();
        $archetypeId = InputValidator::integer($data, 'archetype_id', 0);

        if ($archetypeId <= 0) {
            throw AppError::validation('ID archetipo obbligatorio', [], 'archetype_id_required');
        }

        $this->archetypeService()->removeArchetype($characterId, $archetypeId);

        $rows = $this->archetypeService()->getCharacterArchetypes($characterId);
        $response = ['dataset' => $rows];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    // -------------------------------------------------------------------------
    // Character creation (called during first login flow)
    // -------------------------------------------------------------------------

    public function createCharacter($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $userId = $this->requireUser();

        $data = $this->requestDataObject();
        $name = InputValidator::string($data, 'name', '');
        $surname = InputValidator::string($data, 'surname', '');
        $gender = InputValidator::integer($data, 'gender', 0);
        if ($gender !== 0 && $gender !== 1) {
            $gender = 0;
        }

        if ($name === '') {
            throw AppError::validation('Il nome del personaggio e obbligatorio', [], 'character_name_required');
        }
        if (strlen($name) > 25) {
            throw AppError::validation('Il nome del personaggio supera la lunghezza massima consentita', [], 'character_name_too_long');
        }
        if ($surname !== '' && strlen($surname) > 30) {
            throw AppError::validation('Il cognome del personaggio supera la lunghezza massima consentita', [], 'character_surname_too_long');
        }

        $config = $this->archetypeService()->getConfig();
        $archetypesEnabled = (int) ($config['archetypes_enabled'] ?? 1) === 1;
        $archetypeRequired = $archetypesEnabled && ((int) ($config['archetype_required'] ?? 0) === 1);
        $multipleAllowed = (int) ($config['multiple_archetypes_allowed'] ?? 0) === 1;

        $rawArchetypeIds = [];
        if (isset($data->archetype_ids)) {
            if (is_array($data->archetype_ids)) {
                $rawArchetypeIds = $data->archetype_ids;
            } elseif (is_object($data->archetype_ids)) {
                $rawArchetypeIds = array_values((array) $data->archetype_ids);
            } else {
                $rawArchetypeIds = [$data->archetype_ids];
            }
        } elseif (isset($data->archetype_id)) {
            $rawArchetypeIds = [$data->archetype_id];
        }

        $archetypeIds = [];
        foreach ($rawArchetypeIds as $rawArchetypeId) {
            $id = (int) $rawArchetypeId;
            if ($id > 0 && !in_array($id, $archetypeIds, true)) {
                $archetypeIds[] = $id;
            }
        }

        if (!$multipleAllowed && count($archetypeIds) > 1) {
            throw AppError::validation('In questa configurazione puoi selezionare un solo archetipo', [], 'archetype_single_only');
        }
        if ($archetypeRequired && empty($archetypeIds)) {
            throw AppError::validation('La selezione dell\'archetipo e obbligatoria', [], 'archetype_required');
        }
        if ($archetypesEnabled && !empty($archetypeIds)) {
            $this->characterCreationService()->validateSelectableArchetypes($archetypeIds);
        }
        if (!$archetypesEnabled) {
            $archetypeIds = [];
        }

        $character = $this->characterCreationService()->createCharacter(
            $userId,
            $name,
            $surname !== '' ? $surname : null,
            $gender,
            $archetypeIds,
            $archetypeRequired,
            $multipleAllowed,
            $this->archetypeService(),
        );

        $characterId = (int) ($character->id ?? 0);
        \Core\SessionStore::set('character_id', $characterId);
        \Core\SessionStore::set('character_gender', (int) ($character->gender ?? 0));
        \Core\SessionStore::set('character_last_location', isset($character->last_location) ? (int) $character->last_location : null);
        \Core\SessionStore::set('character_last_map', isset($character->last_map) ? (int) $character->last_map : null);

        $response = [
            'status' => 'ok',
            'character_id' => $characterId,
            'redirect' => '/game/',
        ];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    // -------------------------------------------------------------------------
    // Admin — config
    // -------------------------------------------------------------------------

    public function adminConfigGet($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();

        $config = $this->archetypeService()->getConfig();
        $response = ['dataset' => $config];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function adminConfigUpdate($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();

        $data = $this->requestDataObject();
        $config = $this->archetypeService()->updateConfig($data);
        $response = ['dataset' => $config];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    // -------------------------------------------------------------------------
    // Admin — CRUD
    // -------------------------------------------------------------------------

    public function adminList($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();

        $data = $this->requestDataObject();
        $query = (isset($data->query) && is_object($data->query)) ? $data->query : (object) [];

        $filters = [
            'search' => InputValidator::firstString($query, ['search'], InputValidator::string($data, 'search', '')),
        ];

        $isActiveRaw = property_exists($query, 'is_active')
            ? InputValidator::integer($query, 'is_active', 0)
            : (property_exists($data, 'is_active') ? InputValidator::integer($data, 'is_active', 0) : null);
        if ($isActiveRaw !== null && $isActiveRaw !== '') {
            $filters['is_active'] = ((int) $isActiveRaw === 1) ? 1 : 0;
        }

        $isSelectableRaw = property_exists($query, 'is_selectable')
            ? InputValidator::integer($query, 'is_selectable', 0)
            : (property_exists($data, 'is_selectable') ? InputValidator::integer($data, 'is_selectable', 0) : null);
        if ($isSelectableRaw !== null && $isSelectableRaw !== '') {
            $filters['is_selectable'] = ((int) $isSelectableRaw === 1) ? 1 : 0;
        }

        $limit = InputValidator::integer($data, 'results', 0);
        if ($limit <= 0) {
            $limit = InputValidator::integer($data, 'limit', 20);
        }
        $limit = max(1, min(100, $limit));

        $page = max(1, InputValidator::integer($data, 'page', 1));
        $sort = InputValidator::firstString($data, ['orderBy', 'sort'], 'sort_order|ASC');

        $result = $this->archetypeService()->adminList($filters, $limit, $page, $sort);
        $response = [
            'properties' => [
                'query' => [
                    'search' => $filters['search'],
                    'is_active' => array_key_exists('is_active', $filters) ? (int) $filters['is_active'] : '',
                    'is_selectable' => array_key_exists('is_selectable', $filters) ? (int) $filters['is_selectable'] : '',
                ],
                'page' => (int) ($result['page'] ?? $page),
                'results_page' => (int) ($result['limit'] ?? $limit),
                'orderBy' => $sort,
                'tot' => ['count' => (int) ($result['total'] ?? 0)],
            ],
            'dataset' => isset($result['rows']) && is_array($result['rows']) ? $result['rows'] : [],
        ];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function adminCreate($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();

        $data = $this->requestDataObject();
        $archetype = $this->archetypeService()->adminCreate($data);
        $response = ['dataset' => $archetype];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function adminUpdate($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();

        $data = $this->requestDataObject();
        $archetype = $this->archetypeService()->adminUpdate($data);
        $response = ['dataset' => $archetype];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function adminDelete($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();

        $data = $this->requestDataObject();
        $id = InputValidator::integer($data, 'id', 0);

        $this->archetypeService()->adminDelete($id);
        $response = ['status' => 'ok'];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    // -------------------------------------------------------------------------
    // Admin — character archetype management
    // -------------------------------------------------------------------------

    public function adminCharacterArchetypes($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();

        $data = $this->requestDataObject();
        $characterId = InputValidator::integer($data, 'character_id', 0);

        $rows = $this->archetypeService()->getCharacterArchetypes($characterId);
        $response = ['dataset' => $rows];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function adminAssignArchetype($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();

        $data = $this->requestDataObject();
        $characterId = InputValidator::integer($data, 'character_id', 0);
        $archetypeId = InputValidator::integer($data, 'archetype_id', 0);

        if ($characterId <= 0) {
            throw AppError::validation('ID personaggio obbligatorio', [], 'character_id_required');
        }
        if ($archetypeId <= 0) {
            throw AppError::validation('ID archetipo obbligatorio', [], 'archetype_id_required');
        }

        $config = $this->archetypeService()->getConfig();
        $multipleAllowed = (int) ($config['multiple_archetypes_allowed'] ?? 0) === 1;

        $this->archetypeService()->assignArchetype($characterId, $archetypeId, $multipleAllowed);

        $rows = $this->archetypeService()->getCharacterArchetypes($characterId);
        $response = ['dataset' => $rows];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function adminRemoveArchetype($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();

        $data = $this->requestDataObject();
        $characterId = InputValidator::integer($data, 'character_id', 0);
        $archetypeId = InputValidator::integer($data, 'archetype_id', 0);

        $this->archetypeService()->removeArchetype($characterId, $archetypeId);

        $rows = $this->archetypeService()->getCharacterArchetypes($characterId);
        $response = ['dataset' => $rows];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }
}
