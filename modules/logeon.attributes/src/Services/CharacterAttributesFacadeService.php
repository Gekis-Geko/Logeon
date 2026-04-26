<?php

declare(strict_types=1);

namespace Modules\Logeon\Attributes\Services;

use Core\Http\AppError;
use Core\SessionStore;

class CharacterAttributesFacadeService extends CharacterAttributesBaseService
{
    private const CONFIG_KEY = 'character_attributes_enabled';
    private const REQUIRED_TABLES = [
        'character_attribute_definitions',
        'character_attribute_values',
        'character_attribute_rules',
        'character_attribute_rule_steps',
    ];

    /** @var CharacterAttributesDefinitionService */
    private $definitions;
    /** @var CharacterAttributesRuleService */
    private $rules;
    /** @var CharacterAttributesValueService */
    private $values;
    /** @var CharacterAttributesEngineService */
    private $engine;
    /** @var bool|null */
    private $schemaReady = null;

    public function __construct(
        \Core\Database\DbAdapterInterface $db = null,
        CharacterAttributesDefinitionService $definitions = null,
        CharacterAttributesRuleService $rules = null,
        CharacterAttributesValueService $values = null,
        CharacterAttributesEngineService $engine = null,
    ) {
        parent::__construct($db);
        $this->definitions = $definitions ?: new CharacterAttributesDefinitionService($this->db);
        $this->rules = $rules ?: new CharacterAttributesRuleService($this->db, $this->definitions);
        $this->values = $values ?: new CharacterAttributesValueService($this->db);
        $this->engine = $engine ?: new CharacterAttributesEngineService(
            $this->db,
            $this->definitions,
            $this->rules,
            $this->values,
        );
    }

    public function isEnabled(): bool
    {
        $sessionKey = 'config_' . self::CONFIG_KEY;
        $sessionValue = SessionStore::get($sessionKey);
        if ($sessionValue !== null && $sessionValue !== '') {
            return $this->normalizeBool($sessionValue, 0) === 1;
        }

        $row = $this->db->fetchOnePrepared(
            'SELECT value
             FROM sys_configs
             WHERE `key` = ?
             LIMIT 1',
            [self::CONFIG_KEY],
        );
        if (empty($row) || !isset($row->value)) {
            return false;
        }

        $enabled = $this->normalizeBool($row->value, 0) === 1;
        SessionStore::set($sessionKey, $enabled ? '1' : '0');
        return $enabled;
    }

    public function requireEnabled(): void
    {
        if (!$this->isEnabled()) {
            throw AppError::validation(
                'Sistema attributi personaggio disattivato',
                [],
                'attributes_system_disabled',
            );
        }

        if (!$this->isSchemaReady()) {
            throw AppError::validation(
                'Schema attributi non disponibile: allinea il database con database/logeon_db_core.sql',
                [],
                'attributes_system_disabled',
            );
        }
    }

    public function getSettings(): array
    {
        return [
            'enabled' => $this->isEnabled() ? 1 : 0,
        ];
    }

    public function updateSettings(object $payload): array
    {
        $enabled = $this->normalizeBool($payload->enabled ?? 0, 0);
        if ($enabled === 1 && !$this->isSchemaReady()) {
            throw AppError::validation(
                'Impossibile attivare il sistema: schema attributi non disponibile',
                [],
                'attributes_system_disabled',
            );
        }
        $this->db->executePrepared(
            'INSERT INTO sys_configs (`key`, `value`, `type`)
             VALUES (?, ?, "number")
             ON DUPLICATE KEY UPDATE
                `value` = VALUES(`value`),
                `type` = VALUES(`type`)',
            [self::CONFIG_KEY, (string) $enabled],
        );

        SessionStore::set('config_' . self::CONFIG_KEY, (string) $enabled);

        return [
            'enabled' => $enabled,
        ];
    }

    public function listDefinitions(object $payload): array
    {
        return $this->definitions->listDefinitions($payload);
    }

    public function createDefinition(object $payload, int $actorUserId = 0): array
    {
        return $this->definitions->createDefinition($payload, $actorUserId);
    }

    public function updateDefinition(int $id, object $payload, int $actorUserId = 0): array
    {
        return $this->definitions->updateDefinition($id, $payload, $actorUserId);
    }

    public function deactivateDefinition(int $id, int $actorUserId = 0): array
    {
        return $this->definitions->deactivateDefinition($id, $actorUserId);
    }

    public function reorderDefinitions(array $orderedIds): array
    {
        return $this->definitions->reorderDefinitions($orderedIds);
    }

    public function getRule(int $attributeId): array
    {
        return $this->rules->getRuleByAttributeId($attributeId);
    }

