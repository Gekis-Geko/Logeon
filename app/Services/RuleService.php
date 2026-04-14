<?php

declare(strict_types=1);

namespace App\Services;

use Core\AuditLogService;
use Core\Database\DbAdapterFactory;
use Core\Database\DbAdapterInterface;
use Core\Http\AppError;

class RuleService
{
    /** @var DbAdapterInterface */
    private $db;

    public function __construct(DbAdapterInterface $db = null)
    {
        $this->db = $db ?: DbAdapterFactory::createFromConfig();
    }

    private function fetchPrepared(string $sql, array $params = []): array
    {
        return $this->db->fetchAllPrepared($sql, $params);
    }

    private function firstPrepared(string $sql, array $params = [])
    {
        return $this->db->fetchOnePrepared($sql, $params);
    }

    private function execPrepared(string $sql, array $params = []): void
    {
        $this->db->executePrepared($sql, $params);
    }

    private function ensureUnique(int $article, int $subarticle, ?int $id = null): void
    {
        $sql = 'SELECT id
                FROM rules
                WHERE article = ?
                  AND subarticle = ?';
        $params = [$article, $subarticle];

        if ($id !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $id;
        }

        $sql .= ' LIMIT 1';
        $exists = $this->firstPrepared($sql, $params);

        if (!empty($exists)) {
            throw AppError::validation('Regola e sottoregola gia presenti.', [], 'rule_duplicate');
        }
    }

    public function create(int $article, int $subarticle, string $title, string $body): void
    {
        $this->ensureUnique($article, $subarticle, null);
        $this->execPrepared(
            'INSERT INTO rules SET
                article = ?,
                subarticle = ?,
                title = ?,
                body = ?',
            [$article, $subarticle, $title, $body],
        );
        AuditLogService::writeEvent('rules.create', ['article' => $article, 'subarticle' => $subarticle, 'title' => $title], 'admin');
    }

    public function update(int $id, int $article, int $subarticle, string $title, string $body): void
    {
        $this->ensureUnique($article, $subarticle, $id);
        $this->execPrepared(
            'UPDATE rules SET
                article = ?,
                subarticle = ?,
                title = ?,
                body = ?
            WHERE id = ?',
            [$article, $subarticle, $title, $body, $id],
        );
        AuditLogService::writeEvent('rules.update', ['id' => $id], 'admin');
    }

    public function listPublicChapters(): array
    {
        $rows = $this->fetchPrepared(
            'SELECT id, article, subarticle, title, body
             FROM rules
             ORDER BY article ASC, subarticle ASC, id ASC',
        );

        $chapters = [];
        if (!empty($rows)) {
            foreach ($rows as $row) {
                $chapter = (int) $row->article;
                if (!isset($chapters[$chapter])) {
                    $chapters[$chapter] = [
                        'chapter' => $chapter,
                        'title' => null,
                        'body' => null,
                        'subchapters' => [],
                    ];
                }

                $sub = (int) $row->subarticle;
                if ($sub === 0) {
                    $chapters[$chapter]['title'] = $row->title;
                    $chapters[$chapter]['body'] = $row->body;
                } else {
                    $chapters[$chapter]['subchapters'][] = [
                        'subchapter' => $sub,
                        'title' => $row->title,
                        'body' => $row->body,
                    ];
                }
            }
        }

        return array_values($chapters);
    }
}
