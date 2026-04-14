<?php

declare(strict_types=1);

namespace App\Services;

use Core\AuditLogService;
use Core\Database\DbAdapterFactory;
use Core\Database\DbAdapterInterface;

class LocationAdminService
{
    /** @var DbAdapterInterface */
    private $db;
    /** @var NarrativeTagService|null */
    private $tagService = null;

    private function tagService(): NarrativeTagService
    {
        if ($this->tagService instanceof NarrativeTagService) {
            return $this->tagService;
        }
        $this->tagService = new NarrativeTagService($this->db);
        return $this->tagService;
    }

    public function __construct(?DbAdapterInterface $db = null)
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

    /**
     * @param array<int,mixed> $params
     * @return mixed
     */
    private function firstPrepared(string $sql, array $params = [])
    {
        return $this->db->fetchOnePrepared($sql, $params);
    }

    private function normalizeStatus(string $raw): string
    {
        $allowed = ['open', 'closed', 'locked', 'private'];
        return in_array($raw, $allowed, true) ? $raw : 'open';
    }

    private function normalizeAccessPolicy(string $raw): string
    {
        $allowed = ['open', 'guild', 'invite', 'owner'];
        return in_array($raw, $allowed, true) ? $raw : 'open';
    }

    private function normalizeChatType(?string $raw): ?string
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }
        $allowed = ['standard', 'free', 'roleplay', 'moderated'];
        $val = strtolower(trim($raw));
        return in_array($val, $allowed, true) ? $val : null;
    }

    private function toNullableString($value): ?string
    {
        $text = trim((string) ($value ?? ''));
        return $text !== '' ? $text : null;
    }

    public function create(object $data): void
    {
        $status = $this->normalizeStatus(trim((string) ($data->status ?? 'open')));
        $accessPolicy = $this->normalizeAccessPolicy(trim((string) ($data->access_policy ?? 'open')));
        $mapId = isset($data->map_id) && (int) $data->map_id > 0 ? (int) $data->map_id : null;
        $maxGuests = (isset($data->max_guests) && $data->max_guests !== '') ? (int) $data->max_guests : null;
        $mapX = (isset($data->map_x) && $data->map_x !== '') ? (float) $data->map_x : null;
        $mapY = (isset($data->map_y) && $data->map_y !== '') ? (float) $data->map_y : null;
        $chatType = $this->normalizeChatType($data->chat_type ?? null);

        $this->execPrepared(
            'INSERT INTO locations SET
                name              = ?,
                short_description = ?,
                description       = ?,
                map_id            = ?,
                status            = ?,
                image             = ?,
                icon              = ?,
                is_house          = ?,
                is_chat           = ?,
                is_private        = ?,
                chat_type         = ?,
                access_policy     = ?,
                max_guests        = ?,
                cost              = ?,
                min_fame          = ?,
                map_x             = ?,
                map_y             = ?,
                date_created      = NOW()',
            [
                (string) ($data->name ?? ''),
                (string) ($data->short_description ?? ''),
                $this->toNullableString($data->description ?? null),
                $mapId,
                $status,
                $this->toNullableString($data->image ?? null),
                $this->toNullableString($data->icon ?? null),
                (int) ($data->is_house ?? 0),
                (int) ($data->is_chat ?? 0),
                (int) ($data->is_private ?? 0),
                $chatType,
                $accessPolicy,
                $maxGuests,
                (int) ($data->cost ?? 0),
                (int) ($data->min_fame ?? 0),
                $mapX,
                $mapY,
            ],
        );

        $newId = (int) $this->db->lastInsertId();
        if ($newId > 0 && isset($data->tag_ids) && is_array($data->tag_ids)) {
            $this->tagService()->syncAssignments('scene', $newId, array_map('intval', $data->tag_ids));
        }
        AuditLogService::writeEvent('locations.create', ['id' => $newId, 'name' => (string) ($data->name ?? '')], 'admin');
    }

    public function update(object $data): void
    {
        $status = $this->normalizeStatus(trim((string) ($data->status ?? 'open')));
        $accessPolicy = $this->normalizeAccessPolicy(trim((string) ($data->access_policy ?? 'open')));
        $mapId = isset($data->map_id) && (int) $data->map_id > 0 ? (int) $data->map_id : null;
        $maxGuests = (isset($data->max_guests) && $data->max_guests !== '') ? (int) $data->max_guests : null;
        $mapX = (isset($data->map_x) && $data->map_x !== '') ? (float) $data->map_x : null;
        $mapY = (isset($data->map_y) && $data->map_y !== '') ? (float) $data->map_y : null;
        $chatType = $this->normalizeChatType($data->chat_type ?? null);

        $this->execPrepared(
            'UPDATE locations SET
                name              = ?,
                short_description = ?,
                description       = ?,
                map_id            = ?,
                status            = ?,
                image             = ?,
                icon              = ?,
                is_house          = ?,
                is_chat           = ?,
                is_private        = ?,
                chat_type         = ?,
                access_policy     = ?,
                max_guests        = ?,
                cost              = ?,
                min_fame          = ?,
                map_x             = ?,
                map_y             = ?,
                date_updated      = NOW()
             WHERE id = ?',
            [
                (string) ($data->name ?? ''),
                (string) ($data->short_description ?? ''),
                $this->toNullableString($data->description ?? null),
                $mapId,
                $status,
                $this->toNullableString($data->image ?? null),
                $this->toNullableString($data->icon ?? null),
                (int) ($data->is_house ?? 0),
                (int) ($data->is_chat ?? 0),
                (int) ($data->is_private ?? 0),
                $chatType,
                $accessPolicy,
                $maxGuests,
                (int) ($data->cost ?? 0),
                (int) ($data->min_fame ?? 0),
                $mapX,
                $mapY,
                (int) $data->id,
            ],
        );

        if (isset($data->tag_ids) && is_array($data->tag_ids)) {
            $this->tagService()->syncAssignments('scene', (int) $data->id, array_map('intval', $data->tag_ids));
        }
        AuditLogService::writeEvent('locations.update', ['id' => (int) $data->id], 'admin');
    }

    public function getById(int $id): array
    {
        $row = $this->firstPrepared(
            'SELECT * FROM locations WHERE id = ? AND date_deleted IS NULL LIMIT 1',
            [$id],
        );
        if (empty($row)) {
            return [];
        }
        $data = (array) $row;
        $data['narrative_tags'] = $this->tagService()->listAssignments('scene', $id, false);
        $data['narrative_tag_ids'] = array_map(static function ($tag): int {
            return (int) ($tag['id'] ?? (is_object($tag) ? $tag->id : 0));
        }, $data['narrative_tags']);
        return $data;
    }

    public function delete(int $id): void
    {
        if ($id <= 0) {
            return;
        }
        $this->execPrepared(
            'UPDATE locations SET date_deleted = NOW() WHERE id = ?',
            [$id],
        );
        AuditLogService::writeEvent('locations.delete', ['id' => $id], 'admin');
    }
}
