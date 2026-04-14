<?php

declare(strict_types=1);

namespace Core\Models;

class ListQuery
{
    private $table;
    private $primaryKey;
    private $fillable;
    private $joins;
    private $allowRaw;
    private $sanitizeIdentifier;
    private $isSafeRawCondition;
    private $safeValue;
    private $decryptField;

    public function __construct(
        $table,
        $primaryKey,
        $fillable,
        $joins,
        bool $allowRaw,
        callable $sanitizeIdentifier,
        callable $isSafeRawCondition,
        callable $safeValue,
        callable $decryptField,
    ) {
        $this->table = $table;
        $this->primaryKey = $primaryKey;
        $this->fillable = $fillable;
        $this->joins = $joins;
        $this->allowRaw = $allowRaw;
        $this->sanitizeIdentifier = $sanitizeIdentifier;
        $this->isSafeRawCondition = $isSafeRawCondition;
        $this->safeValue = $safeValue;
        $this->decryptField = $decryptField;
    }

    public function buildFilters($queryPayload): array
    {
        $filters = [];
        if (empty($queryPayload)) {
            return $filters;
        }

        foreach ($queryPayload as $k => $v) {
            if (is_numeric($k)) {
                if ($this->allowRaw && call_user_func($this->isSafeRawCondition, $v)) {
                    $filters[] = $v;
                }
                continue;
            }

            $safeKey = call_user_func($this->sanitizeIdentifier, $k, '');
            if ($safeKey === '') {
                continue;
            }

            if ($safeKey == 'email') {
                $filters[] = call_user_func($this->decryptField, $safeKey) . ' = ' . call_user_func($this->safeValue, $v);
            } elseif ($v === null) {
                $filters[] = $safeKey . ' IS NULL';
            } else {
                $filters[] = $safeKey . ' = ' . call_user_func($this->safeValue, $v);
            }
        }

        return $filters;
    }

    public function normalizeOrder($orderBy): array
    {
        $defaultField = $this->primaryKey;
        if (empty($orderBy)) {
            return [
                'raw' => $defaultField . '|ASC',
                'sql' => ' ORDER BY ' . $defaultField . ' ASC',
            ];
        }

        if (!is_array($orderBy)) {
            $parts = explode('|', (string) $orderBy);
            $field = call_user_func($this->sanitizeIdentifier, $parts[0] ?? '', $this->primaryKey);
            $dir = strtoupper($parts[1] ?? 'ASC');
            if (!in_array($dir, ['ASC', 'DESC'], true)) {
                $dir = 'ASC';
            }

            return [
                'raw' => $field . '|' . $dir,
                'sql' => ' ORDER BY ' . $field . ' ' . $dir,
            ];
        }

        if (count($orderBy) === 0) {
            return [
                'raw' => $defaultField . '|ASC',
                'sql' => ' ORDER BY ' . $defaultField . ' ASC',
            ];
        }

        $order = ' ORDER BY ';
        $n = 1;
        $total = count($orderBy);
        foreach ($orderBy as $Order) {
            $explode = explode('|', (string) $Order);
            $field = call_user_func($this->sanitizeIdentifier, $explode[0] ?? '', $this->primaryKey);
            $dir = strtoupper($explode[1] ?? 'ASC');
            if (!in_array($dir, ['ASC', 'DESC'], true)) {
                $dir = 'ASC';
            }
            $order .= $field . ' ' . $dir;
            $order .= ($total != $n) ? ', ' : '';

            $n++;
        }

        return [
            'raw' => $orderBy,
            'sql' => $order,
        ];
    }

    public function normalizePagination($post): array
    {
        $page = (!empty($post->page)) ? (int) $post->page : 1;
        if ($page < 1) {
            $page = 1;
        }
        $results = (!empty($post->results)) ? (int) $post->results : 0;
        if ($results < 0) {
            $results = 0;
        }
        $limit = ($results > 0) ? ' LIMIT ' . (($page - 1) * $results) . ', ' . $results : '';

        return [
            'page' => $page,
            'results' => $results,
            'limit' => $limit,
        ];
    }

    public function shouldUseCache($post): bool
    {
        return (isset($post->cache) && $post->cache && class_exists('\\Core\\Cache'));
    }

    public function getCacheTtl($post): ?int
    {
        return isset($post->cache_ttl) ? (int) $post->cache_ttl : null;
    }

    public function buildCacheKey(string $where, string $order, string $limit): string
    {
        return $this->table . '|list|' . md5(json_encode([
            'fillable' => $this->fillable,
            'joins' => $this->joins,
            'where' => $where,
            'order' => $order,
            'limit' => $limit,
        ]));
    }

    public function buildResponse($dataset, $count, $post, int $page, int $results): array
    {
        return [
            'properties' => [
                'query' => (empty($post->query)) ? null : $post->query,
                'page' => $page,
                'results_page' => ($results > 0) ? $results : 10,
                'orderBy' => $post->orderBy,
                'tot' => $count,
            ],
            'dataset' => $dataset,
        ];
    }
}
