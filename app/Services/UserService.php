<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database\DbAdapterFactory;
use Core\Database\DbAdapterInterface;
use Core\Http\AppError;

class UserService
{
    /** @var DbAdapterInterface */
    private $db;
    /** @var bool|null */
    private $restrictionColumnExists = null;
    /** @var bool|null */
    private $superuserColumnExists = null;

    public function __construct(DbAdapterInterface $db = null)
    {
        $this->db = $db ?: DbAdapterFactory::createFromConfig();
    }

    private function firstPrepared(string $sql, array $params = [])
    {
        return $this->db->fetchOnePrepared($sql, $params);
    }

    private function execPrepared(string $sql, array $params = []): void
    {
        $this->db->executePrepared($sql, $params);
    }

    private function cryptKey(): string
    {
        if (defined('DB') && isset(DB['crypt_key'])) {
            return (string) DB['crypt_key'];
        }

        return '';
    }

    private function quotedCryptKey(): string
    {
        return "'" . str_replace("'", "''", $this->cryptKey()) . "'";
    }

    private function normalizeStatusFilter($raw): string
    {
        $status = strtolower(trim((string) $raw));
        if ($status !== 'active' && $status !== 'disabled' && $status !== 'pending') {
            $status = 'all';
        }

        return $status;
    }

    private function normalizeOrderBy($raw): array
    {
        $quotedCryptKey = $this->quotedCryptKey();
        $map = [
            'email' => 'LOWER(CAST(AES_DECRYPT(users.email, ' . $quotedCryptKey . ') AS CHAR(255)))',
            'character_name' => 'LOWER(CONCAT_WS(" ", IFNULL(ch.name, ""), IFNULL(ch.surname, "")))',
            'date_created' => 'users.date_created',
            'date_actived' => 'users.date_actived',
            'date_last_signin' => 'users.date_last_signin',
            'date_last_signout' => 'users.date_last_signout',
        ];

        $defaultField = 'date_created';
        $defaultDir = 'DESC';

        $parts = explode('|', (string) $raw);
        $field = trim((string) ($parts[0] ?? ''));
        $dir = strtoupper(trim((string) ($parts[1] ?? $defaultDir)));

        if (!isset($map[$field])) {
            $field = $defaultField;
        }
        if ($dir !== 'DESC') {
            $dir = 'ASC';
        }

        return [
            'raw' => $field . '|' . $dir,
            'sql' => ' ORDER BY ' . $map[$field] . ' ' . $dir . ', users.id DESC',
        ];
    }

    private function failValidation(string $message): void
    {
        throw AppError::validation($message);
    }

    public function normalizePermissionsHierarchy(int $isAdministrator, int $isModerator, int $isMaster): array
    {
        $admin = ($isAdministrator === 1);
        $moderator = $admin || ($isModerator === 1);
        $master = $admin || $moderator || ($isMaster === 1);

        return [
            'is_administrator' => $admin ? 1 : 0,
            'is_moderator' => $moderator ? 1 : 0,
            'is_master' => $master ? 1 : 0,
        ];
    }

    private function verifyPassword(string $password, string $hash, int $userId = 0): bool
    {
        $info = password_get_info($hash);
        if (!empty($info['algo'])) {
            if (password_verify($password, $hash)) {
                if (password_needs_rehash($hash, PASSWORD_DEFAULT) && $userId > 0) {
                    $this->upgradePasswordHash($userId, $password);
                }

                return true;
            }

            return false;
        }

        if (hash_equals($hash, md5($password))) {
            if ($userId > 0) {
                $this->upgradePasswordHash($userId, $password);
            }

            return true;
        }

        return false;
    }

    private function upgradePasswordHash(int $userId, string $password): void
    {
        if ($userId <= 0) {
            return;
        }

        $newHash = password_hash($password, PASSWORD_DEFAULT);
        $this->execPrepared(
            'UPDATE users SET
                password = ?,
                date_last_pass = NOW()
             WHERE id = ?',
            [$newHash, $userId],
        );
    }

    private function isPasswordUnusedForLegacyHash(string $password): bool
    {
        $row = $this->firstPrepared(
            'SELECT password
             FROM users
             WHERE password = ?
             LIMIT 1',
            [md5($password)],
        );

        return empty($row);
    }

