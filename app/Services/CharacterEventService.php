<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database\DbAdapterFactory;
use Core\Database\DbAdapterInterface;

class CharacterEventService
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

    private function execPrepared(string $sql, array $params = []): bool
    {
        return $this->db->executePrepared($sql, $params);
    }

    public function listByCharacter(int $characterId): array
    {
        if ($characterId <= 0) {
            return [];
        }

        $rows = $this->fetchPrepared(
            'SELECT e.id,
                e.character_id,
                e.title,
                e.body,
                e.location_id,
                e.date_event,
                e.is_visible,
                e.created_by_user_id,
                e.created_by_character_id,
                e.date_created,
                e.date_updated,
                l.name AS location_name
            FROM character_events e
            LEFT JOIN locations l ON e.location_id = l.id
            WHERE e.character_id = ?
            ORDER BY COALESCE(e.date_event, e.date_created) DESC, e.id DESC',
            [$characterId],
        );

        return !empty($rows) ? $rows : [];
    }

    public function getById(int $eventId)
    {
        if ($eventId <= 0) {
            return null;
        }

        return $this->firstPrepared(
            'SELECT id, character_id
            FROM character_events
            WHERE id = ?
            LIMIT 1',
            [$eventId],
        );
    }

    public function create(int $characterId, int $userId, ?int $authorCharacterId, array $payload): void
    {
        $locationId = !empty($payload['location_id']) ? (int) $payload['location_id'] : null;
        $dateEvent = isset($payload['date_event']) ? trim((string) $payload['date_event']) : null;
        if ($dateEvent === '') {
            $dateEvent = null;
        }
        $isVisible = (isset($payload['is_visible']) && (int) $payload['is_visible'] === 1) ? 1 : 0;

        $this->execPrepared(
            'INSERT INTO character_events
                (character_id, title, body, location_id, date_event, is_visible, created_by_user_id, created_by_character_id, date_created)
             VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, NOW())',
            [
                $characterId,
                (string) $payload['title'],
                (string) $payload['body'],
                $locationId,
                $dateEvent,
                $isVisible,
                $userId,
                $authorCharacterId,
            ],
        );
    }

    public function update(int $eventId, array $payload): void
    {
        $locationId = !empty($payload['location_id']) ? (int) $payload['location_id'] : null;
        $dateEvent = isset($payload['date_event']) ? trim((string) $payload['date_event']) : null;
        if ($dateEvent === '') {
            $dateEvent = null;
        }
        $isVisible = (isset($payload['is_visible']) && (int) $payload['is_visible'] === 1) ? 1 : 0;

        $this->execPrepared(
            'UPDATE character_events SET
                title = ?,
                body = ?,
                location_id = ?,
                date_event = ?,
                is_visible = ?,
                date_updated = NOW()
            WHERE id = ?',
            [(string) $payload['title'], (string) $payload['body'], $locationId, $dateEvent, $isVisible, $eventId],
        );
    }

    public function delete(int $eventId): void
    {
        if ($eventId <= 0) {
            return;
        }

        $this->execPrepared(
            'DELETE FROM character_events WHERE id = ?',
            [$eventId],
        );
    }
}
