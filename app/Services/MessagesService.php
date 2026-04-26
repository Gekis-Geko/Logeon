<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database\DbAdapterFactory;
use Core\Database\DbAdapterInterface;
use Core\Http\AppError;

class MessagesService
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

    private function failValidation($message, string $errorCode = 'validation_error'): void
    {
        throw AppError::validation((string) $message, [], $errorCode);
    }

    private function failMessageEmpty(): void
    {
        $this->failValidation('Messaggio vuoto', 'message_empty');
    }

    private function failMessageTooLong(): void
    {
        $this->failValidation('Messaggio troppo lungo', 'message_too_long');
    }

    private function failSubjectRequired(): void
    {
        $this->failValidation('Oggetto mancante', 'subject_required');
    }

    private function failSubjectTooLong(): void
    {
        $this->failValidation('Oggetto troppo lungo', 'subject_too_long');
    }

    private function failCharacterInvalid(): void
    {
        $this->failValidation('Personaggio non valido', 'character_invalid');
    }

    private function textLength(string $value): int
    {
        if (function_exists('mb_strlen')) {
            return (int) mb_strlen($value, 'UTF-8');
        }

        return (int) strlen($value);
    }

    public function beginTransaction(): void
    {
        $this->db->query('START TRANSACTION');
    }

    public function commitTransaction(): void
    {
        $this->db->query('COMMIT');
    }

    public function rollbackTransaction(): void
    {
        try {
            $this->db->query('ROLLBACK');
        } catch (\Throwable $e) {
            // no-op: rollback best effort
        }
    }

    public function normalizeBody($value, int $maxLength = 2000): string
    {
        $body = trim((string) $value);
        if ($body === '') {
            $this->failMessageEmpty();
        }
        if ($this->textLength($body) > $maxLength) {
            $this->failMessageTooLong();
        }

        return $body;
    }

    public function normalizeSubject($value, int $maxLength = 120): string
    {
        $subject = trim((string) $value);
        if ($subject === '') {
            $this->failSubjectRequired();
        }
        if ($this->textLength($subject) > $maxLength) {
            $this->failSubjectTooLong();
        }

        return $subject;
    }

    public function getDmPolicyForCharacter(int $characterId): int
    {
        $row = $this->firstPrepared(
            'SELECT dm_policy FROM characters WHERE id = ? LIMIT 1',
            [$characterId],
        );

        if (empty($row)) {
            $this->failCharacterInvalid();
        }

        return (int) $row->dm_policy;
    }

    public function listThreads(int $characterId, string $search = ''): array
    {
        $where = ' WHERE (t.character_one = ? OR t.character_two = ?)'
               . ' AND NOT (t.character_one = ? AND t.deleted_for_one = 1)'
               . ' AND NOT (t.character_two = ? AND t.deleted_for_two = 1)';
        $params = [$characterId, $characterId, $characterId, $characterId];

        $search = trim($search);
        if ($search !== '') {
            $like = '%' . $search . '%';
            $where .= ' AND (
                t.subject LIKE ?
                OR t.last_message_body LIKE ?
                OR c1.name LIKE ?
                OR c1.surname LIKE ?
                OR c2.name LIKE ?
                OR c2.surname LIKE ?
                OR CONCAT(c1.name, " ", IFNULL(c1.surname, "")) LIKE ?
                OR CONCAT(c2.name, " ", IFNULL(c2.surname, "")) LIKE ?
            )';
            $params = array_merge($params, [$like, $like, $like, $like, $like, $like, $like, $like]);
        }

        $dataset = $this->fetchPrepared(
            'SELECT
                t.id,
                t.subject,
                t.last_message_type,
                t.last_message_body,
                t.last_sender_id,
                t.date_last_message,
                (SELECT COUNT(*) FROM messages m WHERE m.thread_id = t.id AND m.recipient_id = ? AND m.is_read = 0) AS unread_count,
                CASE WHEN t.character_one = ? THEN t.character_two ELSE t.character_one END AS other_id,
                CASE WHEN t.character_one = ? THEN c2.name ELSE c1.name END AS other_name,
                CASE WHEN t.character_one = ? THEN c2.surname ELSE c1.surname END AS other_surname,
                CASE WHEN t.character_one = ? THEN c2.avatar ELSE c1.avatar END AS other_avatar
            FROM messages_threads t
            LEFT JOIN characters c1 ON t.character_one = c1.id
            LEFT JOIN characters c2 ON t.character_two = c2.id
            ' . $where . '
            ORDER BY t.date_last_message DESC, t.id DESC',
            array_merge([$characterId, $characterId, $characterId, $characterId, $characterId], $params),
        );

        return $dataset ?: [];
    }

    public function countUnread(int $characterId): int
    {
        $row = $this->firstPrepared(
            'SELECT COUNT(*) AS unread
            FROM messages
            WHERE recipient_id = ? AND is_read = 0',
            [$characterId],
        );

        return (int) ($row->unread ?? 0);
    }

    public function loadThreadForCharacter(int $threadId, int $characterId)
    {
        return $this->firstPrepared(
            'SELECT * FROM messages_threads
            WHERE id = ?
              AND (character_one = ? OR character_two = ?)',
            [$threadId, $characterId, $characterId],
        );
    }

    public function loadThreadById(int $threadId)
    {
        return $this->firstPrepared(
            'SELECT * FROM messages_threads WHERE id = ?',
            [$threadId],
        );
    }

    public function isThreadParticipant($thread, int $characterId): bool
    {
        if (empty($thread)) {
            return false;
        }

        return ((int) $thread->character_one === $characterId || (int) $thread->character_two === $characterId);
    }

    public function resolveOtherParticipantId($thread, int $characterId): int
    {
        if (!$this->isThreadParticipant($thread, $characterId)) {
            return 0;
        }

        if ((int) $thread->character_one === $characterId) {
            return (int) $thread->character_two;
        }

        return (int) $thread->character_one;
    }

    public function listThreadMessages(int $threadId, int $limit, ?int $beforeId = null): array
    {
        $where = 'm.thread_id = ?';
        $params = [$threadId];
        if (!empty($beforeId)) {
            $where .= ' AND m.id < ?';
            $params[] = $beforeId;
        }
        $params[] = $limit;

        $messages = $this->fetchPrepared(
            'SELECT m.id, m.thread_id, m.sender_id, m.recipient_id, m.body, m.message_type, m.is_read, m.date_created,
                c.name, c.surname, c.avatar
            FROM messages m
            LEFT JOIN characters c ON m.sender_id = c.id
            WHERE ' . $where . '
            ORDER BY m.id DESC
            LIMIT ?',
            $params,
        );

        return $messages ?: [];
    }

    public function markThreadRead(int $threadId, int $recipientId): void
    {
        $this->execPrepared(
            'UPDATE messages SET is_read = 1
            WHERE thread_id = ?
            AND recipient_id = ?',
            [$threadId, $recipientId],
        );
    }

    public function getCharacterSummary(int $characterId)
    {
        return $this->firstPrepared(
            'SELECT id, name, surname, avatar FROM characters WHERE id = ?',
            [$characterId],
        );
    }

    public function getCharacterNotificationTarget(int $characterId)
    {
        return $this->firstPrepared(
            'SELECT id, user_id, name, surname
             FROM characters
             WHERE id = ?',
            [$characterId],
        );
    }

    public function createThread(int $characterOne, int $characterTwo, string $subject): int
    {
        $this->execPrepared(
            'INSERT INTO messages_threads SET
                character_one = ?,
                character_two = ?,
                subject = ?,
                date_created = NOW()',
            [$characterOne, $characterTwo, $subject],
        );

        return $this->db->lastInsertId();
    }

    public function insertMessage(int $threadId, int $senderId, int $recipientId, string $body, string $messageType): int
    {
        $this->execPrepared(
            'INSERT INTO messages SET
                thread_id = ?,
                sender_id = ?,
                recipient_id = ?,
                body = ?,
                message_type = ?',
            [$threadId, $senderId, $recipientId, $body, $messageType],
        );

        $messageId = $this->db->lastInsertId();

        // If the recipient previously deleted the thread for themselves, restore it
        $this->resetDeletedFlagForRecipient($threadId, $recipientId);

        return $messageId;
    }

    public function deleteThreadForCharacter(int $threadId, int $characterId): void
    {
        $thread = $this->loadThreadById($threadId);
        if (empty($thread)) {
            return;
        }

        if ((int) $thread->character_one === $characterId) {
            $this->execPrepared(
                'UPDATE messages_threads SET deleted_for_one = 1 WHERE id = ?',
                [$threadId],
            );
        } elseif ((int) $thread->character_two === $characterId) {
            $this->execPrepared(
                'UPDATE messages_threads SET deleted_for_two = 1 WHERE id = ?',
                [$threadId],
            );
        }
    }

    public function resetDeletedFlagForRecipient(int $threadId, int $recipientId): void
    {
        $thread = $this->loadThreadById($threadId);
        if (empty($thread)) {
            return;
        }

        if ((int) $thread->character_one === $recipientId) {
            $this->execPrepared(
                'UPDATE messages_threads SET deleted_for_one = 0 WHERE id = ?',
                [$threadId],
            );
        } elseif ((int) $thread->character_two === $recipientId) {
            $this->execPrepared(
                'UPDATE messages_threads SET deleted_for_two = 0 WHERE id = ?',
                [$threadId],
            );
        }
    }

    public function updateThreadLastMessage(int $threadId, string $body, int $senderId, string $messageType): void
    {
        $this->execPrepared(
            'UPDATE messages_threads SET
                last_message_body = ?,
                last_sender_id = ?,
                last_message_type = ?,
                date_last_message = NOW()
            WHERE id = ?',
            [$body, $senderId, $messageType, $threadId],
        );
    }

    public function fetchMessageById(int $messageId)
    {
        return $this->firstPrepared(
            'SELECT m.id, m.thread_id, m.sender_id, m.recipient_id, m.body, m.message_type, m.is_read, m.date_created,
                c.name, c.surname, c.avatar
            FROM messages m
            LEFT JOIN characters c ON m.sender_id = c.id
            WHERE m.id = ?',
            [$messageId],
        );
    }
}
