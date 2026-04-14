<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database\DbAdapterFactory;
use Core\Database\DbAdapterInterface;
use Core\SessionStore;

class PresenceService
{
    /** @var DbAdapterInterface */
    private $db;

    public function __construct(DbAdapterInterface $db = null)
    {
        $this->db = $db ?: DbAdapterFactory::createFromConfig();
    }

    /**
     * @param array<int,mixed> $params
     */
    private function execPrepared(string $sql, array $params = []): void
    {
        $this->db->executePrepared($sql, $params);
    }

    public function touchUser(int $userId): void
    {
        if ($userId <= 0) {
            return;
        }

        $this->execPrepared(
            'UPDATE users SET
                date_last_seed = NOW()
             WHERE id = ?',
            [$userId],
        );
    }

    public function touchCharacter(int $characterId): void
    {
        if ($characterId <= 0) {
            return;
        }

        $this->execPrepared(
            'UPDATE characters SET
                date_last_seed = NOW(),
                availability = CASE WHEN availability = 2 THEN 1 ELSE availability END
             WHERE id = ?',
            [$characterId],
        );
    }

    public function setCharacterPositionAndTouch(int $characterId, $mapId = null, $locationId = null): void
    {
        if ($characterId <= 0) {
            return;
        }

        $previousMap = SessionStore::get('character_last_map');
        $previousLocation = SessionStore::get('character_last_location');
        $mapChanged = ($previousMap != $mapId);
        $locationChanged = ($previousLocation != $locationId);

        if ($mapChanged) {
            SessionStore::set('character_last_map', $mapId);
        }

        if ($locationChanged) {
            SessionStore::set('character_last_location', $locationId);
        }

        $this->execPrepared(
            'UPDATE characters SET
                last_map = ?,
                last_location = ?,
                date_last_seed = NOW(),
                availability = CASE WHEN availability = 2 THEN 1 ELSE availability END
             WHERE id = ?',
            [$mapId !== null ? (int) $mapId : null, $locationId !== null ? (int) $locationId : null, $characterId],
        );

        if ($mapChanged || $locationChanged) {
            \Core\Hooks::fire(
                'presence.position_changed',
                $characterId,
                $mapId !== null ? (int) $mapId : null,
                $locationId !== null ? (int) $locationId : null,
                $previousMap !== null ? (int) $previousMap : null,
                $previousLocation !== null ? (int) $previousLocation : null,
            );
        }
    }
}
