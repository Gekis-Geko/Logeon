<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database\DbAdapterFactory;
use Core\Database\DbAdapterInterface;
use Core\Http\RequestData;
use Core\Logging\LegacyLoggerAdapter;
use Core\Logging\LoggerInterface;
use Core\Redirect;
use Core\SessionStore;

class AuthGoogleService
{
    private const AUTH_ENDPOINT = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';
    private const USERINFO_ENDPOINT = 'https://openidconnect.googleapis.com/v1/userinfo';

    /** @var DbAdapterInterface */
    private $db;
    /** @var LoggerInterface */
    private $logger;
    /** @var array<string,bool> */
    private $usersColumnCache = [];

    public function __construct(DbAdapterInterface $db = null, LoggerInterface $logger = null)
    {
        $this->db = $db ?: DbAdapterFactory::createFromConfig();
        $this->logger = $logger ?: new LegacyLoggerAdapter();
    }

    public static function redirectToGoogle(): void
    {
        (new self())->start();
    }

    public static function handleCallback(): void
    {
        (new self())->callback();
    }

    public static function isEnabled(): bool
    {
        try {
            $config = (new self())->googleConfig();
            return (bool) ($config['enabled'] ?? false);
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function firstPrepared(string $sql, array $params = [])
    {
        return $this->db->fetchOnePrepared($sql, $params);
    }

    private function fetchPrepared(string $sql, array $params = []): array
    {
        return $this->db->fetchAllPrepared($sql, $params);
    }

    private function cryptKey(): string
    {
        if (defined('DB') && isset(DB['crypt_key'])) {
            return (string) DB['crypt_key'];
        }

        return '';
    }

    private function trace($message, $context = false): void
    {
        $this->logger->trace($message, $context);
    }

    private function getServerValue(string $key, $default = null)
    {
        return array_key_exists($key, $_SERVER) ? $_SERVER[$key] : $default;
    }

    private function isHttps(): bool
    {
        return (
            (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
            || ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443)
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
        );
    }

    private function baseHost(): string
    {
        $raw = trim((string) (APP['baseurl'] ?? ''));
        if ($raw === '') {
            $raw = trim((string) $this->getServerValue('HTTP_HOST', ''));
        }

        $raw = preg_replace('#^https?://#i', '', $raw);
        return trim((string) $raw, " \t\n\r\0\x0B/");
    }

    private function absoluteUrl(string $path): string
    {
        $host = $this->baseHost();
        $scheme = $this->isHttps() ? 'https' : 'http';
        return $scheme . '://' . $host . '/' . ltrim($path, '/');
    }

    /**
     * @param array<int,string> $keys
     * @return array<string,string>
     */
    private function readSysConfigValues(array $keys): array
    {
        $clean = [];
        foreach ($keys as $key) {
            $k = trim((string) $key);
            if ($k === '') {
                continue;
            }
            $clean[$k] = $k;
        }
        if (empty($clean)) {
            return [];
        }

        $params = array_values($clean);
        $placeholders = implode(', ', array_fill(0, count($params), '?'));
        try {
            $rows = $this->fetchPrepared(
                'SELECT `key`, `value`
                 FROM sys_configs
                 WHERE `key` IN (' . $placeholders . ')',
                $params,
            );
        } catch (\Throwable $e) {
            return [];
        }

        if (empty($rows)) {
            return [];
        }

        $values = [];
        foreach ($rows as $row) {
            $key = isset($row->key) ? trim((string) $row->key) : '';
            if ($key === '') {
                continue;
            }
            $values[$key] = isset($row->value) ? (string) $row->value : '';
        }

        return $values;
    }

    private function googleConfig(): array
    {
        $raw = APP['oauth_google'] ?? [];
        if (!is_array($raw)) {
            $raw = [];
        }

        $db = $this->readSysConfigValues([
            'auth_google_enabled',
            'auth_google_client_id',
            'auth_google_client_secret',
            'auth_google_redirect_uri',
        ]);

        $enabledRaw = array_key_exists('auth_google_enabled', $db)
            ? $db['auth_google_enabled']
            : ($raw['enabled'] ?? false);
        $enabled = ($enabledRaw === true || $enabledRaw === 1 || (string) $enabledRaw === '1');

        $clientId = array_key_exists('auth_google_client_id', $db)
            ? trim((string) $db['auth_google_client_id'])
            : trim((string) ($raw['client_id'] ?? ''));

        $clientSecret = array_key_exists('auth_google_client_secret', $db)
            ? trim((string) $db['auth_google_client_secret'])
            : trim((string) ($raw['client_secret'] ?? ''));

        $redirectUri = array_key_exists('auth_google_redirect_uri', $db)
            ? trim((string) $db['auth_google_redirect_uri'])
            : trim((string) ($raw['redirect_uri'] ?? ''));
        if ($redirectUri === '') {
            $redirectUri = $this->absoluteUrl('/auth/google/callback');
        }

        return [
            'enabled' => $enabled,
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri' => $redirectUri,
        ];
    }

    private function clearFlash(): void
    {
        SessionStore::delete('google_auth_error_code');
        SessionStore::delete('google_auth_error_message');
        SessionStore::delete('google_auth_toast_type');
        SessionStore::delete('google_auth_open_create_character');
        SessionStore::delete('google_auth_open_select_character');
        SessionStore::delete('google_auth_select_characters');
        SessionStore::delete('google_auth_prefill_name');
    }

    private function flash(string $code, string $message, string $toastType = 'warning'): void
    {
        SessionStore::set('google_auth_error_code', $code);
        SessionStore::set('google_auth_error_message', $message);
        SessionStore::set('google_auth_toast_type', $toastType);
    }

    private function failAndBackHome(string $code, string $message): void
    {
        $this->trace('[GoogleAuth] ' . $code . ' - ' . $message);
        $this->flash($code, $message, 'error');
        Redirect::url('/');
    }

    private function usersColumnExists(string $column): bool
    {
        $column = trim(strtolower($column));
        if ($column === '') {
            return false;
        }

        if (array_key_exists($column, $this->usersColumnCache)) {
            return $this->usersColumnCache[$column];
        }

        $dbName = DB['mysql']['db_name'] ?? '';
        if ($dbName === '') {
            $this->usersColumnCache[$column] = false;
            return false;
        }

        $row = $this->firstPrepared(
            'SELECT 1 AS ok
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ?
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?
             LIMIT 1',
            [$dbName, 'users', $column],
        );

        $this->usersColumnCache[$column] = !empty($row);
        return $this->usersColumnCache[$column];
    }

    private function randomState(): string
    {
        try {
            return bin2hex(random_bytes(24));
        } catch (\Throwable $e) {
            return sha1(uniqid('google_oauth_state_', true));
        }
    }

    public function start(): void
    {
        $this->clearFlash();

        if (\Core\AppContext::authContext()->isAuthenticated() && (int) \Core\AppContext::session()->get('character_id') > 0) {
            Redirect::url('/game');
            return;
        }

        $config = $this->googleConfig();
        if (!$config['enabled']) {
            $this->failAndBackHome('google_auth_disabled', 'Accesso con Google non disponibile: integrazione disattivata.');
            return;
        }
        if ($config['client_id'] === '' || $config['client_secret'] === '') {
            $this->failAndBackHome('google_auth_not_configured', 'Accesso con Google non configurato. Contatta lo staff.');
            return;
        }

        $request = RequestData::fromGlobals();
        $mode = strtolower(trim((string) $request->query('mode', 'signin')));
        if ($mode !== 'signup') {
            $mode = 'signin';
        }

        $state = $this->randomState();
        SessionStore::set('oauth_google_state', $state);
        SessionStore::set('oauth_google_mode', $mode);

        $params = [
            'client_id' => $config['client_id'],
            'redirect_uri' => $config['redirect_uri'],
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'access_type' => 'online',
            'include_granted_scopes' => 'true',
            'prompt' => 'select_account',
            'state' => $state,
        ];

        Redirect::url(self::AUTH_ENDPOINT . '?' . http_build_query($params));
    }

    public function callback(): void
    {
        $config = $this->googleConfig();
        if (!$config['enabled'] || $config['client_id'] === '' || $config['client_secret'] === '') {
            $this->failAndBackHome('google_auth_not_configured', 'Accesso con Google non configurato. Contatta lo staff.');
            return;
        }

        $request = RequestData::fromGlobals();
        $oauthError = trim((string) $request->query('error', ''));
        if ($oauthError !== '') {
            $this->failAndBackHome('google_oauth_denied', 'Accesso con Google annullato.');
            return;
        }

        $state = trim((string) $request->query('state', ''));
        $storedState = trim((string) SessionStore::get('oauth_google_state'));
        $mode = strtolower(trim((string) (SessionStore::get('oauth_google_mode') ?? 'signin')));
        if ($mode !== 'signup') {
            $mode = 'signin';
        }
        SessionStore::delete('oauth_google_state');
        SessionStore::delete('oauth_google_mode');

        if ($state === '' || $storedState === '' || !hash_equals($storedState, $state)) {
            $this->failAndBackHome('google_oauth_state_invalid', 'Sessione Google non valida. Riprova.');
            return;
        }

        $code = trim((string) $request->query('code', ''));
        if ($code === '') {
            $this->failAndBackHome('google_oauth_code_missing', 'Autorizzazione Google non valida. Riprova.');
            return;
        }

        $tokenPayload = $this->exchangeCodeForToken($code, $config);
        $accessToken = trim((string) ($tokenPayload['access_token'] ?? ''));
        if ($accessToken === '') {
            $this->failAndBackHome('google_oauth_token_invalid', 'Impossibile completare il login con Google.');
            return;
        }

        $profile = $this->fetchGoogleProfile($accessToken);
        $googleSub = trim((string) ($profile['sub'] ?? ''));
        $googleEmail = strtolower(trim((string) ($profile['email'] ?? '')));
        $googleEmailVerified = !empty($profile['email_verified']);
        $googleGivenName = trim((string) ($profile['given_name'] ?? ''));
        $googleName = trim((string) ($profile['name'] ?? ''));
        $googleAvatar = trim((string) ($profile['picture'] ?? ''));

        if ($googleSub === '' || $googleEmail === '' || !$googleEmailVerified) {
            $this->failAndBackHome('google_oauth_profile_invalid', 'Il profilo Google non ha fornito dati validi.');
            return;
        }

        try {
            if ($mode === 'signin') {
                $userId = $this->findLinkedUserFromGoogle(
                    $googleSub,
                    $googleEmail,
                    $googleAvatar,
                );
            } else {
                $userId = $this->findOrCreateUserFromGoogle(
                    $googleSub,
                    $googleEmail,
                    $googleGivenName !== '' ? $googleGivenName : $googleName,
                    $googleAvatar,
                );
            }
        } catch (\Throwable $e) {
            $this->trace('[GoogleAuth] user resolve failed', ['mode' => $mode, 'error' => $e->getMessage()]);
            $this->failAndBackHome('google_auth_user_failed', 'Impossibile associare il tuo account Google.');
            return;
        }

        if ($userId <= 0) {
            if ($mode === 'signin') {
                $this->failAndBackHome(
                    'google_auth_account_not_found',
                    'Nessun account associato a questo profilo Google. Crea prima un account con email/password.',
                );
                return;
            }
            $this->failAndBackHome('google_auth_user_missing', 'Impossibile accedere con Google.');
            return;
        }

        $signin = new AuthSigninService($this->db, $this->logger);
        $result = $signin->signinByUserId($userId, false);
        $status = (string) ($result['status'] ?? '');

        if ($status === 'ok') {
            $this->clearFlash();
            Redirect::url('/game');
            return;
        }

        if ($status === 'character_required') {
            $this->flash(
                'google_character_required',
                'Accesso Google completato. Crea ora il tuo personaggio.',
                'info',
            );
            SessionStore::set('google_auth_open_create_character', 1);
            $prefillName = trim((string) ($googleGivenName !== '' ? $googleGivenName : $googleName));
            if ($prefillName !== '') {
                SessionStore::set('google_auth_prefill_name', $prefillName);
            }
            Redirect::url('/');
            return;
        }

        if ($status === 'character_select_required') {
            $this->flash(
                'google_character_select_required',
                'Accesso Google completato. Seleziona il personaggio con cui entrare.',
                'info',
            );
            SessionStore::set('google_auth_open_select_character', 1);
            SessionStore::set('google_auth_select_characters', is_array($result['characters'] ?? null) ? $result['characters'] : []);
            Redirect::url('/');
            return;
        }

        if ($status === 'inactive') {
            $this->failAndBackHome('google_auth_inactive', 'Il tuo account non risulta attivo.');
            return;
        }

        if ($status === 'banned') {
            $this->failAndBackHome('google_auth_banned', 'Il tuo account risulta bannato.');
            return;
        }

        $this->failAndBackHome('google_auth_signin_failed', 'Login con Google non riuscito.');
    }

    private function findLinkedUserFromGoogle(string $googleSub, string $email, string $avatarUrl): int
    {
        $userBySub = $this->findUserByGoogleSub($googleSub);
        if (!empty($userBySub)) {
            $userId = (int) ($userBySub->id ?? 0);
            if ($userId > 0) {
                $this->syncGoogleLink($userId, $googleSub, $email, $avatarUrl);
                return $userId;
            }
        }

        $userByEmail = $this->findUserByEmail($email);
        if (!empty($userByEmail)) {
            $userId = (int) ($userByEmail->id ?? 0);
            if ($userId > 0) {
                $existingSub = '';
                if ($this->usersColumnExists('google_sub')) {
                    $existingSub = trim((string) ($userByEmail->google_sub ?? ''));
                }
                if ($existingSub !== '' && $existingSub !== $googleSub) {
                    throw new \RuntimeException('Google identity already linked to another profile');
                }
                $this->syncGoogleLink($userId, $googleSub, $email, $avatarUrl);
                return $userId;
            }
        }

        return 0;
    }

    private function findOrCreateUserFromGoogle(string $googleSub, string $email, string $suggestedName, string $avatarUrl): int
    {
        $existingUserId = $this->findLinkedUserFromGoogle($googleSub, $email, $avatarUrl);
        if ($existingUserId > 0) {
            return $existingUserId;
        }

        $passwordSeed = '';
        try {
            $passwordSeed = bin2hex(random_bytes(32));
        } catch (\Throwable $e) {
            $passwordSeed = sha1(uniqid('google_pass_', true));
        }
        $passwordHash = password_hash($passwordSeed, PASSWORD_DEFAULT);

        $columns = [
            'email',
            'password',
            'gender',
            'is_administrator',
            'is_moderator',
            'is_master',
            'date_actived',
            'date_last_pass',
        ];
        $valuesSql = [
            'AES_ENCRYPT(?, ?)',
            '?',
            '1',
            '0',
            '0',
            '0',
            'NOW()',
            'NOW()',
        ];
        $params = [$email, $this->cryptKey(), $passwordHash];

        if ($this->usersColumnExists('google_sub')) {
            $columns[] = 'google_sub';
            $valuesSql[] = '?';
            $params[] = $googleSub;
        }
        if ($this->usersColumnExists('google_avatar')) {
            $columns[] = 'google_avatar';
            $valuesSql[] = '?';
            $params[] = ($avatarUrl !== '' ? $avatarUrl : null);
        }
        if ($this->usersColumnExists('username')) {
            $username = trim($suggestedName);
            if ($username === '') {
                $username = 'Google User';
            }
            $columns[] = 'username';
            $valuesSql[] = '?';
            $params[] = $username;
        }

        $sql = 'INSERT INTO users (`' . implode('`, `', $columns) . '`) VALUES (' . implode(', ', $valuesSql) . ')';
        $this->db->executePrepared($sql, $params);
        $userId = (int) $this->db->lastInsertId();

        if ($userId > 0) {
            $this->syncGoogleLink($userId, $googleSub, $email, $avatarUrl);
        }

        return $userId;
    }

    private function syncGoogleLink(int $userId, string $googleSub, string $email, string $avatarUrl): void
    {
        if ($userId <= 0) {
            return;
        }

        $sets = [
            'email = AES_ENCRYPT(?, ?)',
            'date_actived = IFNULL(date_actived, NOW())',
        ];
        $params = [$email, $this->cryptKey()];

        if ($this->usersColumnExists('google_sub')) {
            $sets[] = 'google_sub = ?';
            $params[] = $googleSub;
        }
        if ($this->usersColumnExists('google_avatar')) {
            $sets[] = 'google_avatar = ?';
            $params[] = ($avatarUrl !== '' ? $avatarUrl : null);
        }

        $params[] = $userId;
        $this->db->executePrepared(
            'UPDATE users SET ' . implode(', ', $sets) . '
             WHERE id = ?',
            $params,
        );
    }

    private function findUserByGoogleSub(string $googleSub)
    {
        if ($googleSub === '' || !$this->usersColumnExists('google_sub')) {
            return null;
        }

        return $this->firstPrepared(
            'SELECT id, google_sub
             FROM users
             WHERE google_sub = ?
             LIMIT 1',
            [$googleSub],
        );
    }

    private function findUserByEmail(string $email)
    {
        if ($email === '') {
            return null;
        }

        $selectGoogleSub = $this->usersColumnExists('google_sub') ? 'google_sub' : 'NULL AS google_sub';

        return $this->firstPrepared(
            'SELECT id, ' . $selectGoogleSub . '
             FROM users
             WHERE email = AES_ENCRYPT(?, ?)
             LIMIT 1',
            [strtolower($email), $this->cryptKey()],
        );
    }

    private function exchangeCodeForToken(string $code, array $config): array
    {
        $body = http_build_query([
            'code' => $code,
            'client_id' => (string) ($config['client_id'] ?? ''),
            'client_secret' => (string) ($config['client_secret'] ?? ''),
            'redirect_uri' => (string) ($config['redirect_uri'] ?? ''),
            'grant_type' => 'authorization_code',
        ]);

        $raw = $this->httpRequest(
            self::TOKEN_ENDPOINT,
            'POST',
            $body,
            [
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json',
            ],
        );

        if ($raw === null || trim($raw) === '') {
            return [];
        }

        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            return [];
        }

        return $payload;
    }

    private function fetchGoogleProfile(string $accessToken): array
    {
        if ($accessToken === '') {
            return [];
        }

        $raw = $this->httpRequest(
            self::USERINFO_ENDPOINT,
            'GET',
            null,
            [
                'Authorization: Bearer ' . $accessToken,
                'Accept: application/json',
            ],
        );

        if ($raw === null || trim($raw) === '') {
            return [];
        }

        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            return [];
        }

        return $payload;
    }

