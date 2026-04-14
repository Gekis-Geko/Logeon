<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database\DbAdapterFactory;
use Core\Database\DbAdapterInterface;

class LogGuildAdminService
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
        $allowed = ['id', 'guild_id', 'action', 'actor_id', 'date_created'];
        if (!in_array($col, $allowed, true)) {
            $col = 'date_created';
        }
        $dir = strtoupper($dir) === 'ASC' ? 'ASC' : 'DESC';

        $where = ['1=1'];
        $params = [];
        if (!empty($query->guild_id)) {
            $where[] = 'gl.guild_id = ?';
            $params[] = (int) $query->guild_id;
        }
        if (!empty($query->action)) {
            $where[] = 'gl.action LIKE ?';
            $params[] = '%' . (string) $query->action . '%';
        }
        $whereSql = implode(' AND ', $where);

        $joins = 'LEFT JOIN guilds ON gl.guild_id = guilds.id
                  LEFT JOIN characters AS actor  ON gl.actor_id  = actor.id
                  LEFT JOIN characters AS target ON gl.target_id = target.id';

        $countRow = $this->firstPrepared(
            "SELECT COUNT(*) AS tot FROM guild_logs gl {$joins} WHERE {$whereSql}",
            $params,
        );
        $total = $countRow ? (int) $countRow->tot : 0;

        $rows = $this->fetchPrepared(
            "SELECT gl.id, gl.guild_id, guilds.name AS guild_name,
                    gl.action, gl.actor_id, actor.name AS actor_name,
                    gl.target_id, target.name AS target_name, gl.date_created
             FROM guild_logs gl {$joins}
             WHERE {$whereSql}
             ORDER BY gl.{$col} {$dir}
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
