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
                l.name AS location_name,
                ca_first.id AS linked_archive_id,
                ca_first.title AS linked_archive_title,
                IFNULL(ca_count.linked_archives_count, 0) AS linked_archives_count
            FROM character_events e
            LEFT JOIN locations l ON e.location_id = l.id
            LEFT JOIN character_chat_archives ca_first
                ON ca_first.id = (
                    SELECT ca2.id
                    FROM character_chat_archives ca2
                    WHERE ca2.diary_event_id = e.id
                      AND ca2.deleted_at IS NULL
                    ORDER BY ca2.created_at DESC, ca2.id DESC
                    LIMIT 1
                )
            LEFT JOIN (
                SELECT diary_event_id, COUNT(*) AS linked_archives_count
                FROM character_chat_archives
                WHERE diary_event_id IS NOT NULL
                  AND deleted_at IS NULL
                GROUP BY diary_event_id
            ) ca_count ON ca_count.diary_event_id = e.id
            WHERE e.character_id = ?
            ORDER BY COALESCE(e.date_event, e.date_created) DESC, e.id DESC',
            [$characterId],
        );

        return !empty($rows) ? $rows : [];
    }

    public function listPublicByCharacter(int $characterId): array
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
                l.name AS location_name,
                ca_first.id AS linked_archive_id,
                ca_first.title AS linked_archive_title,
                IFNULL(ca_count.linked_archives_count, 0) AS linked_archives_count
            FROM character_events e
            LEFT JOIN locations l ON e.location_id = l.id
            LEFT JOIN character_chat_archives ca_first
                ON ca_first.id = (
                    SELECT ca2.id
                    FROM character_chat_archives ca2
                    WHERE ca2.diary_event_id = e.id
                      AND ca2.deleted_at IS NULL
                    ORDER BY ca2.created_at DESC, ca2.id DESC
                    LIMIT 1
                )
            LEFT JOIN (
                SELECT diary_event_id, COUNT(*) AS linked_archives_count
                FROM character_chat_archives
                WHERE diary_event_id IS NOT NULL
                  AND deleted_at IS NULL
                GROUP BY diary_event_id
            ) ca_count ON ca_count.diary_event_id = e.id
            WHERE e.character_id = ?
              AND e.is_visible = 1
            ORDER BY COALESCE(e.date_event, e.date_created) DESC, e.id DESC',
            [$characterId],
        );

        return !empty($rows) ? $rows : [];
    }

    public function searchByCharacterTitle(int $characterId, string $query, int $limit = 10): array
    {
        if ($characterId <= 0) {
            return [];
        }

        $needle = trim($query);
        if ($needle === '') {
            return [];
        }

        if ($limit < 1) {
            $limit = 1;
        }
        if ($limit > 20) {
            $limit = 20;
        }

        $like = '%' . $needle . '%';

        $rows = $this->fetchPrepared(
            'SELECT e.id,
                e.character_id,
                e.title,
                e.location_id,
                e.date_event,
                e.is_visible,
                e.date_created,
                l.name AS location_name
            FROM character_events e
            LEFT JOIN locations l ON e.location_id = l.id
            WHERE e.character_id = ?
              AND e.title LIKE ?
            ORDER BY
                CASE WHEN e.title = ? THEN 0 ELSE 1 END ASC,
                COALESCE(e.date_event, e.date_created) DESC,
                e.id DESC
            LIMIT ' . (int) $limit,
            [$characterId, $like, $needle],
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