    private function httpRequest(string $url, string $method = 'GET', ?string $body = null, array $headers = []): ?string
    {
        $method = strtoupper(trim($method));
        if ($method !== 'POST') {
            $method = 'GET';
        }

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch === false) {
                return null;
            }

            $options = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 20,
                CURLOPT_FOLLOWLOCATION => false,
            ];

            if (!empty($headers)) {
                $options[CURLOPT_HTTPHEADER] = $headers;
            }
            if ($method === 'POST') {
                $options[CURLOPT_POST] = true;
                $options[CURLOPT_POSTFIELDS] = (string) $body;
            }

            curl_setopt_array($ch, $options);
            $response = curl_exec($ch);
            $error = curl_error($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);

            if ($response === false || $error !== '') {
                $this->trace('[GoogleAuth] HTTP request error', ['error' => $error, 'url' => $url]);
                return null;
            }
            if ($status < 200 || $status >= 300) {
                $this->trace('[GoogleAuth] HTTP request non-2xx', ['status' => $status, 'url' => $url]);
            }

            return (string) $response;
        }

        $headerLine = '';
        if (!empty($headers)) {
            $headerLine = implode("\r\n", $headers) . "\r\n";
        }

        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => $headerLine,
                'content' => $method === 'POST' ? (string) $body : '',
                'ignore_errors' => true,
                'timeout' => 20,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);
        if (!is_string($response)) {
            return null;
        }

        return $response;
    }
}
