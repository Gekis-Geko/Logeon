<?php

declare(strict_types=1);

namespace App\Services;

use Core\AuditLogService;
use Core\Database\DbAdapterFactory;
use Core\Database\DbAdapterInterface;
use Core\Http\AppError;

class HowToPlayService
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

    private function ensureUnique(int $step, int $substep, ?int $id = null): void
    {
        $where = 'step = ? AND substep = ?';
        $params = [$step, $substep];
        if ($id !== null) {
            $where .= ' AND id <> ?';
            $params[] = $id;
        }

        $exists = $this->firstPrepared(
            'SELECT id FROM how_to_play WHERE ' . $where . ' LIMIT 1',
            $params,
        );

        if (!empty($exists)) {
            throw AppError::validation('Passo e sottopasso gia presenti.', [], 'how_to_play_duplicate');
        }
    }

    public function create(int $step, int $substep, string $title, string $body): void
    {
        $this->ensureUnique($step, $substep, null);
        $this->execPrepared(
            'INSERT INTO how_to_play (step, substep, title, body) VALUES (?, ?, ?, ?)',
            [$step, $substep, $title, $body],
        );
        AuditLogService::writeEvent('how_to_plays.create', ['step' => $step, 'substep' => $substep, 'title' => $title], 'admin');
    }

    public function update(int $id, int $step, int $substep, string $title, string $body): void
    {
        $this->ensureUnique($step, $substep, $id);
        $this->execPrepared(
            'UPDATE how_to_play
             SET step = ?, substep = ?, title = ?, body = ?
             WHERE id = ?',
            [$step, $substep, $title, $body, $id],
        );
        AuditLogService::writeEvent('how_to_plays.update', ['id' => $id], 'admin');
    }

    public function listPublicChapters(): array
    {
        $rows = $this->fetchPrepared(
            'SELECT id, step, substep, title, body
             FROM how_to_play
             ORDER BY step ASC, substep ASC, id ASC',
        );

        $chapters = [];
        if (!empty($rows)) {
            foreach ($rows as $row) {
                $chapter = (int) $row->step;
                if (!isset($chapters[$chapter])) {
                    $chapters[$chapter] = [
                        'chapter' => $chapter,
                        'title' => null,
                        'body' => null,
                        'subchapters' => [],
                    ];
                }

                $sub = (int) $row->substep;
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
            $label = 'Passo ' . $chapter['chapter'];
            if (!empty($chapter['title'])) {
                $label .= ' - ' . $chapter['title'];
            }
            $chapters[$key]['label'] = $label;
        }

        return array_values($chapters);
    }
}