    public function isRestrictionFeatureAvailable(): bool
    {
        if ($this->restrictionColumnExists !== null) {
            return $this->restrictionColumnExists;
        }

        $dbName = DB['mysql']['db_name'] ?? '';
        if ($dbName === '') {
            $this->restrictionColumnExists = false;
            return false;
        }

        $row = $this->firstPrepared(
            'SELECT 1 AS ok
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ?
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?
             LIMIT 1',
            [$dbName, 'users', 'is_restricted'],
        );

        $this->restrictionColumnExists = !empty($row);
        return $this->restrictionColumnExists;
    }

    public function isSuperuserFeatureAvailable(): bool
    {
        if ($this->superuserColumnExists !== null) {
            return $this->superuserColumnExists;
        }

        $dbName = DB['mysql']['db_name'] ?? '';
        if ($dbName === '') {
            $this->superuserColumnExists = false;
            return false;
        }

        $row = $this->firstPrepared(
            'SELECT 1 AS ok
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ?
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?
             LIMIT 1',
            [$dbName, 'users', 'is_superuser'],
        );

        $this->superuserColumnExists = !empty($row);
        return $this->superuserColumnExists;
    }

    public function isRestricted(int $userId): bool
    {
        if ($userId <= 0 || !$this->isRestrictionFeatureAvailable()) {
            return false;
        }

        $row = $this->firstPrepared(
            'SELECT is_restricted
             FROM users
             WHERE id = ?
             LIMIT 1',
            [$userId],
        );

        return !empty($row) && (int) $row->is_restricted === 1;
    }

    public function emailExists(string $email): bool
    {
        $value = strtolower(trim($email));
        if ($value === '') {
            return false;
        }

        $row = $this->firstPrepared(
            'SELECT id
             FROM users
             WHERE email = AES_ENCRYPT(?, ?)
             LIMIT 1',
            [$value, $this->cryptKey()],
        );

        return !empty($row);
    }

    public function generateRandomPassword(): string
    {
        $length = (int) (CONFIG['password_length'] ?? 8);
        if ($length <= 0) {
            $length = 8;
        }

        do {
            $password = bin2hex(random_bytes($length));
        } while ($this->isPasswordUnusedForLegacyHash($password) === false);

        return $password;
    }

    public function assertPasswordValid(int $userId, string $password): void
    {
        if ($userId <= 0 || trim($password) === '') {
            $this->failValidation('Password non valida');
        }

        $user = $this->firstPrepared(
            'SELECT id, password
             FROM users
             WHERE id = ?
             LIMIT 1',
            [$userId],
        );
        if (empty($user)) {
            $this->failValidation('Utente non trovato');
        }

        if ($this->verifyPassword($password, (string) $user->password, (int) $user->id) === false) {
            $this->failValidation('Password non valida');
        }
    }

    public function getAdminUserById(int $userId)
    {
        if ($userId <= 0) {
            return [];
        }

        $restrictionSelect = $this->isRestrictionFeatureAvailable()
            ? 'users.is_restricted'
            : '0 AS is_restricted';
        $superuserSelect = $this->isSuperuserFeatureAvailable()
            ? 'users.is_superuser'
            : 'users.is_administrator AS is_superuser';

        return $this->firstPrepared(
            'SELECT users.id,
                    CAST(AES_DECRYPT(users.email, ?) AS CHAR(255)) AS email,
                    users.is_administrator,
                    users.is_moderator,
                    users.is_master,
                    ' . $superuserSelect . ',
                    users.date_created,
                    users.date_actived,
                    users.date_last_signin,
                    users.date_last_signout,
                    users.date_sessions_revoked,
                    ' . $restrictionSelect . '
             FROM users
             WHERE users.id = ?
             LIMIT 1',
            [$this->cryptKey(), $userId],
        );
    }

