<?php

declare(strict_types=1);

namespace App\Services;

use Core\AuditLogService;
use Core\Database\DbAdapterFactory;
use Core\Database\DbAdapterInterface;
use Core\Dates;
use Core\Http\ApiResponse;
use Core\Http\RequestContext;
use Core\Http\RequestData;
use Core\Http\ResponseEmitter;
use Core\Logging\LegacyLoggerAdapter;
use Core\Logging\LoggerInterface;
use Core\RateLimiter;
use Core\Router;
use Core\SessionStore;
use SysConfigs;

class AuthSigninService
{
    /** @var DbAdapterInterface */
    private $db;
    /** @var LoggerInterface */
    private $logger;
    /** @var bool|null */
    private $superuserColumnExists = null;

    public function __construct(DbAdapterInterface $db = null, LoggerInterface $logger = null)
    {
        $this->db = $db ?: DbAdapterFactory::createFromConfig();
        $this->logger = $logger ?: new LegacyLoggerAdapter();
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
        if (defined('DB') && isset(DB['crypt_key'])) {
            return (string) DB['crypt_key'];
        }

        return '';
    }

    private function getSessionValue($key)
    {
        return SessionStore::get($key);
    }

    private function setSessionValue($key, $value): void
    {
        SessionStore::set($key, $value);
    }

    private function getServerValue(string $key, $default = null)
    {
        $server = RequestContext::server();
        return array_key_exists($key, $server) ? $server[$key] : $default;
    }

    private function getSessionUserId(): int
    {
        return (int) $this->getSessionValue('user_id');
    }

    private function getSessionCharacterId(): int
    {
        return (int) $this->getSessionValue('character_id');
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
    public function signin()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);

        $payload = $this->requestData((object) []);
        if ($payload === null) {
            $payload = (object) [];
        }

        $email = '';
        if (isset($payload->email)) {
            $email = strtolower(trim((string) $payload->email));
        }
        $ip = (string) $this->getServerValue('REMOTE_ADDR', '0.0.0.0');
        $identity = 'signin:' . $ip . ':' . $email;
        $rule = RateLimiter::getRule('auth_signin', 10, 300, 1, 100, 10, 3600);
        $rate = RateLimiter::hit('auth.signin', $rule['limit'], $rule['window'], $identity);
        if (empty($rate['allowed'])) {
            ResponseEmitter::emit(ApiResponse::json([
                'error_auth' => [
                    'title' => 'Troppi tentativi di accesso',
                    'body' => 'Hai effettuato troppi tentativi. Riprova tra ' . (int) $rate['retry_after'] . ' secondi.',
                ],
            ]));

            return;
        }

        $user = $this->checkUser($payload);
        if (false == $user) {
            ResponseEmitter::emit(ApiResponse::json([
                'error_auth' => [
                    'title' => 'Indirizzo email e/o Password errati',
                    'body' => 'L\'indirizzo Email e/o la Password inseriti non sono corretti, ti preghiamo di riprovare o di contattare l\'assistanza.',
                ],
            ]));

            return;
        }

