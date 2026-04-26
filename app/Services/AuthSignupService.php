<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database\DbAdapterFactory;
use Core\Database\DbAdapterInterface;
use Core\Http\ApiResponse;
use Core\Http\RequestContext;
use Core\Http\RequestData;
use Core\Http\ResponseEmitter;

use Core\Logging\LoggerInterface;
use Core\RateLimiter;

class AuthSignupService
{
    /** @var DbAdapterInterface */
    private $db;
    /** @var LoggerInterface */
    private $logger;
    /** @var array<string,bool> */
    private $usersColumnCache = [];

    public function __construct(DbAdapterInterface $db = null, LoggerInterface $logger = null)
    {
        $this->db = $db ?: DbAdapterFactory::createFromConfig();
        $this->logger = $logger ?: \Core\AppContext::logger();
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
        if (defined('DB')) {
            return (string) DB['crypt_key'];
        }

        return '';
    }

    private function trace($message, $context = false): void
    {
        $this->logger->trace($message, $context);
    }

    private function requestData($default = null)
    {
        $request = RequestData::fromGlobals();
        return $request->postJson('data', $default, false);
    }

    private function getServerValue(string $key, $default = null)
    {
        $server = RequestContext::server();
        return array_key_exists($key, $server) ? $server[$key] : $default;
    }

    private function usersColumnExists(string $column): bool
    {
        $column = trim($column);
        if ($column === '') {
            return false;
        }

        if (array_key_exists($column, $this->usersColumnCache)) {
            return $this->usersColumnCache[$column];
        }

        $dbName = (string) DB['mysql']['db_name'];

        try {
            $row = $this->firstPrepared(
                'SELECT 1 AS ok
                 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = ?
                   AND TABLE_NAME = ?
                   AND COLUMN_NAME = ?
                 LIMIT 1',
                [$dbName, 'users', $column],
            );
        } catch (\Throwable $e) {
            $this->usersColumnCache[$column] = false;
            return false;
        }

        $this->usersColumnCache[$column] = !empty($row);
        return $this->usersColumnCache[$column];
    }

    private function emailExists(string $email): bool
    {
        if ($email === '') {
            return false;
        }

        $row = $this->firstPrepared(
            'SELECT id
             FROM users
             WHERE email = AES_ENCRYPT(?, ?)
             LIMIT 1',
            [$email, $this->cryptKey()],
        );

        return !empty($row);
    }

    private function responseError(string $title, string $body, string $errorCode = ''): void
    {
        ResponseEmitter::emit(ApiResponse::json([
            'error_auth' => [
                'title' => $title,
                'body' => $body,
            ],
            'error_code' => $errorCode,
        ]));
    }

    private function responseSuccess(string $title, string $body): void
    {
        ResponseEmitter::emit(ApiResponse::json([
            'success' => [
                'title' => $title,
                'body' => $body,
            ],
        ]));
    }

    private function dispatchMail(string $to, string $subject, string $htmlBody): bool
    {
        $to = trim($to);
        if ($to === '') {
            return false;
        }

        $from = trim((string) APP['support_email']);
        $headers = [];
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Content-type: text/html; charset=UTF-8';
        if ($from !== '' && $from !== '-') {
            $headers[] = 'From: ' . $from;
            $headers[] = 'Reply-To: ' . $from;
        }

        return @mail($to, $subject, $htmlBody, implode("\r\n", $headers));
    }

    private function isHttps(): bool
    {
        $https = strtolower((string) $this->getServerValue('HTTPS', ''));
        $forwardedProto = strtolower((string) $this->getServerValue('HTTP_X_FORWARDED_PROTO', ''));
        $port = (int) $this->getServerValue('SERVER_PORT', 0);

        return $https === 'on' || $https === '1' || $forwardedProto === 'https' || $port === 443;
    }

    private function absoluteUrl(string $path): string
    {
        $rawHost = trim((string) APP['baseurl']);
        if ($rawHost === '') {
            $rawHost = trim((string) $this->getServerValue('HTTP_HOST', ''));
        }
        $host = preg_replace('#^https?://#i', '', $rawHost);
        $host = trim((string) $host, " \t\n\r\0\x0B/");
        $scheme = $this->isHttps() ? 'https' : 'http';

        return $scheme . '://' . $host . '/' . ltrim($path, '/');
    }

