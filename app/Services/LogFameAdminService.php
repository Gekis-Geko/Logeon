<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database\DbAdapterFactory;
use Core\Database\DbAdapterInterface;

class LogFameAdminService
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
        $allowed = ['id', 'character_id', 'delta', 'source', 'date_created'];
        if (!in_array($col, $allowed, true)) {
            $col = 'date_created';
        }
        $dir = strtoupper($dir) === 'ASC' ? 'ASC' : 'DESC';

        $where = ['1=1'];
        $params = [];
        if (!empty($query->character_id)) {
            $where[] = 'fl.character_id = ?';
            $params[] = (int) $query->character_id;
        }
        if (!empty($query->source)) {
            $where[] = 'fl.source LIKE ?';
            $params[] = '%' . (string) $query->source . '%';
        }
        $whereSql = implode(' AND ', $where);

        $joins = 'LEFT JOIN characters AS c ON fl.character_id = c.id
                  LEFT JOIN characters AS a ON fl.author_id = a.id';

        $countRow = $this->firstPrepared(
            "SELECT COUNT(*) AS tot FROM fame_logs fl {$joins} WHERE {$whereSql}",
            $params,
        );
        $total = $countRow ? (int) $countRow->tot : 0;

        $rows = $this->fetchPrepared(
            "SELECT fl.id, fl.character_id, c.name AS character_name,
                    fl.delta, fl.fame_before, fl.fame_after,
                    fl.reason, fl.source, fl.author_id, a.name AS author_name, fl.date_created
             FROM fame_logs fl {$joins}
             WHERE {$whereSql}
             ORDER BY fl.{$col} {$dir}
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