        RateLimiter::clear('auth.signin', $identity);
        $result = $this->finalizeSigninForUser($user);
        $this->emitSigninResult($result);
    }

    public function signinByUserId(int $userId, bool $emitResponse = false): array
    {
        $user = $this->checkUserById($userId);
        if (false === $user) {
            $result = ['status' => 'invalid_user'];
            if ($emitResponse) {
                $this->emitSigninResult($result);
            }
            return $result;
        }

        $result = $this->finalizeSigninForUser($user);
        if ($emitResponse) {
            $this->emitSigninResult($result);
        }
        return $result;
    }

    public function listSigninCharactersForCurrentUser(bool $emitResponse = false): array
    {
        $userId = $this->getSessionUserId();
        if ($userId <= 0) {
            $result = ['status' => 'invalid_user', 'characters' => []];
            if ($emitResponse) {
                $this->emitSigninResult($result);
            }
            return $result;
        }

        $user = $this->checkUserById($userId);
        if (false === $user) {
            $result = ['status' => 'invalid_user', 'characters' => []];
            if ($emitResponse) {
                $this->emitSigninResult($result);
            }
            return $result;
        }

        $characters = $this->sanitizeCharactersForSelection($this->listUserCharacters((int) $user->id));
        $result = [
            'status' => 'character_select_required',
            'user' => $user,
            'characters' => $characters,
            'max_characters' => $this->getMultiCharacterMaxPerUser(),
        ];

        if ($emitResponse) {
            $this->emitSigninResult($result);
        }
        return $result;
    }

    public function selectSigninCharacter(int $characterId, bool $emitResponse = false): array
    {
        $userId = $this->getSessionUserId();
        if ($userId <= 0 || $characterId <= 0) {
            $result = ['status' => 'invalid_user'];
            if ($emitResponse) {
                $this->emitSigninResult($result);
            }
            return $result;
        }

        $user = $this->checkUserById($userId);
        if (false === $user) {
            $result = ['status' => 'invalid_user'];
            if ($emitResponse) {
                $this->emitSigninResult($result);
            }
            return $result;
        }

        $character = $this->firstPrepared(
            'SELECT * FROM characters
             WHERE id = ?
               AND user_id = ?
               AND (delete_scheduled_at IS NULL OR delete_scheduled_at > NOW())
             LIMIT 1',
            [$characterId, $userId],
        );

        if (empty($character)) {
            $result = ['status' => 'character_not_found'];
            if ($emitResponse) {
                $this->emitSigninResult($result);
            }
            return $result;
        }

        $result = $this->finalizeCharacterSigninForUser($user, $character);
        if ($emitResponse) {
            $this->emitSigninResult($result);
        }
        return $result;
    }

    public function signout()
    {
        $userId = $this->getSessionUserId();
        if ($userId > 0) {
            $this->setLastSignout('user', $userId);
        }

        $characterId = $this->getSessionCharacterId();
        if ($characterId > 0) {
            $this->setLastSignout('character', $characterId);
        }

        AuditLogService::writeFromUrl(Router::currentUri(), $userId > 0 ? $userId : null);
        SessionStore::destroy();
    }

    private function setSessionsUser($user): void
    {
        SessionStore::regenerate();
        $this->setSessionValue('user_id', $user->id);
        $this->setSessionValue('user_gender', $user->gender);
        $this->setSessionValue('user_email', $user->email);
        $this->setSessionValue('user_is_administrator', $user->is_administrator);
        $this->setSessionValue('user_is_superuser', isset($user->is_superuser) ? ((int) $user->is_superuser) : 0);
        $this->setSessionValue('user_is_moderator', isset($user->is_moderator) ? $user->is_moderator : 0);
        $this->setSessionValue('user_is_master', isset($user->is_master) ? $user->is_master : 0);
        $this->setSessionValue('user_session_version', isset($user->session_version) ? $user->session_version : 1);
    }

    private function hasSuperuserColumn(): bool
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

    private function setSessionsCharacter($character): void
    {
        $this->setSessionValue('character_id', $character->id);
        $this->setSessionValue('character_gender', $character->gender);
        $this->setSessionValue('character_socialstatus', $character->socialstatus_id);
        $this->setSessionValue('character_last_location', $character->last_location);
        $this->setSessionValue('character_last_map', $character->last_map);
    }

    private function shouldResumeLastPositionOnSignin(): bool
    {
        $rows = $this->fetchPrepared(
            'SELECT `key`, `value`
             FROM sys_configs
             WHERE `key` IN (?, ?)',
            ['presence_resume_last_position_on_signin', 'presence_restore_last_position_on_signin'],
        );

        if (empty($rows)) {
            return false;
        }

        foreach ($rows as $row) {
            $value = isset($row->value) ? (string) $row->value : '0';
            if ($value === '1') {
                return true;
            }
        }

        return false;
    }

    private function normalizeSigninCharacterPosition(object $character): object
    {
        $characterId = isset($character->id) ? (int) $character->id : 0;
        if ($characterId <= 0) {
            return $character;
        }

        if ($this->shouldResumeLastPositionOnSignin()) {
            return $character;
        }

        $this->execPrepared(
            'UPDATE characters SET
                last_map = NULL,
                last_location = NULL
             WHERE id = ?',
            [$characterId],
        );

        $character->last_map = null;
        $character->last_location = null;
        return $character;
    }

    private function getConfigInt(string $key, int $fallback): int
    {
        $row = $this->firstPrepared(
            'SELECT `value`
             FROM sys_configs
             WHERE `key` = ?
             LIMIT 1',
            [$key],
        );
        if (empty($row) || !isset($row->value)) {
            return $fallback;
        }
        if (!is_numeric((string) $row->value)) {
            return $fallback;
        }
        return (int) $row->value;
    }

    private function isMultiCharacterEnabled(): bool
    {
        return $this->getConfigInt('multi_character_enabled', 0) === 1;
    }

    private function getMultiCharacterMaxPerUser(): int
    {
        $max = $this->getConfigInt('multi_character_max_per_user', 1);
        if ($max < 1) {
            $max = 1;
        }
        if ($max > 10) {
            $max = 10;
        }
        return $max;
    }

    private function listUserCharacters(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }
        $rows = $this->fetchPrepared(
            'SELECT id, user_id, name, surname, gender, availability, socialstatus_id, last_map, last_location, date_last_signin
             FROM characters
             WHERE user_id = ?
               AND (delete_scheduled_at IS NULL OR delete_scheduled_at > NOW())
             ORDER BY id ASC',
            [$userId],
        );

        if (!is_array($rows)) {
            return [];
        }

        return $rows;
    }

    private function sanitizeCharactersForSelection(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $id = isset($row->id) ? (int) $row->id : 0;
            if ($id <= 0) {
                continue;
            }
            $out[] = (object) [
                'id' => $id,
                'name' => isset($row->name) ? (string) $row->name : '',
                'surname' => isset($row->surname) ? (string) $row->surname : '',
                'gender' => isset($row->gender) ? (int) $row->gender : 0,
                'availability' => isset($row->availability) ? (int) $row->availability : 0,
                'last_map' => isset($row->last_map) ? (int) $row->last_map : 0,
                'last_location' => isset($row->last_location) ? (int) $row->last_location : 0,
                'date_last_signin' => isset($row->date_last_signin) ? (string) $row->date_last_signin : '',
            ];
        }
        return $out;
    }

    private function finalizeCharacterSigninForUser($user, object $character): array
    {
        $character = $this->normalizeSigninCharacterPosition($character);
        $this->setSessionsCharacter($character);
        $this->setConfigsSessions((new SysConfigs())->list(false)['dataset']);

        if (!empty($character->id)) {
            $this->setLastSignin('character', (int) $character->id);
        }

        AuditLogService::writeFromUrl(Router::currentUri(), ['user' => $user, 'character' => $character]);

        return ['status' => 'ok', 'user' => $user, 'character' => $character];
    }

    private function setConfigsSessions($configs): void
    {
        foreach ($configs as $config) {
            $this->setSessionValue('config_' . $config->key, $config->value);
        }

        $currency = $this->firstPrepared(
            'SELECT name
             FROM currencies
             WHERE is_default = ?
               AND is_active = ?
             LIMIT 1',
            [1, 1],
        );
        if (!empty($currency) && isset($currency->name)) {
            $this->setSessionValue('config_money_name', $currency->name);
        }
    }

    private function setLastSignin($type = 'user', $id = null): void
    {
        if ($type === 'user') {
            $userId = ($id !== null) ? (int) $id : $this->getSessionUserId();
            if ($userId <= 0) {
                return;
            }
            $this->execPrepared(
                'UPDATE users SET
                    date_last_signin = NOW()
                 WHERE id = ?',
                [$userId],
            );
        } elseif ($type === 'character') {
            $characterId = ($id !== null) ? (int) $id : $this->getSessionCharacterId();
            if ($characterId <= 0) {
                return;
            }
            $this->execPrepared(
                'UPDATE characters SET
                    date_last_signin = NOW(),
                    date_last_seed = NOW()
                 WHERE id = ?',
                [$characterId],
            );
        }
    }

    private function setLastSignout($type = 'user', $id = null): void
    {
        if ($type === 'user') {
            $userId = ($id !== null) ? (int) $id : $this->getSessionUserId();
            if ($userId <= 0) {
                return;
            }
            $this->execPrepared(
                'UPDATE users SET
                    date_last_signout = NOW()
                 WHERE id = ?',
                [$userId],
            );
        } elseif ($type === 'character') {
            $characterId = ($id !== null) ? (int) $id : $this->getSessionCharacterId();
            if ($characterId <= 0) {
                return;
            }
            $this->execPrepared(
                'UPDATE characters SET
                    date_last_signout = NOW()
                 WHERE id = ?',
                [$characterId],
            );
        }
    }

    private function checkUser($data)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);

        $superuserSelect = $this->hasSuperuserColumn()
            ? 'is_superuser'
            : 'is_administrator AS is_superuser';

        $cryptKey = $this->cryptKey();
        $user = $this->firstPrepared(
            'SELECT id, CAST(AES_DECRYPT(email, ?) AS CHAR(255)) AS email, password, is_administrator, ' . $superuserSelect . ', is_moderator, is_master, gender, date_actived, date_last_pass, session_version FROM users '
            . 'WHERE email = AES_ENCRYPT(?, ?)',
            [$cryptKey, strtolower(trim((string) ($data->email ?? ''))), $cryptKey],
        );

        if (null == $user) {
            return false;
        }

        if (false == $this->verifyPassword($data->password, $user->password, $user->id)) {
            return false;
        }

        return $user;
    }

    private function checkUserById(int $userId)
    {
        if ($userId <= 0) {
            return false;
        }

        $superuserSelect = $this->hasSuperuserColumn()
            ? 'is_superuser'
            : 'is_administrator AS is_superuser';

        $cryptKey = $this->cryptKey();
        $user = $this->firstPrepared(
            'SELECT id, CAST(AES_DECRYPT(email, ?) AS CHAR(255)) AS email, password, is_administrator, ' . $superuserSelect . ', is_moderator, is_master, gender, date_actived, date_last_pass, session_version FROM users '
            . 'WHERE id = ? LIMIT 1',
            [$cryptKey, $userId],
        );

        if (null == $user) {
            return false;
        }

        return $user;
    }

    private function finalizeSigninForUser($user): array
    {
        if (empty($user) || empty($user->id)) {
            return ['status' => 'invalid_user'];
        }

        if (null == $user->date_actived) {
            return ['status' => 'inactive', 'user' => $user];
        }

        $ban = $this->checkBan($user->id);
        if (false != $ban) {
            return ['status' => 'banned', 'user' => $user, 'ban' => $ban];
        }

        $this->setSessionsUser($user);
        if (!empty($user->id)) {
            $this->setLastSignin('user', (int) $user->id);
        }

        $characters = $this->listUserCharacters((int) $user->id);
        if (empty($characters)) {
            return ['status' => 'character_required', 'user' => $user];
        }

        if ($this->isMultiCharacterEnabled() && count($characters) > 1) {
            return [
                'status' => 'character_select_required',
                'user' => $user,
                'characters' => $this->sanitizeCharactersForSelection($characters),
                'max_characters' => $this->getMultiCharacterMaxPerUser(),
            ];
        }

        $character = $characters[0];
        return $this->finalizeCharacterSigninForUser($user, $character);
    }

    private function emitSigninResult(array $result): void
    {
        $status = (string) ($result['status'] ?? '');

        if ($status === 'ok') {
            ResponseEmitter::emit(ApiResponse::json([
                'user' => $result['user'] ?? null,
                'character' => $result['character'] ?? null,
            ]));

            return;
        }

        if ($status === 'character_required') {
            $user = $result['user'] ?? null;
            $title = ($user && (int) ($user->gender ?? 1) === 0) ? 'Benvenuta nel portale!' : 'Benvenuto nel portale!';

            ResponseEmitter::emit(ApiResponse::json([
                'error_character' => [
                    'title' => $title,
                    'body' => 'Prima di continuare dovrai creare il tuo personaggio, continuiamo?',
                    'user' => $user,
                ],
            ]));

            return;
        }

        if ($status === 'character_select_required') {
            $user = $result['user'] ?? null;
            $characters = is_array($result['characters'] ?? null) ? $result['characters'] : [];

            ResponseEmitter::emit(ApiResponse::json([
                'error_character_select' => [
                    'title' => 'Seleziona il personaggio',
                    'body' => 'Scegli con quale personaggio vuoi accedere al gioco.',
                    'user' => $user,
                    'characters' => $characters,
                    'max_characters' => (int) ($result['max_characters'] ?? 1),
                ],
            ]));

            return;
        }

        if ($status === 'inactive') {
            ResponseEmitter::emit(ApiResponse::json([
                'error_auth' => [
                    'title' => 'Il tuo account non Ã¨ attivo',
                    'body' => 'Ci risulta che il tuo account non Ã¨ attivo, ti consigliamo di controllare nella tua casella di posta (anche in SPAM) e cliccare sul link di attivazione che il nostro sistema ti ha inoltrato al momento della registrazione.<br/><br/>Nel caso in cui non hai ricevuto la mail di attivazione puoi contattare il supporto.',
                ],
            ]));

            return;
        }

        if ($status === 'banned') {
            $ban = $result['ban'] ?? null;

            ResponseEmitter::emit(ApiResponse::json([
                'error_auth' => [
                    'title' => 'Il tuo account Ã¨ stato bannato',
                    'body' => 'Ci risulta che il tuo account Ã¨ bannato per il seguente motivo: <p class="lead">' . (($ban && isset($ban->motivation)) ? $ban->motivation : '') . '</p> In data: <p class="lead">' . Dates::datetimeHuman(($ban && isset($ban->date_start)) ? $ban->date_start : null) . '</p> terminerÃ  il: <p class="lead">' . (($ban && isset($ban->date_end) && null != $ban->date_end) ? Dates::datetimeHuman($ban->date_end) : 'Permanente') . '</p>',
                ],
            ]));

            return;
        }

        if ($status === 'character_not_found') {
            ResponseEmitter::emit(ApiResponse::json([
                'error_auth' => [
                    'title' => 'Personaggio non valido',
                    'body' => 'Il personaggio selezionato non e disponibile per questo account.',
                ],
            ]));
            return;
        }

        ResponseEmitter::emit(ApiResponse::json([
            'error_auth' => [
                'title' => 'Accesso non riuscito',
                'body' => 'Impossibile completare il login. Riprova tra qualche istante.',
            ],
        ]));
    }

    private function checkBan($user_id)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);

        $user = $this->firstPrepared(
            'SELECT id, banned_id, author_id, motivation, date_start, date_end FROM blacklist '
            . 'WHERE banned_id = ?
               AND date_start <= NOW()
               AND (date_end IS NULL OR date_end > NOW())
             ORDER BY id DESC
             LIMIT 1',
            [(int) $user_id],
        );

        if (null == $user) {
            return false;
        }

        return $user;
    }

    private function verifyPassword($password, $hash, $user_id = null)
    {
        $info = password_get_info($hash);
        if (!empty($info['algo'])) {
            if (password_verify($password, $hash)) {
                if (password_needs_rehash($hash, PASSWORD_DEFAULT) && !empty($user_id)) {
                    $this->upgradePasswordHash($user_id, $password);
                }
                return true;
            }
            return false;
        }

        if (hash_equals($hash, md5($password))) {
            if (!empty($user_id)) {
                $this->upgradePasswordHash($user_id, $password);
            }
            return true;
        }

        return false;
    }

    private function upgradePasswordHash($user_id, $password): void
    {
        $new_hash = password_hash($password, PASSWORD_DEFAULT);
        $this->execPrepared(
            'UPDATE users SET
                password = ?,
                date_last_pass = NOW()
             WHERE id = ?',
            [$new_hash, (int) $user_id],
        );
    }
}
