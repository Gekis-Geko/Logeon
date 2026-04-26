<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database\DbAdapterFactory;
use Core\Database\DbAdapterInterface;
use Core\Http\AppError;

class LocationPositionTagService
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

    private function failValidation(string $message, string $code = 'validation_error'): void
    {
        throw AppError::validation($message, [], $code);
    }

    private function rowToArray($row): array
    {
        if (is_object($row)) {
            return (array) $row;
        }
        return is_array($row) ? $row : [];
    }

    private function normalizeName($value): string
    {
        $name = trim((string) ($value ?? ''));
        if ($name === '') {
            $this->failValidation('Il nome del tag è obbligatorio', 'name_required');
        }
        if (mb_strlen($name, 'UTF-8') > 80) {
            $name = mb_substr($name, 0, 80, 'UTF-8');
        }
        return $name;
    }

    private function normalizeShortDescription($value): ?string
    {
        $text = trim((string) ($value ?? ''));
        if ($text === '') {
            return null;
        }
        if (mb_strlen($text, 'UTF-8') > 255) {
            $text = mb_substr($text, 0, 255, 'UTF-8');
        }
        return $text;
    }

    private function normalizeThumbnail($value): ?string
    {
        $url = trim((string) ($value ?? ''));
        return $url !== '' ? $url : null;
    }

    // ── Game endpoint ─────────────────────────────────────────────────────

    public function listForLocation(int $locationId): array
    {
        if ($locationId <= 0) {
            return [];
        }

        return $this->fetchPrepared(
            'SELECT id, location_id, name, short_description, thumbnail
             FROM location_position_tags
             WHERE location_id = ?
               AND is_active = 1
             ORDER BY name ASC',
            [$locationId],
        );
    }

    // ── Admin ─────────────────────────────────────────────────────────────

    public function adminList(array $filters, int $limit, int $page, string $orderBy): array
    {
        $limit = max(1, min(100, $limit));
        $page  = max(1, $page);
        $offset = ($page - 1) * $limit;

        $where  = ['1=1'];
        $params = [];

        $locationId = isset($filters['location_id']) ? (int) $filters['location_id'] : 0;
        if ($locationId > 0) {
            $where[]  = 'lpt.location_id = ?';
            $params[] = $locationId;
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $where[]  = 'lpt.name LIKE ?';
            $params[] = '%' . $search . '%';
        }

        $isActive = $filters['is_active'] ?? '';
        if ($isActive !== '' && $isActive !== null) {
            $where[]  = 'lpt.is_active = ?';
            $params[] = (int) $isActive;
        }

        $allowedOrder = ['lpt.id', 'lpt.name', 'lpt.location_id', 'lpt.is_active'];
        $allowedDir   = ['ASC', 'DESC'];
        $orderParts   = explode('|', $orderBy);
        $orderCol     = in_array($orderParts[0] ?? '', $allowedOrder, true) ? $orderParts[0] : 'lpt.name';
        $orderDir     = in_array(strtoupper($orderParts[1] ?? ''), $allowedDir, true) ? strtoupper($orderParts[1]) : 'ASC';

        $whereClause = implode(' AND ', $where);

        $totalRow = $this->firstPrepared(
            'SELECT COUNT(*) AS cnt
             FROM location_position_tags lpt
             WHERE ' . $whereClause,
            $params,
        );
        $total = (int) ($totalRow->cnt ?? 0);

        $rowParams   = array_merge($params, [$limit, $offset]);
        $rows = $this->fetchPrepared(
            'SELECT lpt.id, lpt.location_id, lpt.name, lpt.short_description, lpt.thumbnail,
                    lpt.is_active, lpt.created_at, lpt.updated_at,
                    l.name AS location_name
             FROM location_position_tags lpt
             LEFT JOIN locations l ON l.id = lpt.location_id
             WHERE ' . $whereClause . '
             ORDER BY ' . $orderCol . ' ' . $orderDir . '
             LIMIT ? OFFSET ?',
            $rowParams,
        );

        return [
            'rows'  => $rows ?: [],
            'total' => $total,
            'page'  => $page,
            'limit' => $limit,
        ];
    }

    public function adminCreate(array $data): array
    {
        $locationId      = (int) ($data['location_id'] ?? 0);
        if ($locationId <= 0) {
            $this->failValidation('location_id obbligatorio', 'location_id_required');
        }
        $name            = $this->normalizeName($data['name'] ?? '');
        $shortDesc       = $this->normalizeShortDescription($data['short_description'] ?? null);
        $thumbnail       = $this->normalizeThumbnail($data['thumbnail'] ?? null);
        $isActive        = isset($data['is_active']) ? (int) (bool) $data['is_active'] : 1;

        $this->execPrepared(
            'INSERT INTO location_position_tags (location_id, name, short_description, thumbnail, is_active)
             VALUES (?, ?, ?, ?, ?)',
            [$locationId, $name, $shortDesc, $thumbnail, $isActive],
        );
        $id = $this->db->lastInsertId();

        return $this->getById((int) $id);
    }

    public function adminUpdate(int $id, array $data): array
    {
        $row = $this->getById($id);
        if (empty($row)) {
            $this->failValidation('Tag non trovato', 'not_found');
        }

        $locationId = isset($data['location_id']) ? (int) $data['location_id'] : (int) ($row['location_id'] ?? 0);
        if ($locationId <= 0) {
            $this->failValidation('location_id obbligatorio', 'location_id_required');
        }
        $name      = array_key_exists('name', $data) ? $this->normalizeName($data['name']) : (string) ($row['name'] ?? '');
        $shortDesc = array_key_exists('short_description', $data)
            ? $this->normalizeShortDescription($data['short_description'])
            : ($row['short_description'] ?? null);
        $thumbnail = array_key_exists('thumbnail', $data)
            ? $this->normalizeThumbnail($data['thumbnail'])
            : ($row['thumbnail'] ?? null);
        $isActive  = array_key_exists('is_active', $data) ? (int) (bool) $data['is_active'] : (int) ($row['is_active'] ?? 1);

        $this->execPrepared(
            'UPDATE location_position_tags
             SET location_id = ?, name = ?, short_description = ?, thumbnail = ?, is_active = ?
             WHERE id = ?',
            [$locationId, $name, $shortDesc, $thumbnail, $isActive, $id],
        );

        return $this->getById($id);
    }

    public function adminDelete(int $id): bool
    {
        $row = $this->getById($id);
        if (empty($row)) {
            $this->failValidation('Tag non trovato', 'not_found');
        }

        $this->execPrepared('DELETE FROM location_position_tags WHERE id = ?', [$id]);
        return true;
    }

    public function getById(int $id): array
    {
        $row = $this->firstPrepared(
            'SELECT lpt.*, l.name AS location_name
             FROM location_position_tags lpt
             LEFT JOIN locations l ON l.id = lpt.location_id
             WHERE lpt.id = ?
             LIMIT 1',
            [$id],
        );
        return $this->rowToArray($row);
    }
}
