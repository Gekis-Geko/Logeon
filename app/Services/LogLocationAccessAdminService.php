<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database\DbAdapterFactory;
use Core\Database\DbAdapterInterface;

class LogLocationAccessAdminService
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
        $allowed = ['id', 'character_id', 'location_id', 'allowed', 'date_created'];
        if (!in_array($col, $allowed, true)) {
            $col = 'date_created';
        }
        $dir = strtoupper($dir) === 'ASC' ? 'ASC' : 'DESC';

        $where = ['1=1'];
        $params = [];
        if (!empty($query->character_id)) {
            $where[] = 'la.character_id = ?';
            $params[] = (int) $query->character_id;
        }
        if (!empty($query->location_id)) {
            $where[] = 'la.location_id = ?';
            $params[] = (int) $query->location_id;
        }
        if (isset($query->allowed) && $query->allowed !== '') {
            $where[] = 'la.allowed = ?';
            $params[] = (int) $query->allowed;
        }
        $whereSql = implode(' AND ', $where);

        $joins = 'LEFT JOIN characters ON la.character_id = characters.id
                  LEFT JOIN locations  ON la.location_id  = locations.id';

        $countRow = $this->firstPrepared(
            "SELECT COUNT(*) AS tot FROM location_access_logs la {$joins} WHERE {$whereSql}",
            $params,
        );
        $total = $countRow ? (int) $countRow->tot : 0;

        $rows = $this->fetchPrepared(
            "SELECT la.id, la.character_id, characters.name AS character_name,
                    la.location_id, locations.name AS location_name,
                    la.allowed, la.reason_code, la.reason, la.date_created
             FROM location_access_logs la {$joins}
             WHERE {$whereSql}
             ORDER BY la.{$col} {$dir}
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
