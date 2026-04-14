<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database\DbAdapterFactory;
use Core\Database\DbAdapterInterface;
use Core\Http\AppError;

class CharacterCreationService
{
    /** @var DbAdapterInterface */
    private $db;

    public function __construct(DbAdapterInterface $db = null)
    {
        $this->db = $db ?: DbAdapterFactory::createFromConfig();
    }

    private function firstPrepared(string $sql, array $params = [])
    {
        return $this->db->fetchOnePrepared($sql, $params);
    }

    private function fetchPrepared(string $sql, array $params = []): array
    {
        return $this->db->fetchAllPrepared($sql, $params);
    }

    private function execPrepared(string $sql, array $params = []): void
    {
        $this->db->executePrepared($sql, $params);
    }

    // -------------------------------------------------------------------------
    // Character creation
    // -------------------------------------------------------------------------

    /**
     * Creates a new character for the given user and optionally assigns archetypes.
     *
     * Returns the created character row as an object, or null if the fetch fails.
     *
     * @param  array<int> $archetypeIds        Validated archetype IDs to assign (may be empty).
     * @param  bool       $archetypeRequired   If true, rolls back character creation when archetype assignment fails.
     * @param  bool       $multipleAllowed     If true, assigns all IDs; otherwise only the first.
     * @param  ArchetypeService $archetypeService  Service used to assign archetypes.
     */
    public function createCharacter(
        int $userId,
        string $name,
        ?string $surname,
        int $gender,
        array $archetypeIds,
        bool $archetypeRequired,
        bool $multipleAllowed,
        ArchetypeService $archetypeService,
    ): object {
        $this->validateMultiCharacterPolicy($userId);

        $this->execPrepared(
            'INSERT INTO `characters` (`user_id`, `name`, `surname`, `gender`, `socialstatus_id`, `last_map`, `last_location`)
             VALUES (?, ?, ?, ?, 1, NULL, NULL)',
            [$userId, $name, ($surname === '' || $surname === null) ? null : $surname, $gender],
        );
        $characterId = (int) $this->db->lastInsertId();

        if ($characterId <= 0) {
            throw AppError::validation('Impossibile creare il personaggio', [], 'character_creation_failed');
        }

        if (!empty($archetypeIds)) {
            try {
                if ($multipleAllowed) {
                    foreach ($archetypeIds as $archetypeId) {
                        $archetypeService->assignArchetype($characterId, (int) $archetypeId, true);
                    }
                } else {
                    $firstArchetypeId = (int) ($archetypeIds[0] ?? 0);
                    if ($firstArchetypeId > 0) {
                        $archetypeService->assignArchetype($characterId, $firstArchetypeId, false);
                    }
                }
            } catch (\Throwable $e) {
                if ($archetypeRequired) {
                    $this->execPrepared(
                        'DELETE FROM `character_archetypes` WHERE `character_id` = ?',
                        [$characterId],
                    );
                    $this->execPrepared(
                        'DELETE FROM `characters` WHERE `id` = ? LIMIT 1',
                        [$characterId],
                    );
                    throw AppError::validation('Impossibile assegnare l\'archetipo obbligatorio', [], 'archetype_assignment_failed');
                }
            }
        }

        $character = $this->firstPrepared(
            'SELECT * FROM `characters` WHERE `id` = ? LIMIT 1',
            [$characterId],
        );

        if (empty($character)) {
            throw AppError::validation('Personaggio creato ma non recuperabile', [], 'character_creation_failed');
        }

        return $character;
    }

    /**
     * Validates that the given user has not exceeded the multi-character limit.
     * Throws AppError::validation if the limit is reached.
     */
    public function validateMultiCharacterPolicy(int $userId): void
    {
        $configRows = $this->fetchPrepared(
            'SELECT `key`, `value`
             FROM `sys_configs`
             WHERE `key` IN (?, ?)',
            ['multi_character_enabled', 'multi_character_max_per_user'],
        );

        $multiEnabled = false;
        $multiMaxPerUser = 1;
        foreach ($configRows as $row) {
            $key = isset($row->key) ? (string) $row->key : '';
            $value = isset($row->value) ? (string) $row->value : '';
            if ($key === 'multi_character_enabled') {
                $multiEnabled = ((int) $value === 1);
            } elseif ($key === 'multi_character_max_per_user') {
                $multiMaxPerUser = (int) $value;
            }
        }

        $multiMaxPerUser = max(1, min(10, $multiMaxPerUser));
        if (!$multiEnabled) {
            $multiMaxPerUser = 1;
        }

        $countRow = $this->firstPrepared(
            'SELECT COUNT(*) AS tot
             FROM `characters`
             WHERE `user_id` = ?
               AND (`delete_scheduled_at` IS NULL OR `delete_scheduled_at` > NOW())',
            [$userId],
        );
        $existingCount = isset($countRow->tot) ? (int) $countRow->tot : 0;

        if ($existingCount >= $multiMaxPerUser) {
            if ($multiEnabled) {
                throw AppError::validation(
                    'Hai raggiunto il numero massimo di personaggi per questo account',
                    [],
                    'character_limit_reached',
                );
            }
            throw AppError::validation(
                'Hai gia un personaggio associato a questo account',
                [],
                'character_already_exists',
            );
        }
    }

    /**
     * Validates that the requested archetype IDs are all active and selectable.
     * Returns the validated array of IDs (may be a subset if some were invalid).
     *
     * @param  array<int> $archetypeIds
     * @return array<int>
     */
    public function validateSelectableArchetypes(array $archetypeIds): array
    {
        if (empty($archetypeIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($archetypeIds), '?'));
        $selectableRows = $this->fetchPrepared(
            'SELECT `id`
             FROM `archetypes`
             WHERE `is_active` = 1
               AND `is_selectable` = 1
               AND `id` IN (' . $placeholders . ')',
            array_values(array_map('intval', $archetypeIds)),
        );

        $selectableIds = [];
        foreach ($selectableRows as $row) {
            $selectableIds[] = (int) ($row->id ?? 0);
        }

        foreach ($archetypeIds as $selectedId) {
            if (!in_array((int) $selectedId, $selectableIds, true)) {
                throw AppError::validation('Archetipo non selezionabile', [], 'archetype_not_selectable');
            }
        }

        return $archetypeIds;
    }
}
