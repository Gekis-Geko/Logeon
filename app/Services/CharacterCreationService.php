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

    private function isArchetypeProviderContract($provider): bool
    {
        if (!is_object($provider)) {
            return false;
        }

        return method_exists($provider, 'assignArchetype')
            && method_exists($provider, 'clearCharacterArchetypes')
            && method_exists($provider, 'validateSelectableArchetypes');
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
     * @param  object|null $archetypeProvider Provider used to assign archetypes, null if module not active.
     */
    public function createCharacter(
        int $userId,
        string $name,
        ?string $surname,
        int $gender,
        array $archetypeIds,
        bool $archetypeRequired,
        bool $multipleAllowed,
        ?object $archetypeProvider,
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

        if (!empty($archetypeIds) && $this->isArchetypeProviderContract($archetypeProvider)) {
            try {
                if ($multipleAllowed) {
                    foreach ($archetypeIds as $archetypeId) {
                        $archetypeProvider->assignArchetype($characterId, (int) $archetypeId, true);
                    }
                } else {
                    $firstArchetypeId = (int) ($archetypeIds[0] ?? 0);
                    if ($firstArchetypeId > 0) {
                        $archetypeProvider->assignArchetype($characterId, $firstArchetypeId, false);
                    }
                }
            } catch (\Throwable $e) {
                if ($archetypeRequired) {
                    $archetypeProvider->clearCharacterArchetypes($characterId);
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
    public function validateSelectableArchetypes(array $archetypeIds, object $archetypeProvider): array
    {
        if (!$this->isArchetypeProviderContract($archetypeProvider)) {
            throw AppError::validation('Provider archetipi non disponibile', [], 'archetype_provider_missing');
        }

        return $archetypeProvider->validateSelectableArchetypes($archetypeIds);
    }
}
