<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database\DbAdapterFactory;
use Core\Database\DbAdapterInterface;

class LogCurrencyAdminService
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
        $allowed = ['id', 'character_id', 'currency_id', 'account', 'amount', 'source', 'date_created'];
        if (!in_array($col, $allowed, true)) {
            $col = 'date_created';
        }
        $dir = strtoupper($dir) === 'ASC' ? 'ASC' : 'DESC';

        $where = ['1=1'];
        $params = [];
        if (!empty($query->character_id)) {
            $where[] = 'cl.character_id = ?';
            $params[] = (int) $query->character_id;
        }
        if (!empty($query->currency_id)) {
            $where[] = 'cl.currency_id = ?';
            $params[] = (int) $query->currency_id;
        }
        if (!empty($query->source)) {
            $where[] = 'cl.source LIKE ?';
            $params[] = '%' . (string) $query->source . '%';
        }
        $whereSql = implode(' AND ', $where);

        $joins = 'LEFT JOIN characters ON cl.character_id = characters.id
                  LEFT JOIN currencies ON cl.currency_id = currencies.id';

        $countRow = $this->firstPrepared(
            "SELECT COUNT(*) AS tot FROM currency_logs cl {$joins} WHERE {$whereSql}",
            $params,
        );
        $total = $countRow ? (int) $countRow->tot : 0;

        $rows = $this->fetchPrepared(
            "SELECT cl.id, cl.character_id, characters.name AS character_name,
                    cl.currency_id, currencies.name AS currency_name,
                    cl.account, cl.amount, cl.balance_before, cl.balance_after, cl.source, cl.date_created
             FROM currency_logs cl {$joins}
             WHERE {$whereSql}
             ORDER BY cl.{$col} {$dir}
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