    public function upsertRule(int $attributeId, object $payload): array
    {
        return $this->rules->upsertRule($attributeId, $payload);
    }

    public function deleteRule(int $attributeId): array
    {
        return $this->rules->deleteRuleByAttributeId($attributeId);
    }

    public function recompute($characterId = null): array
    {
        $this->requireEnabled();

        if ($characterId !== null && (int) $characterId > 0) {
            $single = $this->engine->recomputeCharacter((int) $characterId);
            return [
                'mode' => 'single',
                'total' => 1,
                'items' => [$single],
            ];
        }

        $rows = $this->db->fetchAllPrepared(
            'SELECT id
             FROM characters
             WHERE delete_scheduled_at IS NULL OR delete_scheduled_at > NOW()
             ORDER BY id ASC',
        );

        $items = [];
        foreach ($rows ?: [] as $row) {
            $id = (int) ($row->id ?? 0);
            if ($id <= 0) {
                continue;
            }
            $items[] = $this->engine->recomputeCharacter($id);
        }

        return [
            'mode' => 'batch',
            'total' => count($items),
            'items' => $items,
        ];
    }

    public function listCharacterValues(int $characterId): array
    {
        $this->requireEnabled();
        if ($characterId <= 0) {
            throw AppError::validation('Personaggio non valido', [], 'character_invalid');
        }

        $this->values->ensureRowsForCharacter($characterId);
        $rows = $this->values->listCharacterValues($characterId);

        return [
            'character_id' => $characterId,
            'dataset' => $rows,
        ];
    }

    public function updateCharacterValues(int $characterId, array $entries): array
    {
        $this->requireEnabled();
        if ($characterId <= 0) {
            throw AppError::validation('Personaggio non valido', [], 'character_invalid');
        }

        $updated = $this->values->updateManualValues($characterId, $entries);
        $recomputed = $this->engine->recomputeCharacter($characterId);

        return [
            'character_id' => $characterId,
            'updated' => $updated,
            'recomputed' => $recomputed,
        ];
    }

    public function recomputeCharacter(int $characterId): array
    {
        $this->requireEnabled();
        return $this->engine->recomputeCharacter($characterId);
    }

    public function decorateCharacterDataset(object $dataset): object
    {
        if (!isset($dataset->id)) {
            return $dataset;
        }

        $characterId = (int) $dataset->id;
        $enabled = $this->isEnabled();
        if (!$enabled || !$this->isSchemaReady()) {
            $dataset = $this->applyRuntimeHealthFallback($dataset);
            $dataset->character_attributes = [
                'enabled' => 0,
                'profile' => [
                    'primary' => [],
                    'secondary' => [],
                    'narrative' => [],
                ],
                'location' => [
                    'primary' => [],
                    'secondary' => [],
                    'narrative' => [],
                ],
            ];
            return $dataset;
        }

        $this->values->ensureRowsForCharacter($characterId);
        $rows = $this->values->listCharacterValues($characterId);

        $dataset->character_attributes = [
            'enabled' => 1,
            'profile' => $this->values->groupedForUi($rows, 'profile'),
            'location' => $this->values->groupedForUi($rows, 'location'),
        ];

        // If health is mapped and values are already available, expose current max coherently.
        $mapped = $this->definitions->mappedHealthDefinition();
        if (!empty($mapped)) {
            $mappedId = (int) ($mapped->id ?? 0);
            if ($mappedId > 0) {
                foreach ($rows as $row) {
                    if ((int) ($row->attribute_id ?? 0) !== $mappedId) {
                        continue;
                    }
                    $effective = isset($row->effective_value)
                        ? (float) $row->effective_value
                        : null;
                    if ($effective !== null && $effective > 0) {
                        $dataset->health_max = round($effective, 2);
                        if (isset($dataset->health) && (float) $dataset->health > $dataset->health_max) {
                            $dataset->health = round((float) $dataset->health_max, 2);
                        }
                    }
                    break;
                }
            }
        }

        return $dataset;
    }

    private function isSchemaReady(): bool
    {
        if ($this->schemaReady !== null) {
            return $this->schemaReady;
        }

        foreach (self::REQUIRED_TABLES as $table) {
            if (!$this->tableExists($table)) {
                $this->schemaReady = false;
                return false;
            }
        }

        $this->schemaReady = true;
        return true;
    }

    public function applyRuntimeHealthFallback(object $dataset): object
    {
        $health = isset($dataset->health) ? (float) $dataset->health : 0.0;
        $healthMax = 100.0;
        if ($health < 0) {
            $health = 0;
        }
        if ($health > $healthMax) {
            $health = $healthMax;
        }

        $dataset->health = round($health, 2);
        $dataset->health_max = $healthMax;
        return $dataset;
    }
}