    public function listAdminUsers(string $searchRaw, string $statusRaw, int $pageRaw, int $resultsRaw, string $orderByRaw, bool $isSuperuser = false): array
    {
        $search = strtolower(trim($searchRaw));
        $status = $this->normalizeStatusFilter($statusRaw);

        $page = $pageRaw;
        if ($page < 1) {
            $page = 1;
        }

        $results = $resultsRaw;
        if ($results < 1) {
            $results = 20;
        } elseif ($results > 100) {
            $results = 100;
        }

        $order = $this->normalizeOrderBy($orderByRaw);

        $whereParts = [];
        $whereParams = [];
        if ($search !== '') {
            $searchLike = '%' . $search . '%';
            $characterSql = 'LOWER(CONCAT_WS(" ", IFNULL(ch.name, ""), IFNULL(ch.surname, ""))) LIKE ?';
            if ($isSuperuser) {
                $whereParts[] = '('
                    . 'LOWER(CAST(AES_DECRYPT(users.email, ?) AS CHAR(255))) LIKE ?'
                    . ' OR '
                    . $characterSql
                    . ')';
                $whereParams[] = $this->cryptKey();
                $whereParams[] = $searchLike;
                $whereParams[] = $searchLike;
            } else {
                $whereParts[] = $characterSql;
                $whereParams[] = $searchLike;
            }
        }

        if ($status === 'active') {
            $whereParts[] = 'users.date_actived IS NOT NULL';
        } elseif ($status === 'disabled') {
            $whereParts[] = 'users.date_actived IS NULL';
            $whereParts[] = 'users.date_last_signin IS NOT NULL';
        } elseif ($status === 'pending') {
            $whereParts[] = 'users.date_actived IS NULL';
            $whereParts[] = 'users.date_last_signin IS NULL';
        }

        $whereSql = '';
        if (!empty($whereParts)) {
            $whereSql = ' WHERE ' . implode(' AND ', $whereParts);
        }

        $offset = ($page - 1) * $results;
        $restrictionSelect = $this->isRestrictionFeatureAvailable()
            ? 'users.is_restricted'
            : '0 AS is_restricted';
        $superuserSelect = $this->isSuperuserFeatureAvailable()
            ? 'users.is_superuser'
            : 'users.is_administrator AS is_superuser';
        $emailSelect = $isSuperuser
            ? 'CAST(AES_DECRYPT(users.email, ?) AS CHAR(255)) AS email'
            : 'NULL AS email';

        $datasetParams = [];
        if ($isSuperuser) {
            $datasetParams[] = $this->cryptKey();
        }
        $datasetParams = array_merge($datasetParams, $whereParams, [$results, $offset]);
        $dataset = $this->db->fetchAllPrepared(
            'SELECT users.id,
                    ' . $emailSelect . ',
                    users.is_administrator,
                    users.is_moderator,
                    users.is_master,
                    ' . $superuserSelect . ',
                    ch.id AS character_id,
                    ch.name AS character_name,
                    ch.surname AS character_surname,
                    users.date_created,
                    users.date_actived,
                    users.date_last_signin,
                    users.date_last_signout,
                    users.date_sessions_revoked,
                    ' . $restrictionSelect . ',
                    CASE
                        WHEN users.date_actived IS NOT NULL THEN "active"
                        WHEN users.date_last_signin IS NOT NULL THEN "disabled"
                        ELSE "pending"
                    END AS status
             FROM users
             LEFT JOIN (
                SELECT c.user_id, MIN(c.id) AS character_id
                FROM characters c
                WHERE c.delete_scheduled_at IS NULL OR c.delete_scheduled_at > NOW()
                GROUP BY c.user_id
             ) uc ON uc.user_id = users.id
             LEFT JOIN characters ch ON ch.id = uc.character_id
             ' . $whereSql . '
             ' . $order['sql'] . '
             LIMIT ? OFFSET ?',
            $datasetParams,
        );

        $count = $this->firstPrepared(
            'SELECT COUNT(*) AS count
             FROM users
             LEFT JOIN (
                SELECT c.user_id, MIN(c.id) AS character_id
                FROM characters c
                WHERE c.delete_scheduled_at IS NULL OR c.delete_scheduled_at > NOW()
                GROUP BY c.user_id
             ) uc ON uc.user_id = users.id
             LEFT JOIN characters ch ON ch.id = uc.character_id
             ' . $whereSql,
            $whereParams,
        );

        if (empty($count)) {
            $count = (object) ['count' => 0];
        }

        return [
            'query' => [
                'search' => $search,
                'status' => $status,
            ],
            'page' => $page,
            'results_page' => $results,
            'orderBy' => $order['raw'],
            'tot' => $count,
            'dataset' => !empty($dataset) ? $dataset : [],
        ];
    }

    public function createPasswordResetToken(int $userId, int $expiresMinutes = 60): string
    {
        if ($expiresMinutes <= 0) {
            $expiresMinutes = 60;
        }

        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);

