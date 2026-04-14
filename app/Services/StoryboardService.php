<?php

declare(strict_types=1);

namespace App\Services;

use Core\AuditLogService;
use Core\Database\DbAdapterFactory;
use Core\Database\DbAdapterInterface;
use Core\Http\AppError;

class StoryboardService
{
    /** @var DbAdapterInterface */
    private $db;

    public function __construct(DbAdapterInterface $db = null)
    {
        $this->db = $db ?: DbAdapterFactory::createFromConfig();
    }

    /**
     * @param array<int,mixed> $params
     * @return mixed
     */
    private function firstPrepared(string $sql, array $params = [])
    {
        return $this->db->fetchOnePrepared($sql, $params);
    }

    /**
     * @param array<int,mixed> $params
     * @return array<int,mixed>
     */
    private function fetchPrepared(string $sql, array $params = []): array
    {
        return $this->db->fetchAllPrepared($sql, $params);
    }

    /**
     * @param array<int,mixed> $params
     */
    private function execPrepared(string $sql, array $params = []): void
    {
        $this->db->executePrepared($sql, $params);
    }

    private function ensureUnique(int $chapter, int $subchapter, ?int $id = null): void
    {
        if ($id !== null) {
            $exists = $this->firstPrepared(
                'SELECT id FROM storyboards WHERE chapter = ? AND subchapter = ? AND id <> ? LIMIT 1',
                [$chapter, $subchapter, $id],
            );
        } else {
            $exists = $this->firstPrepared(
                'SELECT id FROM storyboards WHERE chapter = ? AND subchapter = ? LIMIT 1',
                [$chapter, $subchapter],
            );
        }

        if (!empty($exists)) {
            throw AppError::validation('Capitolo e sottocapitolo gia presenti.', [], 'storyboard_duplicate');
        }
    }

    public function create(int $chapter, int $subchapter, string $title, string $body): void
    {
        $this->ensureUnique($chapter, $subchapter, null);
        $this->execPrepared(
            'INSERT INTO storyboards SET
                chapter = ?,
                subchapter = ?,
                title = ?,
                body = ?',
            [$chapter, $subchapter, $title, $body],
        );
        AuditLogService::writeEvent('storyboards.create', ['chapter' => $chapter, 'subchapter' => $subchapter, 'title' => $title], 'admin');
    }

    public function update(int $id, int $chapter, int $subchapter, string $title, string $body): void
    {
        $this->ensureUnique($chapter, $subchapter, $id);
        $this->execPrepared(
            'UPDATE storyboards SET
                chapter = ?,
                subchapter = ?,
                title = ?,
                body = ?
            WHERE id = ?',
            [$chapter, $subchapter, $title, $body, $id],
        );
        AuditLogService::writeEvent('storyboards.update', ['id' => $id], 'admin');
    }

    public function listPublicChapters(): array
    {
        $rows = $this->fetchPrepared(
            'SELECT id, chapter, subchapter, title, body
             FROM storyboards
             ORDER BY chapter ASC, subchapter ASC, id ASC',
        );

        $chapters = [];
        if (!empty($rows)) {
            foreach ($rows as $row) {
                $chapter = (int) $row->chapter;
                if (!isset($chapters[$chapter])) {
                    $chapters[$chapter] = [
                        'chapter' => $chapter,
                        'title' => null,
                        'body' => null,
                        'subchapters' => [],
                    ];
                }

                $sub = (int) $row->subchapter;
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

        foreach ($chapters as $key => $chapter) {
            $label = 'Capitolo ' . $chapter['chapter'];
            if (!empty($chapter['title'])) {
                $label .= ' - ' . $chapter['title'];
            }
            $chapters[$key]['label'] = $label;
        }

        return array_values($chapters);
    }
}
