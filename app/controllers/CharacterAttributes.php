<?php

declare(strict_types=1);

use App\Services\CharacterAttributesFacadeService;
use Core\AuthGuard;
use Core\Http\ApiResponse;
use Core\Http\AppError;
use Core\Http\InputValidator;
use Core\Http\RequestData;
use Core\Http\ResponseEmitter;
use Core\SessionStore;

class CharacterAttributes
{
    /** @var CharacterAttributesFacadeService|null */
    private $facade = null;

    public function setFacade(CharacterAttributesFacadeService $facade = null)
    {
        $this->facade = $facade;
        return $this;
    }

    private function facade(): CharacterAttributesFacadeService
    {
        if ($this->facade instanceof CharacterAttributesFacadeService) {
            return $this->facade;
        }

        $this->facade = new CharacterAttributesFacadeService();
        return $this->facade;
    }

    private function emitJson(array $payload): void
    {
        ResponseEmitter::emit(ApiResponse::json($payload));
    }

    private function requireAdmin(): void
    {
        AuthGuard::api()->requireAbility('settings.manage', [], 'Operazione non autorizzata');
    }

    private function requireStaffCharacter(): array
    {
        $guard = AuthGuard::api();
        $guard->requireUserCharacter();

        $isStaff = ((int) SessionStore::get('user_is_administrator') === 1)
            || ((int) SessionStore::get('user_is_moderator') === 1)
            || ((int) SessionStore::get('user_is_master') === 1);

        if (!$isStaff) {
            throw AppError::unauthorized('Operazione non autorizzata', [], 'attribute_update_forbidden');
        }

        return [
            'user_id' => (int) $guard->requireUser(),
            'character_id' => (int) $guard->requireCharacter(),
        ];
    }

    private function resolveTargetCharacterId(object $data, int $fallbackCharacterId): int
    {
        if (!empty($data->character_id)) {
            return (int) $data->character_id;
        }
        if (!empty($data->id)) {
            return (int) $data->id;
        }
        return $fallbackCharacterId;
    }

    private function resolveDefinitionId(object $data): int
    {
        if (!empty($data->attribute_id)) {
            return (int) $data->attribute_id;
        }
        if (!empty($data->id)) {
            return (int) $data->id;
        }
        return 0;
    }

    public function adminSettingsGet()
    {
        $this->requireAdmin();
        $settings = $this->facade()->getSettings();
        $this->emitJson(['dataset' => $settings]);
        return $this;
    }

    public function adminSettingsUpdate()
    {
        $this->requireAdmin();
        $data = InputValidator::postJsonObject(RequestData::fromGlobals(), 'data', true);

        $settings = $this->facade()->updateSettings($data);
        $this->emitJson(['dataset' => $settings]);
        return $this;
    }

    public function adminDefinitionsList()
    {
        $this->requireAdmin();
        $data = InputValidator::postJsonObject(RequestData::fromGlobals(), 'data', true);

        $response = $this->facade()->listDefinitions($data);
        $this->emitJson($response);
        return $this;
    }

    public function adminDefinitionsCreate()
    {
        $this->requireAdmin();
        $guard = AuthGuard::api();
        $userId = (int) $guard->requireUser();

        $data = InputValidator::postJsonObject(RequestData::fromGlobals(), 'data', false, 'Dati mancanti', 'payload_missing');

        $result = $this->facade()->createDefinition($data, $userId);
        $this->emitJson(['dataset' => $result]);
        return $this;
    }

    public function adminDefinitionsUpdate()
    {
        $this->requireAdmin();
        $guard = AuthGuard::api();
        $userId = (int) $guard->requireUser();

        $data = InputValidator::postJsonObject(RequestData::fromGlobals(), 'data', false, 'Dati mancanti', 'payload_missing');

        $id = $this->resolveDefinitionId($data);
        if ($id <= 0) {
            throw AppError::validation('Attributo non valido', [], 'attribute_definition_not_found');
        }

        $result = $this->facade()->updateDefinition($id, $data, $userId);
        $this->emitJson(['dataset' => $result]);
        return $this;
    }

    public function adminDefinitionsDeactivate()
    {
        $this->requireAdmin();
        $guard = AuthGuard::api();
        $userId = (int) $guard->requireUser();

        $data = InputValidator::postJsonObject(RequestData::fromGlobals(), 'data', false, 'Dati mancanti', 'payload_missing');

        $id = $this->resolveDefinitionId($data);
        if ($id <= 0) {
            throw AppError::validation('Attributo non valido', [], 'attribute_definition_not_found');
        }

        $result = $this->facade()->deactivateDefinition($id, $userId);
        $this->emitJson(['dataset' => $result]);
        return $this;
    }

