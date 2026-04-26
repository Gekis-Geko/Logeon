<?php

declare(strict_types=1);

namespace App\Services;

use Core\AuditLogService;
use Core\Database\DbAdapterFactory;
use Core\Database\DbAdapterInterface;

class JobLevelAdminService
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
            'jl.id' => 'jl.id',
            'id' => 'jl.id',
            'job_name' => 'j.name',
            'jl.level' => 'jl.level',
            'level' => 'jl.level',
            'jl.title' => 'jl.title',
            'title' => 'jl.title',
            'min_points' => 'jl.min_points',
            'jl.min_points' => 'jl.min_points',
            'pay_bonus_percent' => 'jl.pay_bonus_percent',
        ];

        $parts = explode('|', $raw);
        $field = trim($parts[0]);
        $dir = strtoupper(trim($parts[1] ?? 'ASC')) === 'DESC' ? 'DESC' : 'ASC';
        $col = $allowed[$field] ?? 'jl.id';

        return $col . ' ' . $dir;
    }

    public function list(object $data): array
    {
        $query = (isset($data->query) && is_object($data->query)) ? $data->query : (object) [];
        $jobId = isset($query->job_id) ? (int) $query->job_id : 0;
        $title = isset($query->title) ? trim((string) $query->title) : '';
        $page = max(1, (int) ($data->page ?? 1));
        $resultsPage = max(5, min(100, (int) ($data->results ?? 20)));
        $orderByRaw = isset($data->orderBy) ? (string) $data->orderBy : 'j.name|ASC';
        $orderBy = $this->normalizeOrderBy($orderByRaw);

        $where = ['1=1'];
        $params = [];

        if ($jobId > 0) {
            $where[] = 'jl.job_id = ?';
            $params[] = $jobId;
        }
        if ($title !== '') {
            $where[] = 'jl.title LIKE ?';
            $params[] = '%' . $title . '%';
        }

        $whereClause = 'WHERE ' . implode(' AND ', $where);

        $countRow = $this->firstPrepared(
            'SELECT COUNT(*) AS total
             FROM job_levels jl
             LEFT JOIN jobs j ON jl.job_id = j.id
             ' . $whereClause,
            $params,
        );
        $total = !empty($countRow) ? (int) $countRow->total : 0;

        $offset = ($page - 1) * $resultsPage;

        $rows = $this->fetchPrepared(
            'SELECT jl.id, jl.job_id, jl.level, jl.title, jl.min_points,
                    jl.pay_bonus_percent, jl.date_created,
                    j.name AS job_name
             FROM job_levels jl
             LEFT JOIN jobs j ON jl.job_id = j.id
             ' . $whereClause . '
             ORDER BY ' . $orderBy . ', jl.level ASC
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
        $jobId = (int) ($data->job_id ?? 0);
        $level = max(1, (int) ($data->level ?? 1));
        $title = trim((string) ($data->title ?? ''));
        $minPoints = max(0, (int) ($data->min_points ?? 0));
        $payBonusPercent = max(0, (int) ($data->pay_bonus_percent ?? 0));

        $this->execPrepared(
            'INSERT INTO job_levels
                (job_id, level, title, min_points, pay_bonus_percent, date_created)
             VALUES (?, ?, ?, ?, ?, NOW())',
            [$jobId, $level, $title, $minPoints, $payBonusPercent],
        );
        AuditLogService::writeEvent('job_levels.create', ['job_id' => $jobId, 'level' => $level, 'title' => $title], 'admin');
    }

    public function update(object $data): void
    {
        $id = (int) ($data->id ?? 0);
        $jobId = (int) ($data->job_id ?? 0);
        $level = max(1, (int) ($data->level ?? 1));
        $title = trim((string) ($data->title ?? ''));
        $minPoints = max(0, (int) ($data->min_points ?? 0));
        $payBonusPercent = max(0, (int) ($data->pay_bonus_percent ?? 0));

        if ($id <= 0 || $title === '') {
            return;
        }

        $this->execPrepared(
            'UPDATE job_levels SET
                job_id = ?,
                level = ?,
                title = ?,
                min_points = ?,
                pay_bonus_percent = ?
             WHERE id = ?',
            [$jobId, $level, $title, $minPoints, $payBonusPercent, $id],
        );
        AuditLogService::writeEvent('job_levels.update', ['id' => $id], 'admin');
    }

    public function delete(int $id): void
    {
        if ($id <= 0) {
            return;
        }
        $this->execPrepared('DELETE FROM job_levels WHERE id = ?', [$id]);
        AuditLogService::writeEvent('job_levels.delete', ['id' => $id], 'admin');
    }
}
