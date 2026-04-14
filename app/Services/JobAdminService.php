<?php

declare(strict_types=1);

namespace App\Services;

use Core\AuditLogService;
use Core\Database\DbAdapterFactory;
use Core\Database\DbAdapterInterface;

class JobAdminService
{
    /** @var DbAdapterInterface */
    private $db;

    public function __construct(?DbAdapterInterface $db = null)
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

    private function normalizeOrderBy(string $raw): string
    {
        $allowed = [
            'j.id' => 'j.id',
            'id' => 'j.id',
            'j.name' => 'j.name',
            'name' => 'j.name',
            'location_name' => 'l.name',
            'base_pay' => 'j.base_pay',
            'j.base_pay' => 'j.base_pay',
            'daily_tasks' => 'j.daily_tasks',
            'j.daily_tasks' => 'j.daily_tasks',
            'is_active' => 'j.is_active',
            'j.is_active' => 'j.is_active',
        ];

        $parts = explode('|', $raw);
        $field = trim($parts[0] ?? '');
        $dir = strtoupper(trim($parts[1] ?? 'ASC')) === 'DESC' ? 'DESC' : 'ASC';
        $col = $allowed[$field] ?? 'j.id';

        return $col . ' ' . $dir;
    }

    public function list(object $data): array
    {
        $query = (isset($data->query) && is_object($data->query)) ? $data->query : (object) [];
        $name = isset($query->name) ? trim((string) $query->name) : '';
        $isActive = (isset($query->is_active) && $query->is_active !== '') ? (int) $query->is_active : -1;
        $locationId = isset($query->location_id) ? (int) $query->location_id : 0;
        $page = max(1, (int) ($data->page ?? 1));
        $resultsPage = max(5, min(100, (int) ($data->results ?? 20)));
        $orderByRaw = isset($data->orderBy) ? (string) $data->orderBy : 'j.id|ASC';
        $orderBy = $this->normalizeOrderBy($orderByRaw);

        $where = ['1=1'];
        $params = [];

        if ($name !== '') {
            $where[] = 'j.name LIKE ?';
            $params[] = '%' . $name . '%';
        }
        if ($isActive !== -1) {
            $where[] = 'j.is_active = ?';
            $params[] = $isActive;
        }
        if ($locationId > 0) {
            $where[] = 'j.location_id = ?';
            $params[] = $locationId;
        }

        $whereClause = 'WHERE ' . implode(' AND ', $where);

        $countRow = $this->firstPrepared(
            'SELECT COUNT(*) AS total
             FROM jobs j
             LEFT JOIN locations l ON j.location_id = l.id
             ' . $whereClause,
            $params,
        );
        $total = !empty($countRow) ? (int) $countRow->total : 0;

        $offset = ($page - 1) * $resultsPage;

        $rows = $this->fetchPrepared(
            'SELECT j.id, j.name, j.description, j.icon, j.location_id, j.min_socialstatus_id,
                    j.base_pay, j.daily_tasks, j.is_active, j.date_created,
                    l.name AS location_name,
                    ss.name AS social_status_name
             FROM jobs j
             LEFT JOIN locations l ON j.location_id = l.id
             LEFT JOIN social_status ss ON j.min_socialstatus_id = ss.id
             ' . $whereClause . '
             ORDER BY ' . $orderBy . '
             LIMIT ? OFFSET ?',
            array_merge($params, [$resultsPage, $offset]),
        );

        return [
            'dataset' => $rows ?: [],
            'properties' => [
                'query' => $query,
                'page' => $page,
                'results_page' => $resultsPage,
                'orderBy' => $orderByRaw,
                'tot' => ['count' => $total],
            ],
        ];
    }

    public function create(object $data): void
    {
        $name = trim((string) ($data->name ?? ''));
        $description = trim((string) ($data->description ?? ''));
        $icon = trim((string) ($data->icon ?? ''));
        $locationId = isset($data->location_id) && $data->location_id !== '' && (int) $data->location_id > 0
            ? (int) $data->location_id : null;
        $minSocialstatusId = isset($data->min_socialstatus_id) && $data->min_socialstatus_id !== '' && (int) $data->min_socialstatus_id > 0
            ? (int) $data->min_socialstatus_id : null;
        $basePay = max(0, (int) ($data->base_pay ?? 0));
        $dailyTasks = max(1, (int) ($data->daily_tasks ?? 2));
        $isActive = isset($data->is_active) ? (int) (bool) $data->is_active : 1;
        $defaultIcon = '/assets/imgs/defaults-images/default-icon.png';

        $this->execPrepared(
            'INSERT INTO jobs
                (name, description, icon, location_id, min_socialstatus_id, base_pay, daily_tasks, is_active, date_created)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())',
            [
                $name,
                $description !== '' ? $description : null,
                $icon !== '' ? $icon : $defaultIcon,
                $locationId,
                $minSocialstatusId,
                $basePay,
                $dailyTasks,
                $isActive,
            ],
        );
        AuditLogService::writeEvent('jobs.create', ['name' => $name], 'admin');
    }

    public function update(object $data): void
    {
        $id = (int) ($data->id ?? 0);
        $name = trim((string) ($data->name ?? ''));
        $description = trim((string) ($data->description ?? ''));
        $icon = trim((string) ($data->icon ?? ''));
        $locationId = isset($data->location_id) && $data->location_id !== '' && (int) $data->location_id > 0
            ? (int) $data->location_id : null;
        $minSocialstatusId = isset($data->min_socialstatus_id) && $data->min_socialstatus_id !== '' && (int) $data->min_socialstatus_id > 0
            ? (int) $data->min_socialstatus_id : null;
        $basePay = max(0, (int) ($data->base_pay ?? 0));
        $dailyTasks = max(1, (int) ($data->daily_tasks ?? 2));
        $isActive = isset($data->is_active) ? (int) (bool) $data->is_active : 1;
        $defaultIcon = '/assets/imgs/defaults-images/default-icon.png';

        if ($id <= 0 || $name === '') {
            return;
        }

        $this->execPrepared(
            'UPDATE jobs SET
                name = ?,
                description = ?,
                icon = ?,
                location_id = ?,
                min_socialstatus_id = ?,
                base_pay = ?,
                daily_tasks = ?,
                is_active = ?,
                date_updated = NOW()
             WHERE id = ?',
            [
                $name,
                $description !== '' ? $description : null,
                $icon !== '' ? $icon : $defaultIcon,
                $locationId,
                $minSocialstatusId,
                $basePay,
                $dailyTasks,
                $isActive,
                $id,
            ],
        );
        AuditLogService::writeEvent('jobs.update', ['id' => $id], 'admin');
    }

    public function delete(int $id): void
    {
        if ($id <= 0) {
            return;
        }
        $this->execPrepared('DELETE FROM jobs WHERE id = ?', [$id]);
        AuditLogService::writeEvent('jobs.delete', ['id' => $id], 'admin');
    }
}