    public function adminDefinitionsReorder()
    {
        $this->requireAdmin();
        $data = InputValidator::postJsonObject(RequestData::fromGlobals(), 'data', false, 'Dati mancanti', 'payload_missing');

        $ordered = [];
        if (isset($data->ordered_ids) && is_array($data->ordered_ids)) {
            $ordered = $data->ordered_ids;
        } elseif (isset($data->ids) && is_array($data->ids)) {
            $ordered = $data->ids;
        }

        $result = $this->facade()->reorderDefinitions($ordered);
        $this->emitJson(['dataset' => $result]);
        return $this;
    }

    public function adminRulesGet()
    {
        $this->requireAdmin();
        $data = InputValidator::postJsonObject(RequestData::fromGlobals(), 'data', false, 'Dati mancanti', 'payload_missing');

        $attributeId = $this->resolveDefinitionId($data);
        if ($attributeId <= 0) {
            throw AppError::validation('Attributo non valido', [], 'attribute_definition_not_found');
        }

        $result = $this->facade()->getRule($attributeId);
        $this->emitJson(['dataset' => $result]);
        return $this;
    }

    public function adminRulesUpsert()
    {
        $this->requireAdmin();
        $data = InputValidator::postJsonObject(RequestData::fromGlobals(), 'data', false, 'Dati mancanti', 'payload_missing');

        $attributeId = $this->resolveDefinitionId($data);
        if ($attributeId <= 0) {
            throw AppError::validation('Attributo non valido', [], 'attribute_definition_not_found');
        }

        $result = $this->facade()->upsertRule($attributeId, $data);
        $this->emitJson(['dataset' => $result]);
        return $this;
    }

    public function adminRulesDelete()
    {
        $this->requireAdmin();
        $data = InputValidator::postJsonObject(RequestData::fromGlobals(), 'data', false, 'Dati mancanti', 'payload_missing');

        $attributeId = $this->resolveDefinitionId($data);
        if ($attributeId <= 0) {
            throw AppError::validation('Attributo non valido', [], 'attribute_definition_not_found');
        }

        $result = $this->facade()->deleteRule($attributeId);
        $this->emitJson(['dataset' => $result]);
        return $this;
    }

    public function adminRecompute()
    {
        $this->requireAdmin();
        $data = InputValidator::postJsonObject(RequestData::fromGlobals(), 'data', true);

        $characterId = $this->resolveTargetCharacterId($data, 0);
        if ($characterId > 0) {
            $result = $this->facade()->recomputeCharacter($characterId);
        } else {
            $result = $this->facade()->recompute(null);
        }

        $this->emitJson(['dataset' => $result]);
        return $this;
    }

    public function profileList()
    {
        $session = $this->requireStaffCharacter();
        $data = InputValidator::postJsonObject(RequestData::fromGlobals(), 'data', true);

        $targetCharacterId = $this->resolveTargetCharacterId($data, (int) $session['character_id']);
        if ($targetCharacterId <= 0) {
            throw AppError::validation('Personaggio non valido', [], 'character_invalid');
        }

        $result = $this->facade()->listCharacterValues($targetCharacterId);
        $this->emitJson($result);
        return $this;
    }

    public function profileUpdateValues()
    {
        $session = $this->requireStaffCharacter();
        $data = InputValidator::postJsonObject(RequestData::fromGlobals(), 'data', false, 'Dati mancanti', 'payload_missing');

        $targetCharacterId = $this->resolveTargetCharacterId($data, (int) $session['character_id']);
        if ($targetCharacterId <= 0) {
            throw AppError::validation('Personaggio non valido', [], 'character_invalid');
        }

        $values = InputValidator::arrayOfValues($data, 'values');
        if ($values === []) {
            throw AppError::validation('Nessun valore da aggiornare', [], 'attribute_update_forbidden');
        }

        $result = $this->facade()->updateCharacterValues($targetCharacterId, $values);
        $this->emitJson(['dataset' => $result]);
        return $this;
    }

    public function profileRecompute()
    {
        $session = $this->requireStaffCharacter();
        $data = InputValidator::postJsonObject(RequestData::fromGlobals(), 'data', true);

        $targetCharacterId = $this->resolveTargetCharacterId($data, (int) $session['character_id']);
        if ($targetCharacterId <= 0) {
            throw AppError::validation('Personaggio non valido', [], 'character_invalid');
        }

        $result = $this->facade()->recomputeCharacter($targetCharacterId);
        $this->emitJson(['dataset' => $result]);
        return $this;
    }
}
