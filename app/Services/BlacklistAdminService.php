<?php

declare(strict_types=1);

namespace App\Services;

use Core\AuditLogService;
use Core\Database\DbAdapterFactory;
use Core\Database\DbAdapterInterface;
use Core\Http\AppError;

class BlacklistAdminService
{
    /** @var DbAdapterInterface */
    private $db;
    /** @var bool|null */
    private $hasDateCreatedColumn = null;

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

    private function cryptKey(): string
    {
        return (string) DB['crypt_key'];
    }

    private function quotedCryptKey(): string
    {
        return "'" . str_replace("'", "''", $this->cryptKey()) . "'";
    }

    private function failValidation(string $message, string $errorCode = 'validation_error'): void
    {
        throw AppError::validation($message, [], $errorCode);
    }

    private function hasDateCreatedColumn(): bool
    {
        if ($this->hasDateCreatedColumn !== null) {
            return $this->hasDateCreatedColumn;
        }

        $row = $this->firstPrepared(
            'SELECT column_name
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = ?
               AND column_name = ?
             LIMIT 1',
            ['blacklist', 'date_created'],
        );

        $this->hasDateCreatedColumn = !empty($row);
        return $this->hasDateCreatedColumn;
    }

    private function normalizeStatusFilter($raw): string
    {
        $status = strtolower(trim((string) $raw));
        if (!in_array($status, ['all', 'active', 'expired', 'permanent'], true)) {
            $status = 'all';
        }

        return $status;
    }

    private function normalizeOrderBy($raw): array
    {
        $dateCreatedExpr = $this->hasDateCreatedColumn() ? 'blacklist.date_created' : 'blacklist.date_start';
        $quotedCryptKey = $this->quotedCryptKey();
        $bannedEmailExpr = 'LOWER(CAST(AES_DECRYPT(users_banned.email, ' . $quotedCryptKey . ') AS CHAR(255)))';
        $authorEmailExpr = 'LOWER(CAST(AES_DECRYPT(users_author.email, ' . $quotedCryptKey . ') AS CHAR(255)))';

        $map = [
            'id' => 'blacklist.id',
            'date_created' => $dateCreatedExpr,
            'date_start' => 'blacklist.date_start',
            'date_end' => 'blacklist.date_end',
            'banned_email' => $bannedEmailExpr,
            'author_email' => $authorEmailExpr,
        ];

        $defaultField = 'date_start';
        $defaultDir = 'DESC';

        $parts = explode('|', (string) $raw);
        $field = trim((string) $parts[0]);
        $dir = strtoupper(trim((string) ($parts[1] ?? $defaultDir)));

        if (!isset($map[$field])) {
            $field = $defaultField;
        }
        if ($dir !== 'DESC') {
            $dir = 'ASC';
        }

        return [
            'raw' => $field . '|' . $dir,
            'sql' => ' ORDER BY ' . $map[$field] . ' ' . $dir . ', blacklist.id DESC',
        ];
    }

