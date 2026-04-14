<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database\DbAdapterFactory;
use Core\Database\DbAdapterInterface;
use Core\HtmlSanitizer;
use Core\Http\AppError;

class ThreadService
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
     */
    private function execPrepared(string $sql, array $params = []): void
    {
        $this->db->executePrepared($sql, $params);
    }

    private function failValidation($message, string $errorCode = 'validation_error'): void
    {
        throw AppError::validation((string) $message, [], $errorCode);
    }

    private function failUnauthorized($message = 'Operazione non autorizzata', string $errorCode = 'unauthorized'): void
    {
        throw AppError::unauthorized((string) $message, [], $errorCode);
    }

    private function failNotFound($message = 'Risorsa non trovata', string $errorCode = 'not_found'): void
    {
        throw AppError::notFound((string) $message, [], $errorCode);
    }

    private function normalizeThreadId($value): int
    {
        $threadId = (int) $value;
        if ($threadId <= 0) {
            $this->failValidation('Thread non valido', 'thread_invalid');
        }

        return $threadId;
    }

    private function normalizeForumId($value): int
    {
        $forumId = (int) $value;
        if ($forumId <= 0) {
            $this->failValidation('Forum non valido', 'forum_invalid');
        }

        return $forumId;
    }

    private function normalizeRequiredTitle($value): string
    {
        $title = trim((string) $value);
        if ($title === '') {
            $this->failValidation('Titolo del thread mancante', 'thread_title_required');
        }

        return $title;
    }

    private function sanitizeBody($value): string
    {
        return HtmlSanitizer::sanitize((string) $value, ['allow_images' => true]);
    }

    public function getById(int $threadId)
    {
        if ($threadId <= 0) {
            return null;
        }

        return $this->firstPrepared(
            'SELECT id, character_id, forum_id, title, is_closed
             FROM forum_threads
             WHERE id = ?
             LIMIT 1',
            [$threadId],
        );
    }

    public function ensureManageAllowed(int $threadId, ?int $currentCharacterId, bool $isAdmin)
    {
        $thread = $this->getById($threadId);
        if (empty($thread)) {
            $this->failNotFound('Thread non trovato', 'thread_not_found');
        }

        if ($isAdmin) {
            return $thread;
        }

        if ($currentCharacterId === null || (int) $currentCharacterId <= 0) {
            $this->failUnauthorized('Operazione non autorizzata', 'thread_forbidden');
        }

        if ((int) $thread->character_id !== (int) $currentCharacterId) {
            $this->failUnauthorized('Operazione non autorizzata', 'thread_forbidden');
        }

        return $thread;
    }

    public function create($data, int $characterId): int
    {
        $forumId = $this->normalizeForumId($data->forum_id ?? null);
        $title = $this->normalizeRequiredTitle($data->title ?? '');
        $fatherId = isset($data->father_id) ? (int) $data->father_id : 0;
        if ($fatherId <= 0) {
            $fatherId = null;
        }
        $safeBody = $this->sanitizeBody($data->body ?? '');

        $this->execPrepared(
            'INSERT INTO forum_threads SET
                father_id = ?,
                forum_id = ?,
                character_id = ?,
                title = ?,
                body = ?',
            [$fatherId, $forumId, $characterId, $title, $safeBody],
        );

        return $this->db->lastInsertId();
    }

    public function update($data, int $characterId, bool $isAdmin): int
    {
        $threadId = $this->normalizeThreadId($data->id ?? null);
        $this->ensureManageAllowed($threadId, $characterId, $isAdmin);

        $title = $this->normalizeRequiredTitle($data->title ?? '');
        $safeBody = $this->sanitizeBody($data->body ?? '');

        $this->execPrepared(
            'UPDATE forum_threads SET
                title = ?,
                body = ?
            WHERE id = ?',
            [$title, $safeBody, $threadId],
        );

        return $threadId;
    }

    public function answer($data, int $characterId): int
    {
        $fatherId = isset($data->father_id) ? (int) $data->father_id : 0;
        if ($fatherId <= 0) {
            $this->failValidation('Riferimento al thread non valido', 'thread_parent_invalid');
        }

        $parent = $this->firstPrepared(
            'SELECT id, forum_id, title, is_closed
             FROM forum_threads
             WHERE id = ?
             LIMIT 1',
            [$fatherId],
        );

        if (empty($parent)) {
            $this->failNotFound('Thread di riferimento non trovato', 'thread_parent_not_found');
        }

        if ((int) $parent->is_closed === 1) {
            $this->failValidation('Il thread e chiuso', 'thread_closed');
        }

        $safeBody = $this->sanitizeBody($data->body ?? '');
        $title = trim((string) ($data->title ?? ''));
        if ($title === '') {
            $parentTitle = trim((string) ($parent->title ?? ''));
            $title = ($parentTitle !== '') ? ('Re: ' . $parentTitle) : 'Risposta';
        }

        $this->execPrepared(
            'INSERT INTO forum_threads SET
                father_id = ?,
                forum_id = ?,
                character_id = ?,
                title = ?,
                body = ?',
            [$fatherId, (int) $parent->forum_id, $characterId, $title, $safeBody],
        );

        return $this->db->lastInsertId();
    }

    public function setClosed(int $threadId, bool $closed): void
    {
        $threadId = $this->normalizeThreadId($threadId);
        $this->execPrepared(
            'UPDATE forum_threads SET
                is_closed = ?
            WHERE id = ?',
            [$closed ? 1 : 0, $threadId],
        );
    }

    public function setImportant(int $threadId, bool $important): void
    {
        $threadId = $this->normalizeThreadId($threadId);
        $this->execPrepared(
            'UPDATE forum_threads SET
                is_important = ?
            WHERE id = ?',
            [$important ? 1 : 0, $threadId],
        );
    }

    public function move(int $threadId, int $forumId): void
    {
        $threadId = $this->normalizeThreadId($threadId);
        if ($forumId <= 0) {
            $this->failValidation('Sezione di destinazione non valida', 'forum_invalid');
        }

        $this->execPrepared(
            'UPDATE forum_threads SET
                forum_id = ?
            WHERE id = ?',
            [$forumId, $threadId],
        );
    }

    public function delete(int $threadId, int $characterId, bool $isAdmin): void
    {
        $threadId = $this->normalizeThreadId($threadId);
        $this->ensureManageAllowed($threadId, $characterId, $isAdmin);

        $this->execPrepared('DELETE FROM forum_threads WHERE id = ?', [$threadId]);
    }
}