        $this->execPrepared(
            'DELETE FROM password_resets WHERE user_id = ? AND used_at IS NULL',
            [$userId],
        );
        $this->execPrepared(
            'INSERT INTO password_resets SET
                user_id = ?,
                token_hash = ?,
                expires_at = DATE_ADD(NOW(), INTERVAL ? MINUTE),
                created_at = NOW()',
            [$userId, $tokenHash, $expiresMinutes],
        );

        return $token;
    }

    public function setAdminPermissions(int $userId, int $isAdministrator, int $isModerator, int $isMaster): void
    {
        $normalized = $this->normalizePermissionsHierarchy($isAdministrator, $isModerator, $isMaster);
        $this->execPrepared(
            'UPDATE users SET
                is_administrator = ?,
                is_moderator = ?,
                is_master = ?
             WHERE id = ?',
            [$normalized['is_administrator'], $normalized['is_moderator'], $normalized['is_master'], $userId],
        );
    }

    public function disconnectUserSessions(int $userId): void
    {
        $this->execPrepared(
            'UPDATE users SET
                session_version = IFNULL(session_version, 1) + 1,
                date_sessions_revoked = NOW()
             WHERE id = ?',
            [$userId],
        );
    }

    public function setUserRestriction(int $userId, int $isRestricted): void
    {
        if ($isRestricted === 1) {
            $this->execPrepared(
                'UPDATE users SET
                    is_restricted = 1,
                    session_version = IFNULL(session_version, 1) + 1,
                    date_sessions_revoked = NOW()
                 WHERE id = ?',
                [$userId],
            );
            return;
        }

        $this->execPrepared(
            'UPDATE users SET
                is_restricted = 0
             WHERE id = ?',
            [$userId],
        );
    }

    public function revokeSessions(int $userId): ?int
    {
        $this->disconnectUserSessions($userId);

        $row = $this->firstPrepared(
            'SELECT session_version
             FROM users
             WHERE id = ?
             LIMIT 1',
            [$userId],
        );

        if (empty($row) || !isset($row->session_version)) {
            return null;
        }

        return (int) $row->session_version;
    }

    public function createUser(object $data, string $hash): void
    {
        $normalized = $this->normalizePermissionsHierarchy(
            isset($data->is_administrator) ? (int) $data->is_administrator : 0,
            isset($data->is_moderator) ? (int) $data->is_moderator : 0,
            isset($data->is_master) ? (int) $data->is_master : 0,
        );
        $this->execPrepared(
            'INSERT INTO users SET
                username = ?,
                email = AES_ENCRYPT(?, ?),
                gender  = ?,
                password = ?,
                is_administrator = ?,
                is_moderator = ?,
                is_master = ?',
            [
                (string) $data->username,
                (string) $data->email,
                $this->cryptKey(),
                (int) $data->gender,
                $hash,
                (int) $normalized['is_administrator'],
                (int) $normalized['is_moderator'],
                (int) $normalized['is_master'],
            ],
        );
    }

    public function updateUser(object $data): void
    {
        $normalized = $this->normalizePermissionsHierarchy(
            isset($data->is_administrator) ? (int) $data->is_administrator : 0,
            isset($data->is_moderator) ? (int) $data->is_moderator : 0,
            isset($data->is_master) ? (int) $data->is_master : 0,
        );
        $this->execPrepared(
            'UPDATE users SET
                username = ?,
                email = AES_ENCRYPT(?, ?),
                gender  = ?,
                is_administrator = ?,
                is_moderator = ?,
                is_master = ?
            WHERE id = ?',
            [
                (string) $data->username,
                (string) $data->email,
                $this->cryptKey(),
                (int) $data->gender,
                (int) $normalized['is_administrator'],
                (int) $normalized['is_moderator'],
                (int) $normalized['is_master'],
                (int) $data->id,
            ],
        );
    }

    public function setUserActive(int $userId, bool $active): void
    {
        $this->execPrepared(
            'UPDATE users SET
                date_actived = ' . ($active ? 'NOW()' : 'NULL') . '
            WHERE id = ?',
            [$userId],
        );
    }

    public function createSystemSeedUser(string $email = 'test@pbce.com', string $plainPassword = 'test'): void
    {
        $hash = password_hash($plainPassword, PASSWORD_DEFAULT);
        $this->execPrepared(
            'INSERT INTO users SET
                email = AES_ENCRYPT(?, ?),
                gender  = ?,
                password = ?,
                is_administrator = ?',
            [(string) $email, $this->cryptKey(), rand(0, 1), $hash, 1],
        );
    }
}
