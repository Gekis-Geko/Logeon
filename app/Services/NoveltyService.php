<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database\DbAdapterFactory;
use Core\Database\DbAdapterInterface;
use Core\HtmlSanitizer;

class NoveltyService
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

    private function execPrepared(string $sql, array $params = []): void
    {
        $this->db->executePrepared($sql, $params);
    }

    private function buildWhere(?int $type, bool $onlyPublished): array
    {
        $params = [];
        $where = [];
        if ($type !== null) {
            $where[] = 'news.type = ?';
            $params[] = $type;
        }
        if ($onlyPublished) {
            $where[] = 'news.is_published = 1';
        }

        return [
            'sql' => !empty($where) ? ' WHERE ' . implode(' AND ', $where) : '',
            'params' => $params,
        ];
    }

    private function normalizeAdminOrderBy(string $raw): string
    {
        $allowed = [
            'id' => 'news.id',
            'title' => 'news.title',
            'type' => 'news.type',
            'is_published' => 'news.is_published',
            'is_pinned' => 'news.is_pinned',
            'date_created' => 'news.date_created',
            'date_published' => 'news.date_published',
        ];

        $parts = explode('|', $raw);
        $field = trim($parts[0] ?? '');
        $dir = strtoupper(trim($parts[1] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';
        $col = $allowed[$field] ?? 'news.id';

        return $col . ' ' . $dir;
    }

    public function adminList(object $data): array
    {
        $query = (isset($data->query) && is_object($data->query)) ? $data->query : (object) [];
        $title = isset($query->title) ? trim((string) $query->title) : '';
        $type = isset($query->type) ? trim((string) $query->type) : '';
        $isPublished = (isset($query->is_published) && $query->is_published !== '') ? (int) $query->is_published : null;
        $page = max(1, (int) ($data->page ?? 1));
        $resultsPage = max(5, min(100, (int) ($data->results ?? 20)));
        $orderByRaw = isset($data->orderBy) ? (string) $data->orderBy : 'news.id|DESC';
        $orderBy = $this->normalizeAdminOrderBy($orderByRaw);

        $where = ['1=1'];
        $params = [];

        if ($title !== '') {
            $where[] = 'news.title LIKE ?';
            $params[] = '%' . $title . '%';
        }
        if ($type !== '') {
            $where[] = 'news.type = ?';
            $params[] = $type;
        }
        if ($isPublished !== null) {
            $where[] = 'news.is_published = ?';
            $params[] = $isPublished;
        }

        $whereClause = 'WHERE ' . implode(' AND ', $where);

        $countRow = $this->firstPrepared(
            'SELECT COUNT(*) AS total FROM news ' . $whereClause,
            $params,
        );
        $total = !empty($countRow) ? (int) $countRow->total : 0;

        $offset = ($page - 1) * $resultsPage;

        $rows = $this->fetchPrepared(
            'SELECT id, title, body, excerpt, image, type, is_published, is_pinned,
                    author_id, date_created, date_published, date_updated
             FROM news
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

    public function adminCreate(object $data): void
    {
        $title = trim((string) ($data->title ?? ''));
        $body = trim((string) ($data->body ?? ''));
        $excerpt = trim((string) ($data->excerpt ?? ''));
        $image = trim((string) ($data->image ?? ''));
        $type = trim((string) ($data->type ?? ''));
        $isPublished = isset($data->is_published) ? (int) (bool) $data->is_published : 0;
        $isPinned = isset($data->is_pinned) ? (int) (bool) $data->is_pinned : 0;
        $authorId = (isset($data->author_id) && (int) $data->author_id > 0) ? (int) $data->author_id : null;

        $datePublished = null;
        if ($isPublished) {
            if (isset($data->date_published) && trim((string) $data->date_published) !== '') {
                $datePublished = trim((string) $data->date_published);
            } else {
                $datePublished = date('Y-m-d H:i:s');
            }
        }

        $this->execPrepared(
            'INSERT INTO news
            (title, body, excerpt, image, type, is_published, is_pinned, author_id, date_created, date_updated, date_published)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), ?)',
            [
                $title,
                $body,
                $excerpt,
                $image,
                $type,
                $isPublished,
                $isPinned,
                $authorId !== null ? $authorId : null,
                $datePublished !== null ? $datePublished : null,
            ],
        );
    }

    public function adminUpdate(object $data): void
    {
        $id = (int) ($data->id ?? 0);
        $title = trim((string) ($data->title ?? ''));
        $body = trim((string) ($data->body ?? ''));
        $excerpt = trim((string) ($data->excerpt ?? ''));
        $image = trim((string) ($data->image ?? ''));
        $type = trim((string) ($data->type ?? ''));
        $isPublished = isset($data->is_published) ? (int) (bool) $data->is_published : 0;
        $isPinned = isset($data->is_pinned) ? (int) (bool) $data->is_pinned : 0;

        if ($id <= 0 || $title === '') {
            return;
        }

        $datePublished = null;
        if ($isPublished) {
            if (isset($data->date_published) && trim((string) $data->date_published) !== '') {
                $datePublished = trim((string) $data->date_published);
            } else {
                $existing = $this->firstPrepared(
                    'SELECT date_published FROM news WHERE id = ?',
                    [$id],
                );
                if (!empty($existing) && !empty($existing->date_published)) {
                    $datePublished = $existing->date_published;
                } else {
                    $datePublished = date('Y-m-d H:i:s');
                }
            }
        }

        $this->execPrepared(
            'UPDATE news SET
                title = ?,
                body = ?,
                excerpt = ?,
                image = ?,
                type = ?,
                is_published = ?,
                is_pinned = ?,
                date_updated = NOW(),
                date_published = ?
             WHERE id = ?',
            [
                $title,
                $body,
                $excerpt,
                $image,
                $type,
                $isPublished,
                $isPinned,
                $datePublished !== null ? $datePublished : null,
                $id,
            ],
        );
    }

    public function adminDelete(int $id): void
    {
        if ($id <= 0) {
            return;
        }
        $this->execPrepared('DELETE FROM news WHERE id = ?', [$id]);
    }

    public function listForHomepageFeed(int $limit = 6): array
    {
        $limit = max(1, min(20, (int) $limit));

        try {
            $rows = $this->fetchPrepared(
                'SELECT news.id,
                        news.title,
                        news.excerpt,
                        news.body,
                        news.type,
                        news.image,
                        COALESCE(news.date_published, news.date_created) AS date_publish
                 FROM news
                 WHERE news.is_published = 1
                 ORDER BY news.is_pinned DESC, COALESCE(news.date_published, news.date_created) DESC, news.id DESC
                 LIMIT ?',
                [$limit],
            );
        } catch (\Throwable $e) {
            return [];
        }

        if (!is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $rowData = is_object($row) ? (array) $row : (is_array($row) ? $row : []);
            $out[] = [
                'id' => (int) ($rowData['id'] ?? 0),
                'title' => (string) ($rowData['title'] ?? ''),
                'excerpt' => HtmlSanitizer::sanitize((string) ($rowData['excerpt'] ?? ''), ['allow_images' => false]),
                'body' => HtmlSanitizer::sanitize((string) ($rowData['body'] ?? ''), ['allow_images' => true]),
                'type' => (string) ($rowData['type'] ?? ''),
                'image' => (string) ($rowData['image'] ?? ''),
                'date_publish' => (string) ($rowData['date_publish'] ?? ''),
            ];
        }

        return $out;
    }

    public function listPaginated(array $fillable, ?int $type, bool $onlyPublished, int $offset, int $limit): array
    {
        $whereData = $this->buildWhere($type, $onlyPublished);
        $whereSql = $whereData['sql'];
        $whereParams = $whereData['params'];
        $orderSql = ' ORDER BY news.is_pinned DESC, COALESCE(news.date_published, news.date_created) DESC, news.id DESC';
        $limitSql = ' LIMIT ? OFFSET ?';

        $dataset = $this->fetchPrepared(
            'SELECT ' . implode(', ', $fillable) . ',
                COALESCE(news.date_published, news.date_created) AS date_publish
            FROM news' . $whereSql . $orderSql . $limitSql,
            array_merge($whereParams, [(int) $limit, (int) $offset]),
        );

        $count = $this->firstPrepared(
            'SELECT COUNT(*) AS count FROM news' . $whereSql,
            $whereParams,
        );

        return [
            'dataset' => !empty($dataset) ? $dataset : [],
            'count' => $count,
        ];
    }
}