    private function normalizeDateTime($value, bool $nullable = false): ?string
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return $nullable ? null : date('Y-m-d H:i:s');
        }

        $timestamp = strtotime(str_replace('T', ' ', $raw));
        if ($timestamp === false) {
            $this->failValidation('Data non valida', 'blacklist_date_invalid');
        }

        return date('Y-m-d H:i:s', $timestamp);
    }

    private function assertUserExists(int $userId): void
    {
        if ($userId <= 0) {
            $this->failValidation('Utente non valido', 'blacklist_user_invalid');
        }

        $row = $this->firstPrepared(
            'SELECT id
             FROM users
             WHERE id = ?
             LIMIT 1',
            [$userId],
        );

        if (empty($row)) {
            $this->failValidation('Utente non valido', 'blacklist_user_invalid');
        }
    }

    private function statusWhereClause(string $status): string
    {
        if ($status === 'active') {
            return '(blacklist.date_start <= NOW() AND (blacklist.date_end IS NULL OR blacklist.date_end > NOW()))';
        }
        if ($status === 'expired') {
            return '(blacklist.date_end IS NOT NULL AND blacklist.date_end <= NOW())';
        }
        if ($status === 'permanent') {
            return 'blacklist.date_end IS NULL';
        }

        return '1 = 1';
    }

    public function listEntries(string $emailRaw, string $statusRaw, int $pageRaw, int $resultsRaw, string $orderByRaw): array
    {
        $email = strtolower(trim($emailRaw));
        $status = $this->normalizeStatusFilter($statusRaw);
        $dateCreatedExpr = $this->hasDateCreatedColumn() ? 'blacklist.date_created' : 'blacklist.date_start';

        $page = $pageRaw < 1 ? 1 : $pageRaw;
        $results = $resultsRaw;
        if ($results < 1) {
            $results = 20;
        } elseif ($results > 100) {
            $results = 100;
        }
        $offset = ($page - 1) * $results;
        $order = $this->normalizeOrderBy($orderByRaw);

        $whereParts = [];
        $whereParams = [];
        if ($email !== '') {
            $whereParts[] = 'LOWER(CAST(AES_DECRYPT(users_banned.email, ?) AS CHAR(255))) LIKE ?';
            $whereParams[] = $this->cryptKey();
            $whereParams[] = '%' . $email . '%';
        }
        if ($status !== 'all') {
            $whereParts[] = $this->statusWhereClause($status);
        }

        $whereSql = '';
        if (!empty($whereParts)) {
            $whereSql = ' WHERE ' . implode(' AND ', $whereParts);
        }

        $datasetParams = array_merge([$this->cryptKey(), $this->cryptKey()], $whereParams, [$offset, $results]);
        $dataset = $this->fetchPrepared(
            'SELECT blacklist.id,
                    blacklist.banned_id,
                    blacklist.author_id,
                    blacklist.motivation,
                    ' . $dateCreatedExpr . ' AS date_created,
                    blacklist.date_start,
                    blacklist.date_end,
                    CAST(AES_DECRYPT(users_banned.email, ?) AS CHAR(255)) AS banned_email,
                    CAST(AES_DECRYPT(users_author.email, ?) AS CHAR(255)) AS author_email,
                    CASE
                        WHEN blacklist.date_end IS NULL THEN "permanent"
                        WHEN blacklist.date_end <= NOW() THEN "expired"
                        WHEN blacklist.date_start <= NOW() AND blacklist.date_end > NOW() THEN "active"
                        ELSE "scheduled"
                    END AS status
             FROM blacklist
             LEFT JOIN users AS users_banned ON blacklist.banned_id = users_banned.id
             LEFT JOIN users AS users_author ON blacklist.author_id = users_author.id
             ' . $whereSql . '
             ' . $order['sql'] . '
             LIMIT ?, ?',
            $datasetParams,
        );

        $count = $this->firstPrepared(
            'SELECT COUNT(*) AS count
             FROM blacklist
             LEFT JOIN users AS users_banned ON blacklist.banned_id = users_banned.id
             LEFT JOIN users AS users_author ON blacklist.author_id = users_author.id
             ' . $whereSql,
            $whereParams,
        );

        $total = (!empty($count) && isset($count->count)) ? (int) $count->count : 0;

        return [
            'query' => [
                'email' => $email,
                'status' => $status,
            ],
            'page' => $page,
            'results_page' => $results,
            'orderBy' => $order['raw'],
            'tot' => (object) ['count' => max(0, $total)],
            'dataset' => !empty($dataset) ? $dataset : [],
        ];
    }

    public function create(object $data, int $authorId): void
    {
        $bannedId = isset($data->banned_id) ? (int) $data->banned_id : 0;
        $motivation = trim((string) ($data->motivation ?? ''));
        $dateStart = $this->normalizeDateTime($data->date_start ?? '', false);
        $dateEnd = $this->normalizeDateTime($data->date_end ?? '', true);

        if ($motivation === '') {
            $this->failValidation('Motivazione obbligatoria', 'blacklist_motivation_required');
        }
        if ($dateEnd !== null && strtotime($dateEnd) <= strtotime($dateStart)) {
            $this->failValidation('La data fine deve essere successiva alla data inizio', 'blacklist_date_range_invalid');
        }

        $this->assertUserExists($bannedId);
        $this->assertUserExists($authorId);

        $active = $this->firstPrepared(
            'SELECT id
             FROM blacklist
             WHERE banned_id = ?
               AND date_start <= NOW()
               AND (date_end IS NULL OR date_end > NOW())
             LIMIT 1',
            [$bannedId],
        );
        if (!empty($active)) {
            $this->failValidation('Esiste gia un ban attivo per questo utente', 'blacklist_duplicate_active');
        }

        $this->execPrepared(
            'INSERT INTO blacklist SET
                banned_id = ?,
                author_id = ?,
                motivation = ?,
                date_start = ?,
                date_end = ?',
            [$bannedId, $authorId, $motivation, $dateStart, $dateEnd],
        );
        AuditLogService::writeEvent('blacklist.create', ['banned_id' => $bannedId, 'author_id' => $authorId], 'admin');
    }

    public function update(object $data): void
    {
        $id = isset($data->id) ? (int) $data->id : 0;
        $bannedId = isset($data->banned_id) ? (int) $data->banned_id : 0;
        $motivation = trim((string) ($data->motivation ?? ''));
        $dateStart = $this->normalizeDateTime($data->date_start ?? '', false);
        $dateEnd = $this->normalizeDateTime($data->date_end ?? '', true);

        if ($id <= 0) {
            $this->failValidation('Record non valido', 'blacklist_invalid');
        }
        if ($motivation === '') {
            $this->failValidation('Motivazione obbligatoria', 'blacklist_motivation_required');
        }
        if ($dateEnd !== null && strtotime($dateEnd) <= strtotime($dateStart)) {
            $this->failValidation('La data fine deve essere successiva alla data inizio', 'blacklist_date_range_invalid');
        }

        $this->assertUserExists($bannedId);

        $existing = $this->firstPrepared(
            'SELECT id
             FROM blacklist
             WHERE id = ?
             LIMIT 1',
            [$id],
        );
        if (empty($existing)) {
            $this->failValidation('Record non valido', 'blacklist_invalid');
        }

        $this->execPrepared(
            'UPDATE blacklist SET
                banned_id = ?,
                motivation = ?,
                date_start = ?,
                date_end = ?
             WHERE id = ?',
            [$bannedId, $motivation, $dateStart, $dateEnd, $id],
        );
        AuditLogService::writeEvent('blacklist.update', ['id' => $id], 'admin');
    }

    public function delete(int $id): void
    {
        if ($id <= 0) {
            $this->failValidation('Record non valido', 'blacklist_invalid');
        }

        $this->execPrepared(
            'DELETE FROM blacklist
             WHERE id = ?',
            [$id],
        );
        AuditLogService::writeEvent('blacklist.delete', ['id' => $id], 'admin');
    }
}
