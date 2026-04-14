<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database\DbAdapterFactory;
use Core\Database\DbAdapterInterface;
use Core\Http\ApiResponse;
use Core\Http\AppError;
use Core\Http\RequestContext;
use Core\Http\RequestData;
use Core\Http\ResponseEmitter;
use Core\Logging\LegacyLoggerAdapter;
use Core\Logging\LoggerInterface;
use Core\RateLimiter;

class AuthPasswordResetService
{
    /** @var DbAdapterInterface */
    private $db;
    /** @var LoggerInterface */
    private $logger;

    public function __construct(DbAdapterInterface $db = null, LoggerInterface $logger = null)
    {
        $this->db = $db ?: DbAdapterFactory::createFromConfig();
        $this->logger = $logger ?: new LegacyLoggerAdapter();
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

    private function getServerValue(string $key, $default = null)
    {
        $server = RequestContext::server();
        return array_key_exists($key, $server) ? $server[$key] : $default;
    }

    private function requestData($default = null)
    {
        $request = RequestData::fromGlobals();
        return $request->postJson('data', $default, false);
    }

    private function trace($message, $context = false): void
    {
        $this->logger->trace($message, $context);
    }

    private function failValidation(string $message): void
    {
        throw AppError::validation($message);
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
        $rawHost = trim((string) (APP['baseurl'] ?? ''));
        if ($rawHost === '') {
            $rawHost = trim((string) $this->getServerValue('HTTP_HOST', ''));
        }
        $host = preg_replace('#^https?://#i', '', $rawHost);
        $host = trim((string) $host, " \t\n\r\0\x0B/");
        $scheme = $this->isHttps() ? 'https' : 'http';

        return $scheme . '://' . $host . '/' . ltrim($path, '/');
    }

    private function dispatchMail(string $to, string $subject, string $htmlBody): bool
    {
        $to = trim($to);
        if ($to === '') {
            return false;
        }

        $from = trim((string) (APP['support_email'] ?? ''));
        $headers = [];
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Content-type: text/html; charset=UTF-8';
        if ($from !== '' && $from !== '-') {
            $headers[] = 'From: ' . $from;
            $headers[] = 'Reply-To: ' . $from;
        }

        return @mail($to, $subject, $htmlBody, implode("\r\n", $headers));
    }

    public function resetPassword()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);

        $data = $this->requestData((object) []);
        if ($data === null) {
            $data = (object) [];
        }

        $email = isset($data->email) ? strtolower(trim((string) $data->email)) : '';
        $ip = (string) $this->getServerValue('REMOTE_ADDR', '0.0.0.0');
        $identity = 'reset:' . $ip . ':' . $email;
        $rule = RateLimiter::getRule('auth_reset', 5, 900, 1, 50, 30, 86400);
        $rate = RateLimiter::hit('auth.reset_password', $rule['limit'], $rule['window'], $identity);
        if (empty($rate['allowed'])) {
            ResponseEmitter::emit(ApiResponse::json([
                'error_auth' => [
                    'title' => 'Troppi tentativi',
                    'body' => 'Hai superato il limite di richieste. Riprova tra ' . (int) $rate['retry_after'] . ' secondi.',
                ],
            ]));

            return;
        }

        $user = null;
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $user = $this->getUserByEmail($email);
        }

        if (null !== $user) {
            $token = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $token);
            $expiresMinutes = 60;

            $this->execPrepared(
                'DELETE FROM password_resets WHERE user_id = ? AND used_at IS NULL',
                [(int) $user->id],
            );
            $this->execPrepared(
                'INSERT INTO password_resets SET
                    user_id = ?,
                    token_hash = ?,
                    expires_at = DATE_ADD(NOW(), INTERVAL ? MINUTE),
                    created_at = NOW()',
                [(int) $user->id, $tokenHash, $expiresMinutes],
            );

            $resetUrl = $this->absoluteUrl('/reset-password/' . $token);
            $body = "<h3>Recupero password</h3>
            <p>Abbiamo ricevuto una richiesta di reset password per questo account.</p>
            <p>Se non sei stato tu, ignora questa email.</p>
            <p>Per impostare una nuova password clicca qui:</p>
            <p><a href=\"$resetUrl\">$resetUrl</a></p>
            <p>Il link scade tra $expiresMinutes minuti.</p>";

            $sent = $this->dispatchMail($email, 'Recupero password', $body);
            if (!$sent) {
                $this->trace('[AuthPasswordReset] invio mail reset non riuscito', [
                    'user_id' => (int) ($user->id ?? 0),
                    'email' => $email,
                ]);
            }
        }

        ResponseEmitter::emit(ApiResponse::json([
            'success' => [
                'title' => 'Link inviato',
                'body' => 'Se l\'email e registrata riceverai un link per reimpostare la password. Controlla anche la cartella spam.',
            ],
        ]));
    }

    public function resetPasswordConfirm()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);

        $data = $this->requestData((object) []);
        if ($data === null) {
            $data = (object) [];
        }

        $tokenHash = isset($data->token) ? hash('sha256', (string) $data->token) : '';
        $ip = (string) $this->getServerValue('REMOTE_ADDR', '0.0.0.0');
        $identity = 'reset-confirm:' . $ip . ':' . $tokenHash;
        $rule = RateLimiter::getRule('auth_reset_confirm', 10, 900, 1, 100, 30, 86400);
        $rate = RateLimiter::hit('auth.reset_password_confirm', $rule['limit'], $rule['window'], $identity);
        if (empty($rate['allowed'])) {
            $this->failValidation('Troppi tentativi, riprova tra ' . (int) $rate['retry_after'] . ' secondi');
        }

        if (empty($data->token)) {
            $this->failValidation('Token di reset mancante.');
        }

        $password = (string) ($data->password ?? '');
        $rewritePassword = (string) ($data->rewrite_password ?? '');
        $passwordValidation = AuthPasswordPolicy::validate($password, $rewritePassword, true);
        if (!$passwordValidation['valid']) {
            $this->failValidation($passwordValidation['message']);
        }

        $tokenHash = hash('sha256', (string) $data->token);
        $reset = $this->firstPrepared(
            'SELECT id, user_id, expires_at, used_at FROM password_resets
             WHERE token_hash = ?
             ORDER BY id DESC LIMIT 1',
            [$tokenHash],
        );

        if (null == $reset) {
            $this->failValidation('Token non valido.');
        }

        if (!empty($reset->used_at)) {
            $this->failValidation('Token gia utilizzato.');
        }

        if (!empty($reset->expires_at) && strtotime($reset->expires_at) < time()) {
            $this->failValidation('Token scaduto.');
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $this->execPrepared(
            'UPDATE users SET
                password = ?,
                date_last_pass = NOW()
             WHERE id = ?',
            [$hash, (int) $reset->user_id],
        );

        $this->execPrepared(
            'UPDATE password_resets SET
                used_at = NOW()
             WHERE id = ?',
            [(int) $reset->id],
        );

        ResponseEmitter::emit(ApiResponse::json([
            'success' => [
                'title' => 'Password aggiornata',
                'body' => 'Ora puoi accedere con la nuova password.',
            ],
        ]));
    }

    private function getUserByEmail($email)
    {
        return $this->firstPrepared(
            'SELECT id FROM users WHERE email = AES_ENCRYPT(?, ?) LIMIT 1',
            [strtolower(trim((string) $email)), $this->cryptKey()],
        );
    }
}
