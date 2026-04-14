<?php

declare(strict_types=1);

namespace App\Services;

use Core\AuditLogService;
use Core\Database\DbAdapterFactory;
use Core\Database\DbAdapterInterface;

class JobTaskAdminService
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
            'jt.id' => 'jt.id',
            'id' => 'jt.id',
            'jt.title' => 'jt.title',
            'title' => 'jt.title',
            'job_name' => 'j.name',
            'jt.min_level' => 'jt.min_level',
            'min_level' => 'jt.min_level',
            'is_active' => 'jt.is_active',
            'jt.is_active' => 'jt.is_active',
        ];

        $parts = explode('|', $raw);
        $field = trim($parts[0] ?? '');
        $dir = strtoupper(trim($parts[1] ?? 'ASC')) === 'DESC' ? 'DESC' : 'ASC';
        $col = $allowed[$field] ?? 'jt.id';

        return $col . ' ' . $dir;
    }

    public function list(object $data): array
    {
        $query = (isset($data->query) && is_object($data->query)) ? $data->query : (object) [];
        $title = isset($query->title) ? trim((string) $query->title) : '';
        $jobId = isset($query->job_id) ? (int) $query->job_id : 0;
        $isActive = (isset($query->is_active) && $query->is_active !== '') ? (int) $query->is_active : -1;
        $page = max(1, (int) ($data->page ?? 1));
        $resultsPage = max(5, min(100, (int) ($data->results ?? 20)));
        $orderByRaw = isset($data->orderBy) ? (string) $data->orderBy : 'jt.id|ASC';
        $orderBy = $this->normalizeOrderBy($orderByRaw);

        $where = ['1=1'];
        $params = [];

        if ($title !== '') {
            $where[] = 'jt.title LIKE ?';
            $params[] = '%' . $title . '%';
        }
        if ($jobId > 0) {
            $where[] = 'jt.job_id = ?';
            $params[] = $jobId;
        }
        if ($isActive !== -1) {
            $where[] = 'jt.is_active = ?';
            $params[] = $isActive;
        }

        $whereClause = 'WHERE ' . implode(' AND ', $where);

        $countRow = $this->firstPrepared(
            'SELECT COUNT(*) AS total
             FROM job_tasks jt
             LEFT JOIN jobs j ON jt.job_id = j.id
             ' . $whereClause,
            $params,
        );
        $total = !empty($countRow) ? (int) $countRow->total : 0;

        $offset = ($page - 1) * $resultsPage;

        $rows = $this->fetchPrepared(
            'SELECT jt.id, jt.job_id, jt.title, jt.body, jt.min_level,
                    jt.requires_location_id, jt.is_active, jt.date_created,
                    j.name AS job_name,
                    l.name AS requires_location_name
             FROM job_tasks jt
             LEFT JOIN jobs j ON jt.job_id = j.id
             LEFT JOIN locations l ON jt.requires_location_id = l.id
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

    public function getWithChoices(int $id): ?object
    {
        if ($id <= 0) {
            return null;
        }

        $task = $this->firstPrepared(
            'SELECT jt.id, jt.job_id, jt.title, jt.body, jt.min_level,
                    jt.requires_location_id, jt.is_active,
                    j.name AS job_name,
                    l.name AS requires_location_name
             FROM job_tasks jt
             LEFT JOIN jobs j ON jt.job_id = j.id
             LEFT JOIN locations l ON jt.requires_location_id = l.id
             WHERE jt.id = ?',
            [$id],
        );

        if (empty($task)) {
            return null;
        }

        $choices = $this->fetchPrepared(
            'SELECT id, choice_code, label, pay, fame, points
             FROM job_task_choices
             WHERE task_id = ?
             ORDER BY id ASC',
            [$id],
        );

        $task->choices = $choices ?: [];

        return $task;
    }

    public function create(object $data): int
    {
        $jobId = (int) ($data->job_id ?? 0);
        $title = trim((string) ($data->title ?? ''));
        $body = trim((string) ($data->body ?? ''));
        $minLevel = max(1, (int) ($data->min_level ?? 1));
        $requiresLocationId = isset($data->requires_location_id) && (int) $data->requires_location_id > 0
            ? (int) $data->requires_location_id : null;
        $isActive = isset($data->is_active) ? (int) (bool) $data->is_active : 1;

        $this->execPrepared(
            'INSERT INTO job_tasks
            (job_id, title, body, min_level, requires_location_id, is_active, date_created)
            VALUES (?, ?, ?, ?, ?, ?, NOW())',
            [$jobId, $title, $body !== '' ? $body : null, $minLevel, $requiresLocationId, $isActive],
        );

        $newId = (int) $this->db->lastInsertId();

        if ($newId > 0) {
            $this->syncChoices($newId, $data->choices ?? []);
        }
        AuditLogService::writeEvent('job_tasks.create', ['id' => $newId, 'job_id' => $jobId], 'admin');

        return $newId;
    }

    public function update(object $data): void
    {
        $id = (int) ($data->id ?? 0);
        $jobId = (int) ($data->job_id ?? 0);
        $title = trim((string) ($data->title ?? ''));
        $body = trim((string) ($data->body ?? ''));
        $minLevel = max(1, (int) ($data->min_level ?? 1));
        $requiresLocationId = isset($data->requires_location_id) && (int) $data->requires_location_id > 0
            ? (int) $data->requires_location_id : null;
        $isActive = isset($data->is_active) ? (int) (bool) $data->is_active : 1;

        if ($id <= 0 || $title === '') {
            return;
        }

        $this->execPrepared(
            'UPDATE job_tasks SET
                job_id = ?,
                title = ?,
                body = ?,
                min_level = ?,
                requires_location_id = ?,
                is_active = ?
             WHERE id = ?',
            [$jobId, $title, $body !== '' ? $body : null, $minLevel, $requiresLocationId, $isActive, $id],
        );

        $this->syncChoices($id, $data->choices ?? []);
        AuditLogService::writeEvent('job_tasks.update', ['id' => $id], 'admin');
    }

    public function delete(int $id): void
    {
        if ($id <= 0) {
            return;
        }
        $this->execPrepared('DELETE FROM job_task_choices WHERE task_id = ?', [$id]);
        $this->execPrepared('DELETE FROM job_tasks WHERE id = ?', [$id]);
        AuditLogService::writeEvent('job_tasks.delete', ['id' => $id], 'admin');
    }

    private function syncChoices(int $taskId, $choices): void
    {
        $this->execPrepared('DELETE FROM job_task_choices WHERE task_id = ?', [$taskId]);

        if (empty($choices) || !is_array($choices)) {
            return;
        }

        foreach ($choices as $choice) {
            $choice = (object) $choice;
            $code = trim((string) ($choice->choice_code ?? 'on'));
            $label = trim((string) ($choice->label ?? ''));
            $pay = (int) ($choice->pay ?? 0);
            $fame = (int) ($choice->fame ?? 0);
            $points = (int) ($choice->points ?? 0);

            if ($label === '') {
                continue;
            }
            if ($code === '') {
                $code = 'on';
            }

            $this->execPrepared(
                'INSERT INTO job_task_choices
                (task_id, choice_code, label, pay, fame, points, date_created)
                VALUES (?, ?, ?, ?, ?, ?, NOW())',
                [$taskId, $code, $label, $pay, $fame, $points],
            );
        }
    }
}
