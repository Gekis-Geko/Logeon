<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database\DbAdapterFactory;
use Core\Database\DbAdapterInterface;
use Core\Http\AppError;

class SystemEventParticipationService
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

    private function rowToArray($row): array
    {
        if (is_object($row)) {
            return (array) $row;
        }
        return is_array($row) ? $row : [];
    }

    private function decodeRow(array $row): array
    {
        if (isset($row['meta_json']) && is_string($row['meta_json'])) {
            $decoded = json_decode($row['meta_json'], true);
            $row['meta_json'] = is_array($decoded) ? $decoded : (object) [];
        }
        return $row;
    }

    public function viewerFactionIds(int $characterId): array
    {
        if ($characterId <= 0) {
            return [];
        }

        $rows = $this->fetchPrepared(
            'SELECT faction_id
             FROM faction_memberships
             WHERE character_id = ?
               AND status = ?',
            [$characterId, 'active'],
        );
        $ids = [];
        foreach ($rows as $row) {
            $id = (int) ($row->faction_id ?? 0);
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    public function viewerHasJoined(int $eventId, int $viewerCharacterId, array $viewerFactionIds = []): bool
    {
        $eventId = (int) $eventId;
        if ($eventId <= 0) {
            return false;
        }

        $clauses = [];
        $params = [$eventId, 'joined'];
        if ($viewerCharacterId > 0) {
            $clauses[] = '(participant_mode = ? AND character_id = ?)';
            $params[] = 'character';
            $params[] = (int) $viewerCharacterId;
        }
        if (!empty($viewerFactionIds)) {
            $factionIds = [];
            foreach ($viewerFactionIds as $factionId) {
                $factionId = (int) $factionId;
                if ($factionId > 0) {
                    $factionIds[] = $factionId;
                }
            }
            if (!empty($factionIds)) {
                $placeholders = implode(',', array_fill(0, count($factionIds), '?'));
                $clauses[] = '(participant_mode = ? AND faction_id IN (' . $placeholders . '))';
                $params[] = 'faction';
                foreach ($factionIds as $factionId) {
                    $params[] = $factionId;
                }
            }
        }

        if (empty($clauses)) {
            return false;
        }

        $row = $this->firstPrepared(
            'SELECT COUNT(*) AS n
             FROM system_event_participations
             WHERE system_event_id = ?
               AND status = ?
               AND (' . implode(' OR ', $clauses) . ')
             LIMIT 1',
            $params,
        );

        return (int) ($row->n ?? 0) > 0;
    }

    public function listByEvent(int $eventId): array
    {
        $eventId = (int) $eventId;
        if ($eventId <= 0) {
            return [];
        }

        $rows = $this->fetchPrepared(
            'SELECT p.*,
                    c.name AS character_name,
                    c.surname AS character_surname,
                    f.name AS faction_name
             FROM system_event_participations p
             LEFT JOIN characters c ON c.id = p.character_id
             LEFT JOIN factions f ON f.id = p.faction_id
             WHERE p.system_event_id = ?
             ORDER BY p.date_joined DESC, p.id DESC',
            [$eventId],
        );
        $out = [];
        foreach ($rows as $row) {
            $record = $this->decodeRow($this->rowToArray($row));
            if (!empty($record['character_name'])) {
                $name = trim((string) $record['character_name'] . ' ' . (string) ($record['character_surname'] ?? ''));
                $record['participant_label'] = $name !== '' ? $name : ('Personaggio #' . (int) ($record['character_id'] ?? 0));
            } elseif (!empty($record['faction_name'])) {
                $record['participant_label'] = (string) $record['faction_name'];
            } else {
                $record['participant_label'] = ((string) ($record['participant_mode'] ?? 'character') === 'faction')
                    ? ('Fazione #' . (int) ($record['faction_id'] ?? 0))
                    : ('Personaggio #' . (int) ($record['character_id'] ?? 0));
            }
            $out[] = $record;
        }

        return $out;
    }

    public function join(array $event, array $data, int $actorCharacterId, bool $isStaff = false): array
    {
        $eventId = (int) ($event['id'] ?? 0);
        if ($eventId <= 0) {
            throw AppError::notFound('Evento di sistema non trovato', [], 'system_event_not_found');
        }

        $status = strtolower(trim((string) ($event['status'] ?? '')));
        if (!in_array($status, ['scheduled', 'active'], true)) {
            throw AppError::validation('Evento non aderibile nello stato attuale', [], 'system_event_invalid_state');
        }

        $mode = strtolower(trim((string) ($event['participant_mode'] ?? 'character')));
        if ($mode !== 'faction') {
            $characterId = (int) ($data['character_id'] ?? 0);
            if ($characterId <= 0) {
                $characterId = $actorCharacterId;
            }
            if ($characterId <= 0) {
                throw AppError::validation('Personaggio non valido', [], 'system_event_participation_forbidden');
            }
            if (!$isStaff && $characterId !== $actorCharacterId) {
                throw AppError::unauthorized('Non puoi aderire a nome di un altro personaggio', [], 'system_event_participation_forbidden');
            }

            return $this->upsert($eventId, 'character', $characterId, 0, 'joined', $actorCharacterId, true);
        }

        $factionId = (int) ($data['faction_id'] ?? 0);
        if ($factionId <= 0) {
            throw AppError::validation('Fazione obbligatoria per questo evento', [], 'system_event_participation_forbidden');
        }
        if (!$isStaff) {
            $this->assertFactionOfficer($actorCharacterId, $factionId);
        }

        return $this->upsert($eventId, 'faction', 0, $factionId, 'joined', $actorCharacterId, true);
    }

    public function leave(array $event, array $data, int $actorCharacterId, bool $isStaff = false): array
    {
        $eventId = (int) ($event['id'] ?? 0);
        if ($eventId <= 0) {
            throw AppError::notFound('Evento di sistema non trovato', [], 'system_event_not_found');
        }

        $mode = strtolower(trim((string) ($event['participant_mode'] ?? 'character')));
        if ($mode !== 'faction') {
            $characterId = (int) ($data['character_id'] ?? 0);
            if ($characterId <= 0) {
                $characterId = $actorCharacterId;
            }
            if ($characterId <= 0) {
                throw AppError::validation('Personaggio non valido', [], 'system_event_participation_forbidden');
            }
            if (!$isStaff && $characterId !== $actorCharacterId) {
                throw AppError::unauthorized('Non puoi ritirare un altro personaggio', [], 'system_event_participation_forbidden');
            }

            return $this->upsert($eventId, 'character', $characterId, 0, 'left', $actorCharacterId, false);
        }

        $factionId = (int) ($data['faction_id'] ?? 0);
        if ($factionId <= 0) {
            throw AppError::validation('Fazione obbligatoria per questo evento', [], 'system_event_participation_forbidden');
        }
        if (!$isStaff) {
            $this->assertFactionOfficer($actorCharacterId, $factionId);
        }

        return $this->upsert($eventId, 'faction', 0, $factionId, 'left', $actorCharacterId, false);
    }

    public function adminUpsert(int $eventId, array $data, int $actorCharacterId = 0): array
    {
        $mode = strtolower(trim((string) ($data['participant_mode'] ?? '')));
        if ($mode === '') {
            $mode = ((int) ($data['faction_id'] ?? 0) > 0) ? 'faction' : 'character';
        }
        if ($mode !== 'faction') {
            $mode = 'character';
        }

        $characterId = (int) ($data['character_id'] ?? 0);
        $factionId = (int) ($data['faction_id'] ?? 0);
        $status = strtolower(trim((string) ($data['status'] ?? 'joined')));
        if (!in_array($status, ['joined', 'left', 'removed'], true)) {
            $status = 'joined';
        }

        return $this->upsert($eventId, $mode, $characterId, $factionId, $status, $actorCharacterId, false);
    }

    public function adminRemove(int $eventId, array $data): array
    {
        $eventId = (int) $eventId;
        if ($eventId <= 0) {
            throw AppError::notFound('Evento di sistema non trovato', [], 'system_event_not_found');
        }

        $id = (int) ($data['id'] ?? 0);
        if ($id > 0) {
            $this->execPrepared(
                'DELETE FROM system_event_participations WHERE id = ? AND system_event_id = ?',
                [$id, $eventId],
            );
            return ['removed' => 1];
        }

        $characterId = (int) ($data['character_id'] ?? 0);
        $factionId = (int) ($data['faction_id'] ?? 0);
        if ($characterId <= 0 && $factionId <= 0) {
            throw AppError::validation('Partecipazione da rimuovere non valida', [], 'system_event_participation_forbidden');
        }

        if ($characterId > 0) {
            $this->execPrepared(
                'DELETE FROM system_event_participations
                 WHERE system_event_id = ?
                   AND character_id = ?',
                [$eventId, $characterId],
            );
        } else {
            $this->execPrepared(
                'DELETE FROM system_event_participations
                 WHERE system_event_id = ?
                   AND faction_id = ?',
                [$eventId, $factionId],
            );
        }

        return ['removed' => 1];
    }

    private function upsert(
        int $eventId,
        string $mode,
        int $characterId,
        int $factionId,
        string $status,
        int $actorCharacterId = 0,
        bool $strictConflict = false,
    ): array {
        $mode = ($mode === 'faction') ? 'faction' : 'character';
        if ($mode === 'character' && $characterId <= 0) {
            throw AppError::validation('Personaggio obbligatorio', [], 'system_event_participation_forbidden');
        }
        if ($mode === 'faction' && $factionId <= 0) {
            throw AppError::validation('Fazione obbligatoria', [], 'system_event_participation_forbidden');
        }

        $status = strtolower(trim($status));
        if (!in_array($status, ['joined', 'left', 'removed'], true)) {
            $status = 'joined';
        }

        $whereSql = 'system_event_id = ?';
        $whereParams = [(int) $eventId];
        if ($mode === 'character') {
            $whereSql .= ' AND character_id = ?';
            $whereParams[] = (int) $characterId;
        } else {
            $whereSql .= ' AND faction_id = ?';
            $whereParams[] = (int) $factionId;
        }

        $existing = $this->firstPrepared(
            'SELECT *
             FROM system_event_participations
             WHERE ' . $whereSql . '
             LIMIT 1',
            $whereParams,
        );
        if (!empty($existing)) {
            $current = strtolower(trim((string) ($existing->status ?? 'joined')));
            if ($strictConflict && $status === 'joined' && $current === 'joined') {
                throw AppError::validation('Partecipazione gia presente', [], 'system_event_participation_conflict');
            }

            $this->execPrepared(
                'UPDATE system_event_participations SET
                    participant_mode = ?,
                    status = ?,
                    joined_by_character_id = ?,
                    date_joined = CASE WHEN ? = \'joined\' THEN NOW() ELSE date_joined END,
                    date_left = CASE WHEN ? = \'joined\' THEN NULL ELSE NOW() END
                 WHERE id = ?',
                [
                    $mode,
                    $status,
                    $actorCharacterId > 0 ? (int) $actorCharacterId : null,
                    $status,
                    $status,
                    (int) ($existing->id ?? 0),
                ],
            );

            $row = $this->firstPrepared(
                'SELECT *
                 FROM system_event_participations
                 WHERE id = ?
                 LIMIT 1',
                [(int) ($existing->id ?? 0)],
            );
            return $this->decodeRow($this->rowToArray($row));
        }

        if ($status !== 'joined' && $strictConflict) {
            throw AppError::validation('Partecipazione non trovata', [], 'system_event_participation_conflict');
        }

        $this->execPrepared(
            'INSERT INTO system_event_participations
             (system_event_id, participant_mode, character_id, faction_id, status, joined_by_character_id, date_joined, date_left)
             VALUES (?, ?, ?, ?, ?, ?, NOW(), CASE WHEN ? = \'joined\' THEN NULL ELSE NOW() END)',
            [
                (int) $eventId,
                $mode,
                $mode === 'character' ? (int) $characterId : null,
                $mode === 'faction' ? (int) $factionId : null,
                $status,
                $actorCharacterId > 0 ? (int) $actorCharacterId : null,
                $status,
            ],
        );

        $id = (int) $this->db->lastInsertId();
        $row = $this->firstPrepared(
            'SELECT *
             FROM system_event_participations
             WHERE id = ?
             LIMIT 1',
            [$id],
        );
        return $this->decodeRow($this->rowToArray($row));
    }

    private function assertFactionOfficer(int $characterId, int $factionId): void
    {
        if ($characterId <= 0 || $factionId <= 0) {
            throw AppError::unauthorized('Partecipazione fazione non autorizzata', [], 'system_event_participation_forbidden');
        }

        $row = $this->firstPrepared(
            'SELECT role
             FROM faction_memberships
             WHERE faction_id = ?
               AND character_id = ?
               AND status = ?
             LIMIT 1',
            [(int) $factionId, (int) $characterId, 'active'],
        );

        if (empty($row)) {
            throw AppError::unauthorized('Non appartieni alla fazione selezionata', [], 'system_event_participation_forbidden');
        }

        $role = strtolower(trim((string) ($row->role ?? '')));
        if (!in_array($role, ['leader', 'officer', 'advisor'], true)) {
            throw AppError::unauthorized('Solo leader o officer possono aderire per la fazione', [], 'system_event_participation_forbidden');
        }
    }

    public function activeParticipantsForRewards(int $eventId, string $participantMode = 'character'): array
    {
        $eventId = (int) $eventId;
        if ($eventId <= 0) {
            return [];
        }

        $participantMode = strtolower(trim($participantMode));
        if ($participantMode !== 'faction') {
            $rows = $this->fetchPrepared(
                'SELECT DISTINCT character_id
                 FROM system_event_participations
                 WHERE system_event_id = ?
                   AND participant_mode = ?
                   AND status = ?
                   AND character_id IS NOT NULL',
                [$eventId, 'character', 'joined'],
            );
            $out = [];
            foreach ($rows as $row) {
                $id = (int) ($row->character_id ?? 0);
                if ($id > 0) {
                    $out[$id] = $id;
                }
            }
            return array_values($out);
        }

        $factionRows = $this->fetchPrepared(
            'SELECT DISTINCT faction_id
             FROM system_event_participations
             WHERE system_event_id = ?
               AND participant_mode = ?
               AND status = ?
               AND faction_id IS NOT NULL',
            [$eventId, 'faction', 'joined'],
        );
        $factionIds = [];
        foreach ($factionRows as $row) {
            $id = (int) ($row->faction_id ?? 0);
            if ($id > 0) {
                $factionIds[] = $id;
            }
        }
        if (empty($factionIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($factionIds), '?'));
        $rows = $this->fetchPrepared(
            'SELECT DISTINCT character_id
             FROM faction_memberships
             WHERE faction_id IN (' . $placeholders . ')
               AND status = ?',
            array_merge(array_map('intval', $factionIds), ['active']),
        );
        $out = [];
        foreach ($rows as $row) {
            $id = (int) ($row->character_id ?? 0);
            if ($id > 0) {
                $out[$id] = $id;
            }
        }

        return array_values($out);
    }
}
