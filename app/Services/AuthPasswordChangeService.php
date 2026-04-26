<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database\DbAdapterFactory;
use Core\Database\DbAdapterInterface;
use Core\Http\AppError;
use Core\Http\RequestData;

use Core\Logging\LoggerInterface;
use Core\SessionStore;

class AuthPasswordChangeService
{
    /** @var DbAdapterInterface */
    private $db;
    /** @var LoggerInterface */
    private $logger;

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

    private function getSessionValue($key)
    {
        return SessionStore::get($key);
    }

    private function setSessionValue($key, $value): void
    {
        SessionStore::set($key, $value);
    }

    private function getSessionUserId(): int
    {
        return (int) $this->getSessionValue('user_id');
    }

    private function trace($message, $context = false): void
    {
        $this->logger->trace($message, $context);
    }

    private function failValidation(string $message): void
    {
        throw AppError::validation($message);
    }

    private function requestData($default = null)
    {
        $request = RequestData::fromGlobals();
        return $request->postJson('data', $default, false);
    }

    public function changePassword($payload = null): void
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);

        if ($payload === null) {
            $data = $this->requestData((object) []);
            if ($data === null) {
                $data = (object) [];
            }
        } else {
            if (is_array($payload)) {
                $data = (object) $payload;
            } elseif (is_object($payload)) {
                $data = $payload;
            } else {
                $data = (object) [];
            }
        }

        $this->checkOldPassword($data);
        if ($data->old_password == $data->new_password) {
            $this->failValidation('Sistema: la vecchia password è uguale alla nuova password');
        }

        if ($data->new_password != $data->rewrite_new_password) {
            $this->failValidation('Sistema: la nuova password e la conferma della nuova password non corrispondono.');
        }

        $hash = password_hash($data->new_password, PASSWORD_DEFAULT);
        $this->execPrepared(
            'UPDATE users SET
                password = ?,
                date_last_pass = NOW(),
                session_version = IFNULL(session_version, 1) + 1,
                date_sessions_revoked = NOW()
             WHERE id = ?',
            [$hash, (int) $data->user_id],
        );

        $sessionUserId = $this->getSessionUserId();
        if ($sessionUserId > 0 && $sessionUserId === (int) $data->user_id) {
            $row = $this->firstPrepared(
                'SELECT session_version FROM users WHERE id = ?',
                [(int) $data->user_id],
            );
            if (!empty($row)) {
                $this->setSessionValue('user_session_version', $row->session_version);
            }
        }
    }

    private function checkOldPassword($data)
    {
        if (null == $data->old_password) {
            $this->failValidation('Sistema: non hai inserito la tua vecchia password verifica di aver compilato il form correttamente');
        }

        $user = $this->firstPrepared(
            'SELECT id, password FROM users WHERE id = ?',
            [(int) $data->user_id],
        );
        if (null == $user) {
            $this->failValidation('Sistema: utente non trovato');
        }

        if (true === $this->verifyPassword($data->old_password, $user->password, $user->id)) {
            return $user;
        }

        $this->failValidation('Sistema: la vecchia password non corrisponde all\'interno dei nostri sistemi');
        return null;
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


