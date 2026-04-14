<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database\DbAdapterFactory;
use Core\Database\DbAdapterInterface;

class LogConflictsAdminService
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
        $orderBy = $data->orderBy ?? 'timestamp|DESC';
        $offset = ($page - 1) * $resultsPage;

        [$col, $dir] = array_pad(explode('|', $orderBy, 2), 2, 'DESC');
        $allowed = ['id', 'conflict_id', 'roll_type', 'die_used', 'base_roll', 'final_result', 'critical_flag', 'timestamp'];
        if (!in_array($col, $allowed, true)) {
            $col = 'timestamp';
        }
        $dir = strtoupper($dir) === 'ASC' ? 'ASC' : 'DESC';

        $where = ['1=1'];
        $params = [];
        if (!empty($query->conflict_id)) {
            $where[] = 'crl.conflict_id = ?';
            $params[] = (int) $query->conflict_id;
        }
        if (!empty($query->roll_type)) {
            $where[] = 'crl.roll_type = ?';
            $params[] = (string) $query->roll_type;
        }
        if (!empty($query->die_used)) {
            $where[] = 'crl.die_used = ?';
            $params[] = (string) $query->die_used;
        }
        if (!empty($query->critical_flag)) {
            $where[] = 'crl.critical_flag = ?';
            $params[] = (string) $query->critical_flag;
        }
        $whereSql = implode(' AND ', $where);

        $joins = 'LEFT JOIN characters ON crl.actor_id = characters.id';

        $countRow = $this->firstPrepared(
            "SELECT COUNT(*) AS tot FROM conflict_roll_log crl {$joins} WHERE {$whereSql}",
            $params,
        );
        $total = $countRow ? (int) $countRow->tot : 0;

        $rows = $this->fetchPrepared(
            "SELECT crl.id, crl.conflict_id, crl.actor_id, characters.name AS actor_name,
                    crl.roll_type, crl.die_used, crl.base_roll, crl.modifiers,
                    crl.final_result, crl.critical_flag, crl.margin, crl.timestamp
             FROM conflict_roll_log crl {$joins}
             WHERE {$whereSql}
             ORDER BY crl.{$col} {$dir}
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