    private function ensureEmailVerificationsTable(): void
    {
        $this->execPrepared(
            'CREATE TABLE IF NOT EXISTS email_verifications (
                id INT(11) NOT NULL AUTO_INCREMENT,
                user_id INT(11) NOT NULL,
                token_hash VARCHAR(64) NOT NULL,
                expires_at DATETIME NOT NULL,
                used_at DATETIME NULL DEFAULT NULL,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_email_verifications_token_hash (token_hash),
                KEY idx_email_verifications_user_id (user_id),
                KEY idx_email_verifications_expires_at (expires_at),
                KEY idx_email_verifications_used_at (used_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            [],
        );
    }

    private function createEmailVerificationToken(int $userId, int $expiresHours = 48): string
    {
        $this->ensureEmailVerificationsTable();
        if ($expiresHours <= 0) {
            $expiresHours = 48;
        }

        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);

        $this->execPrepared(
            'DELETE FROM email_verifications
             WHERE user_id = ?
               AND used_at IS NULL',
            [$userId],
        );
        $this->execPrepared(
            'INSERT INTO email_verifications SET
                user_id = ?,
                token_hash = ?,
                expires_at = DATE_ADD(NOW(), INTERVAL ? HOUR),
                created_at = NOW()',
            [$userId, $tokenHash, $expiresHours],
        );

        return $token;
    }

    /**
     * @return array{status:string,message:string}
     */
    public function verifyEmailToken(string $token): array
    {
        $rawToken = trim($token);
        if ($rawToken === '' || !preg_match('/^[a-f0-9]{64}$/i', $rawToken)) {
            return [
                'status' => 'invalid',
                'message' => 'Il link di verifica non e valido.',
            ];
        }

        $this->ensureEmailVerificationsTable();
        $tokenHash = hash('sha256', $rawToken);
        $row = $this->firstPrepared(
            'SELECT id, user_id, expires_at, used_at
             FROM email_verifications
             WHERE token_hash = ?
             ORDER BY id DESC
             LIMIT 1',
            [$tokenHash],
        );

        if (empty($row)) {
            return [
                'status' => 'invalid',
                'message' => 'Il link di verifica non e valido.',
            ];
        }

        if (!empty($row->used_at)) {
            return [
                'status' => 'already_used',
                'message' => 'Questo link e gia stato utilizzato.',
            ];
        }

        if (!empty($row->expires_at) && strtotime((string) $row->expires_at) < time()) {
            return [
                'status' => 'expired',
                'message' => 'Il link di verifica e scaduto.',
            ];
        }

        $user = $this->firstPrepared(
            'SELECT id, date_actived
             FROM users
             WHERE id = ?
             LIMIT 1',
            [(int) $row->user_id],
        );
        if (empty($user)) {
            return [
                'status' => 'invalid',
                'message' => 'Utente non trovato per questo link di verifica.',
            ];
        }

        $alreadyActive = !empty($user->date_actived);
        if (!$alreadyActive) {
            $this->execPrepared(
                'UPDATE users SET
                    date_actived = NOW()
                 WHERE id = ?',
                [(int) $user->id],
            );
        }

        $this->execPrepared(
            'UPDATE email_verifications SET
                used_at = NOW()
             WHERE id = ?',
            [(int) $row->id],
        );

        return [
            'status' => $alreadyActive ? 'already_active' : 'success',
            'message' => $alreadyActive
                ? 'Account gia attivo. Puoi accedere.'
                : 'Email verificata con successo. Ora puoi accedere.',
        ];
    }

