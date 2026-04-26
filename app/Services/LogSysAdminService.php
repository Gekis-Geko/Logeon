<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database\DbAdapterFactory;
use Core\Database\DbAdapterInterface;

class LogSysAdminService
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

    private function cryptKey(): string
    {
        if (defined('DB')) {
            return (string) DB['crypt_key'];
        }

        return '';
    }

    private function quotedCryptKey(): string
    {
        return "'" . str_replace("'", "''", $this->cryptKey()) . "'";
    }

    public function list(object $data, bool $isSuperuser = false): array
    {
        $query = $data->query ?? (object) [];
        $page = max(1, (int) ($data->page ?? 1));
        $resultsPage = max(1, (int) ($data->results_page ?? $data->results ?? 30));
        $orderBy = $data->orderBy ?? 'date_created|DESC';
        $offset = ($page - 1) * $resultsPage;

        [$col, $dir] = array_pad(explode('|', $orderBy, 2), 2, 'DESC');
        $allowed = ['id', 'author', 'area', 'module', 'action', 'date_created'];
        if (!in_array($col, $allowed, true)) {
            $col = 'date_created';
        }
        $dir = strtoupper($dir) === 'ASC' ? 'ASC' : 'DESC';

        $where = ['1=1'];
        $params = [];
        if (!empty($query->area)) {
            $where[] = 'sl.area LIKE ?';
            $params[] = '%' . (string) $query->area . '%';
        }
        if (!empty($query->action)) {
            $where[] = 'sl.action LIKE ?';
            $params[] = '%' . (string) $query->action . '%';
        }
        if (!empty($query->author)) {
            $where[] = 'sl.author = ?';
            $params[] = (int) $query->author;
        }
        $whereSql = implode(' AND ', $where);

        $emailSelect = $isSuperuser
            ? 'CAST(AES_DECRYPT(u.email, ' . $this->quotedCryptKey() . ') AS CHAR(255)) AS author_email'
            : 'NULL AS author_email';

        $countRow = $this->firstPrepared(
            "SELECT COUNT(*) AS tot FROM sys_logs sl WHERE {$whereSql}",
            $params,
        );
        $total = $countRow ? (int) $countRow->tot : 0;

        $rows = $this->fetchPrepared(
            "SELECT sl.id, sl.author,
                    {$emailSelect},
                    (SELECT CONCAT_WS(' ', c.name, c.surname) FROM characters c WHERE c.user_id = sl.author ORDER BY c.id ASC LIMIT 1) AS author_name,
                    sl.url, sl.area, sl.module, sl.action, sl.date_created
             FROM sys_logs sl
             LEFT JOIN users u ON u.id = sl.author
             WHERE {$whereSql}
             ORDER BY sl.{$col} {$dir}
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
