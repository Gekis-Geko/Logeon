<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database\DbAdapterFactory;
use Core\Database\DbAdapterInterface;

class LogJobAdminService
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

    public function list(object $data): array
    {
        $query = $data->query ?? (object) [];
        $page = max(1, (int) ($data->page ?? 1));
        $resultsPage = max(1, (int) ($data->results_page ?? $data->results ?? 30));
        $orderBy = $data->orderBy ?? 'date_created|DESC';
        $offset = ($page - 1) * $resultsPage;

        [$col, $dir] = array_pad(explode('|', $orderBy, 2), 2, 'DESC');
        $allowed = ['id', 'character_id', 'job_id', 'assigned_date', 'pay', 'fame', 'points', 'date_created'];
        if (!in_array($col, $allowed, true)) {
            $col = 'date_created';
        }
        $dir = strtoupper($dir) === 'ASC' ? 'ASC' : 'DESC';

        $where = ['1=1'];
        $params = [];
        if (!empty($query->character_id)) {
            $where[] = 'jl.character_id = ?';
            $params[] = (int) $query->character_id;
        }
        if (!empty($query->job_id)) {
            $where[] = 'jl.job_id = ?';
            $params[] = (int) $query->job_id;
        }
        $whereSql = implode(' AND ', $where);

        $joins = 'LEFT JOIN characters ON jl.character_id = characters.id
                  LEFT JOIN jobs ON jl.job_id = jobs.id
                  LEFT JOIN job_tasks ON jl.task_id = job_tasks.id';

        $countRow = $this->firstPrepared(
            "SELECT COUNT(*) AS tot FROM job_logs jl {$joins} WHERE {$whereSql}",
            $params,
        );
        $total = $countRow ? (int) $countRow->tot : 0;

        $rows = $this->fetchPrepared(
            "SELECT jl.id, jl.character_id, characters.name AS character_name,
                    jl.job_id, jobs.name AS job_name,
                    jl.task_id, job_tasks.title AS task_name,
                    jl.pay, jl.fame, jl.points, jl.assigned_date, jl.date_created
             FROM job_logs jl {$joins}
             WHERE {$whereSql}
             ORDER BY jl.{$col} {$dir}
             LIMIT ? OFFSET ?",
            array_merge($params, [$resultsPage, $offset]),
        );

        return [
            'dataset' => $rows,
            'properties' => [
                'query' => $query,
                'page' => $page,
                'results_page' => $resultsPage,
                'orderBy' => $orderBy,
                'tot' => ['count' => $total],
            ],
        ];
    }
}
