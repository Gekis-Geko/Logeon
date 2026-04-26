<?php

declare(strict_types=1);

namespace Modules\Logeon\Factions;

use App\Contracts\FactionProviderInterface;
use Core\Database\DbAdapterFactory;
use Core\Database\DbAdapterInterface;

class FactionsModuleProvider implements FactionProviderInterface
{
    private DbAdapterInterface $db;

    public function __construct(DbAdapterInterface $db = null)
    {
        $this->db = $db ?: DbAdapterFactory::createFromConfig();
    }

    public function getMembershipsForCharacter(int $characterId): array
    {
        if ($characterId <= 0) {
            return [];
        }

        try {
            $rows = $this->db->fetchAllPrepared(
                'SELECT DISTINCT faction_id
                 FROM faction_memberships
                 WHERE character_id = ?
                   AND status = ?',
                [$characterId, 'active'],
            );
        } catch (\Throwable $e) {
            return [];
        }

        $ids = [];
        foreach ($rows as $row) {
            $id = (int) $this->readField($row, 'faction_id', 0);
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    public function getActiveCharacterIdsForFactions(array $factionIds): array
    {
        $normalizedFactionIds = [];
        foreach ($factionIds as $factionId) {
            $factionId = (int) $factionId;
            if ($factionId > 0) {
                $normalizedFactionIds[$factionId] = $factionId;
            }
        }
        if (empty($normalizedFactionIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($normalizedFactionIds), '?'));
        $params = array_values($normalizedFactionIds);
        $params[] = 'active';

        try {
            $rows = $this->db->fetchAllPrepared(
                'SELECT DISTINCT character_id
                 FROM faction_memberships
                 WHERE faction_id IN (' . $placeholders . ')
                   AND status = ?',
                $params,
            );
        } catch (\Throwable $e) {
            return [];
        }

        $characterIds = [];
        foreach ($rows as $row) {
            $characterId = (int) $this->readField($row, 'character_id', 0);
            if ($characterId > 0) {
                $characterIds[$characterId] = $characterId;
            }
        }

        return array_values($characterIds);
    }

    public function existsById(int $id): bool
    {
        if ($id <= 0) {
            return false;
        }

        try {
            $row = $this->db->fetchOnePrepared(
                'SELECT id
                 FROM factions
                 WHERE id = ?
                 LIMIT 1',
                [$id],
            );
        } catch (\Throwable $e) {
            return false;
        }

        return !empty($row);
    }

    public function getNameById(int $id): ?string
    {
        if ($id <= 0) {
            return null;
        }

        try {
            $row = $this->db->fetchOnePrepared(
                'SELECT name
                 FROM factions
                 WHERE id = ?
                 LIMIT 1',
                [$id],
            );
        } catch (\Throwable $e) {
            return null;
        }

        $name = trim((string) $this->readField($row, 'name', ''));
        return $name !== '' ? $name : null;
    }

    /**
     * @return array<int,array{id:int,label:string,secondary:string}>
     */
    public function search(string $needle, int $limit): array
    {
        $limit = max(1, min(50, $limit));
        $needle = trim($needle);

        try {
            if ($needle !== '') {
                $like = '%' . $needle . '%';
                $rows = $this->db->fetchAllPrepared(
                    'SELECT id, name AS label, code AS secondary
                     FROM factions
                     WHERE name LIKE ? OR code LIKE ?
                     ORDER BY name ASC, id ASC
                     LIMIT ?',
                    [$like, $like, $limit],
                );
            } else {
                $rows = $this->db->fetchAllPrepared(
                    'SELECT id, name AS label, code AS secondary
                     FROM factions
                     ORDER BY name ASC, id ASC
                     LIMIT ?',
                    [$limit],
                );
            }
        } catch (\Throwable $e) {
            return [];
        }

        $result = [];
        foreach ($rows as $row) {
            $id = (int) $this->readField($row, 'id', 0);
            if ($id <= 0) {
                continue;
            }

            $result[] = [
                'id' => $id,
                'label' => trim((string) $this->readField($row, 'label', '')),
                'secondary' => trim((string) $this->readField($row, 'secondary', '')),
            ];
        }

        return $result;
    }

    public function joinEventAsFaction(int $factionId, int $eventId, int $characterId): bool
    {
        return $this->canManageFactionEventParticipation($factionId, $eventId, $characterId);
    }

    public function leaveEventAsFaction(int $factionId, int $eventId, int $characterId): bool
    {
        return $this->canManageFactionEventParticipation($factionId, $eventId, $characterId);
    }

    public function inviteFactionToEvent(int $factionId, int $eventId, int $inviterCharacterId): bool
    {
        return $this->canManageFactionEventParticipation($factionId, $eventId, $inviterCharacterId);
    }

    private function canManageFactionEventParticipation(int $factionId, int $eventId, int $characterId): bool
    {
        if ($factionId <= 0 || $eventId <= 0) {
            return false;
        }

        if (!$this->factionExists($factionId)) {
            return false;
        }

        if ($characterId <= 0) {
            return true;
        }

        return $this->hasOfficerRole($factionId, $characterId);
    }

    private function factionExists(int $factionId): bool
    {
        try {
            $row = $this->db->fetchOnePrepared(
                'SELECT id
                 FROM factions
                 WHERE id = ?
                 LIMIT 1',
                [$factionId],
            );
        } catch (\Throwable $e) {
            return false;
        }

        return !empty($row);
    }

    private function hasOfficerRole(int $factionId, int $characterId): bool
    {
        try {
            $row = $this->db->fetchOnePrepared(
                'SELECT role
                 FROM faction_memberships
                 WHERE faction_id = ?
                   AND character_id = ?
                   AND status = ?
                 LIMIT 1',
                [$factionId, $characterId, 'active'],
            );
        } catch (\Throwable $e) {
            return false;
        }

        if (empty($row)) {
            return false;
        }

        $role = strtolower(trim((string) $this->readField($row, 'role', '')));
        return in_array($role, ['leader', 'officer', 'advisor'], true);
    }

    /**
     * @param array<string,mixed>|object $row
     * @param mixed $default
     * @return mixed
     */
    private function readField($row, string $field, $default = null)
    {
        if (is_array($row) && array_key_exists($field, $row)) {
            return $row[$field];
        }
        if (is_object($row) && isset($row->{$field})) {
            return $row->{$field};
        }
        return $default;
    }
}