    public function signup(): void
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);

        $data = $this->requestData((object) []);
        if ($data === null || !is_object($data)) {
            $data = (object) [];
        }

        $email = strtolower(trim((string) ($data->email ?? '')));
        $password = (string) ($data->password ?? '');
        $passwordConfirm = (string) ($data->password_confirm ?? '');
        $ip = (string) $this->getServerValue('REMOTE_ADDR', '0.0.0.0');
        $identity = 'signup:' . $ip . ':' . $email;

        $rule = RateLimiter::getRule('auth_signup', 6, 900, 1, 40, 30, 86400);
        $rate = RateLimiter::hit('auth.signup', $rule['limit'], $rule['window'], $identity);
        if (empty($rate['allowed'])) {
            $this->responseError(
                'Troppi tentativi',
                'Hai superato il limite di registrazioni. Riprova tra ' . (int) $rate['retry_after'] . ' secondi.',
                'signup_rate_limited',
            );
            return;
        }

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->responseError('Email non valida', 'Inserisci un indirizzo email valido.', 'signup_email_invalid');
            return;
        }

        $passwordValidation = AuthPasswordPolicy::validate($password, $passwordConfirm, true);
        if (!$passwordValidation['valid']) {
            $this->responseError(
                'Password non valida',
                $passwordValidation['message'],
                (string) $passwordValidation['error_code'],
            );
            return;
        }

        if ($this->emailExists($email)) {
            $this->responseError(
                'Email gia registrata',
                'Questo indirizzo email e gia associato a un account.',
                'signup_email_exists',
            );
            return;
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $columns = [
            'email',
            'password',
            'gender',
            'is_administrator',
            'is_moderator',
            'is_master',
            'date_actived',
            'date_last_pass',
            'session_version',
        ];
        $values = [
            'AES_ENCRYPT(?, ?)',
            '?',
            '?',
            '?',
            '?',
            '?',
            'NULL',
            'NOW()',
            '?',
        ];
        $params = [
            $email,
            $this->cryptKey(),
            $hash,
            1,
            0,
            0,
            0,
            1,
        ];

        if ($this->usersColumnExists('username')) {
            $parts = explode('@', $email);
            $username = trim((string) $parts[0]);
            if ($username === '') {
                $username = 'Utente';
            }
            $columns[] = 'username';
            $values[] = '?';
            $params[] = $username;
        }

        $this->execPrepared(
            'INSERT INTO users (' . implode(', ', $columns) . ')
             VALUES (' . implode(', ', $values) . ')',
            $params,
        );
        $userId = (int) $this->db->lastInsertId();
        if ($userId <= 0) {
            $this->responseError('Registrazione non riuscita', 'Impossibile completare la registrazione.', 'signup_create_failed');
            return;
        }

        try {
            $token = $this->createEmailVerificationToken($userId, 48);
        } catch (\Throwable $e) {
            $this->trace('[Signup] token verifica non creato', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            $this->execPrepared('DELETE FROM users WHERE id = ? LIMIT 1', [$userId]);
            $this->responseError(
                'Registrazione non riuscita',
                'Impossibile completare la registrazione in questo momento.',
                'signup_verification_token_failed',
            );
            return;
        }

        $verifyUrl = $this->absoluteUrl('/verify-email/' . $token);
        $mailSent = $this->dispatchMail(
            $email,
            'Verifica il tuo account su ' . (string) APP['name'],
            '<h3>Conferma la tua email</h3>'
            . '<p>Per attivare il tuo account clicca sul link qui sotto:</p>'
            . '<p><a href="' . $verifyUrl . '">' . $verifyUrl . '</a></p>'
            . '<p>Il link scade tra 48 ore.</p>',
        );

        if (!$mailSent) {
            $this->trace('[Signup] invio email benvenuto non riuscito', [
                'user_id' => $userId,
                'email' => $email,
            ]);
            $this->execPrepared('DELETE FROM email_verifications WHERE user_id = ?', [$userId]);
            $this->execPrepared('DELETE FROM users WHERE id = ? LIMIT 1', [$userId]);
            $this->responseError(
                'Registrazione non completata',
                'Impossibile inviare l\'email di verifica. Riprova piu tardi.',
                'signup_verification_email_failed',
            );
            return;
        }

        RateLimiter::clear('auth.signup', $identity);
        $this->responseSuccess(
            'Registrazione completata',
            'Ti abbiamo inviato un link di verifica email. Conferma l\'account prima di accedere.',
        );
    }
}


