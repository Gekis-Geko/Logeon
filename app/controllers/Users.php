<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\AuthService;
use App\Services\UserService;
use Core\Http\ApiResponse;
use Core\Http\AppError;
use Core\Http\InputValidator;
use Core\Http\RequestData;
use Core\Http\ResponseEmitter;


use Core\Logging\LoggerInterface;
use Core\SessionStore;

class Users extends User
{
    /** @var LoggerInterface|null */
    private $logger = null;
    /** @var AuthService|null */
    private $authService = null;
    /** @var UserService|null */
    private $userService = null;

    public function setLogger(LoggerInterface $logger = null)
    {
        $this->logger = $logger;
        return $this;
    }

    public function setUserService(UserService $userService = null)
    {
        $this->userService = $userService;
        return $this;
    }

    public function setAuthService(AuthService $authService = null)
    {
        $this->authService = $authService;
        return $this;
    }

    private function logger(): LoggerInterface
    {
        if ($this->logger instanceof LoggerInterface) {
            return $this->logger;
        }

        $this->logger = \Core\AppContext::logger();
        return $this->logger;
    }

    private function userService(): UserService
    {
        if ($this->userService instanceof UserService) {
            return $this->userService;
        }

        $this->userService = new UserService();
        return $this->userService;
    }

    private function authService(): AuthService
    {
        if ($this->authService instanceof AuthService) {
            return $this->authService;
        }

        $this->authService = new AuthService();
        return $this->authService;
    }

    protected function trace($message, $context = false): void
    {
        $this->logger()->trace($message, $context);
    }

    private function failValidation($message, string $errorCode = '')
    {
        $message = (string) $message;
        if ($errorCode === '') {
            $errorCode = $this->resolveValidationErrorCode($message);
        }
        throw AppError::validation($message, [], $errorCode);
    }

    private function resolveValidationErrorCode(string $message): string
    {
        $map = [
            'Utente non valido' => 'user_invalid',
            'Non puoi rimuovere il ruolo admin dal tuo account attuale' => 'user_self_admin_remove_forbidden',
            'Non puoi rimuovere il ruolo admin da un account amministratore' => 'user_admin_remove_forbidden',
            'Operazione riservata al superuser' => 'user_superuser_required',
            'Non puoi modificare i permessi dell\'account superuser' => 'user_superuser_permissions_locked',
            'Non puoi disconnettere il tuo account dalla lista utenti' => 'user_self_disconnect_forbidden',
            'Funzione restrizione non disponibile. Manca la colonna users.is_restricted' => 'user_restriction_feature_unavailable',
            'Non puoi restringere il tuo account attuale' => 'user_self_restrict_forbidden',
            'Dati mancanti' => 'payload_missing',
            'Sistema: L\'indirizzo email inserito e obbligatorio.' => 'email_required',
            'Sistema: L\'indirizzo email inserito e gia presente nei nostri sistemi. Riprova con un altro indirizzo' => 'email_already_exists',
            'Sistema: Il genere dell\'utente deve essere inserito.' => 'gender_required',
        ];

        return $map[$message] ?? 'validation_error';
    }

    private function setSessionValue($key, $value): void
    {
        SessionStore::set($key, $value);
    }

    private function requestDataObject($default = null, $allowInvalidJson = true)
    {
        $request = RequestData::fromGlobals();
        return InputValidator::postJsonObject($request, 'data', true);
    }

    public function signin()
    {
        return AuthService::signin();
    }
    public function signup(): void
    {
        AuthService::signup();
    }
    public function signinCharactersList()
    {
        return AuthService::signinCharactersList(true);
    }
    public function signinCharacterSelect()
    {
        $data = $this->requestDataObject((object) [], true);
        $characterId = InputValidator::integer($data, 'character_id', 0);
        return AuthService::signinCharacterSelect($characterId, true);
    }
    public function signout()
    {
        return AuthService::signout();
    }
    public function resetPassword()
    {
        return AuthService::resetPassword();
    }
    public function resetPasswordConfirm()
    {
        return AuthService::resetPasswordConfirm();
    }

    private function requireAdmin()
    {
        \Core\AuthGuard::api()->requireAbility('settings.manage');
    }

    private function requireSuperAdmin(): void
    {
        if (!\Core\AppContext::authContext()->isAdmin()) {
            throw AppError::unauthorized('Operazione riservata agli amministratori');
        }
    }

    private function requireSuperuser(): void
    {
        $authContext = \Core\AppContext::authContext();

        if (!$this->userService()->isSuperuserFeatureAvailable()) {
            $this->requireSuperAdmin();
            return;
        }

        if ($authContext->isSuperuser()) {
            return;
        }

        $currentUserId = \Core\AuthGuard::api()->requireUser();
        $current = $this->userService()->getAdminUserById((int) $currentUserId);
        if (!empty($current) && (int) ($current->is_superuser ?? 0) === 1) {
            $this->setSessionValue('user_is_superuser', 1);
            return;
        }

        throw AppError::unauthorized('Operazione riservata al superuser');
    }

    private function requireAdminOrModerator(): void
    {
        if (!\Core\AppContext::authContext()->isAdmin() && !\Core\AppContext::authContext()->isModerator()) {
            throw AppError::unauthorized('Operazione riservata a moderatori e amministratori');
        }
    }

    public static function isRestricted($user_id)
    {
        return (new UserService())->isRestricted((int) $user_id);
    }

    public function adminList()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();
        $isSuperuser = \Core\AppContext::authContext()->isSuperuser();

        $post = $this->requestDataObject((object) [], true);
        $query = (isset($post->query) && is_object($post->query)) ? $post->query : (object) [];
        $search = InputValidator::firstString($query, ['search', 'email'], '');
        $status = InputValidator::string($query, 'status', 'all');
        $page = max(1, InputValidator::integer($post, 'page', 1));
        $results = max(1, InputValidator::integer($post, 'results', 20));
        $orderBy = InputValidator::string($post, 'orderBy', 'date_created|DESC');
        $list = $this->userService()->listAdminUsers(
            $search,
            $status,
            $page,
            $results,
            $orderBy,
            $isSuperuser,
        );

        ResponseEmitter::emit(ApiResponse::json([
            'properties' => [
                'query' => $list['query'],
                'page' => $list['page'],
                'results_page' => $list['results_page'],
                'orderBy' => $list['orderBy'],
                'tot' => $list['tot'],
            ],
            'dataset' => $list['dataset'],
        ]));
    }

    public function adminResetPassword()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();
        $this->requireSuperAdmin();

        $data = $this->requestDataObject((object) [], true);

        $user_id = InputValidator::positiveInt($data, 'user_id', 'Utente non valido', 'user_invalid');
        $user = $this->userService()->getAdminUserById($user_id);
        if (empty($user) || empty($user->email)) {
            $this->failValidation('Utente non valido');
        }

        $expires_minutes = 60;
        $token = $this->userService()->createPasswordResetToken((int) $user->id, $expires_minutes);

        $reset_url = 'https://' . APP['baseurl'] . '/reset-password/' . $token;
        $mess = "<h3>Recupero della password</h3>
        <p>Ciao! un amministratore ha avviato il recupero password per il tuo account.</p>
        <p>Per impostare una nuova password, clicca qui:</p>
        <p><a href=\"$reset_url\">$reset_url</a></p>
        <p>Il link scade tra $expires_minutes minuti.</p>
        <p>Lo Staff</p>";

        mail($user->email, 'Recupero della password', $mess);

        ResponseEmitter::emit(ApiResponse::json([
            'success' => true,
            'message' => 'Email di reset inviata',
        ]));
    }

    public function adminSetPermissions()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();
        $this->requireSuperuser();
        $currentUserId = \Core\AuthGuard::api()->requireUser();

        $data = $this->requestDataObject((object) [], true);

        $user_id = InputValidator::positiveInt($data, 'user_id', 'Utente non valido', 'user_invalid');
        $permissions = $this->userService()->normalizePermissionsHierarchy(
            InputValidator::boolean($data, 'is_administrator', false) ? 1 : 0,
            InputValidator::boolean($data, 'is_moderator', false) ? 1 : 0,
            InputValidator::boolean($data, 'is_master', false) ? 1 : 0,
        );

        $target = $this->userService()->getAdminUserById($user_id);
        if (empty($target)) {
            $this->failValidation('Utente non valido');
        }
        if ((int) ($target->is_superuser ?? 0) === 1) {
            $this->failValidation('Non puoi modificare i permessi dell\'account superuser');
        }

        if ((int) $currentUserId === $user_id && (int) $permissions['is_administrator'] !== 1) {
            $this->failValidation('Non puoi rimuovere il ruolo admin dal tuo account attuale');
        }
        if ((int) ($target->is_administrator ?? 0) === 1 && (int) $permissions['is_administrator'] !== 1) {
            $this->failValidation('Non puoi rimuovere il ruolo admin da un account amministratore');
        }

        $this->userService()->setAdminPermissions(
            $user_id,
            (int) $permissions['is_administrator'],
            (int) $permissions['is_moderator'],
            (int) $permissions['is_master'],
        );

        if ((int) $currentUserId === $user_id) {
            $this->setSessionValue('user_is_administrator', (int) $permissions['is_administrator']);
            $this->setSessionValue('user_is_superuser', (int) ($target->is_superuser ?? 0));
            $this->setSessionValue('user_is_moderator', (int) $permissions['is_moderator']);
            $this->setSessionValue('user_is_master', (int) $permissions['is_master']);
        }

        ResponseEmitter::emit(ApiResponse::json([
            'success' => true,
            'message' => 'Permessi aggiornati',
        ]));
    }

    public function adminDisconnect()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdminOrModerator();
        $currentUserId = \Core\AuthGuard::api()->requireUser();

        $data = $this->requestDataObject((object) [], true);

        $user_id = InputValidator::positiveInt($data, 'user_id', 'Utente non valido', 'user_invalid');
        if ((int) $currentUserId === $user_id) {
            $this->failValidation('Non puoi disconnettere il tuo account dalla lista utenti');
        }

        $target = $this->userService()->getAdminUserById($user_id);
        if (empty($target)) {
            $this->failValidation('Utente non valido');
        }

        $this->userService()->disconnectUserSessions($user_id);

        ResponseEmitter::emit(ApiResponse::json([
            'success' => true,
            'message' => 'Sessioni invalidated',
        ]));
    }

    public function adminSetRestriction()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();
        $currentUserId = \Core\AuthGuard::api()->requireUser();

        if (!$this->userService()->isRestrictionFeatureAvailable()) {
            $this->failValidation('Funzione restrizione non disponibile. Manca la colonna users.is_restricted');
        }

        $data = $this->requestDataObject((object) [], true);

        $user_id = InputValidator::positiveInt($data, 'user_id', 'Utente non valido', 'user_invalid');
        $is_restricted = InputValidator::boolean($data, 'is_restricted', false) ? 1 : 0;

        if ((int) $currentUserId === $user_id && $is_restricted === 1) {
            $this->failValidation('Non puoi restringere il tuo account attuale');
        }

        $target = $this->userService()->getAdminUserById($user_id);
        if (empty($target)) {
            $this->failValidation('Utente non valido');
        }

        $this->userService()->setUserRestriction($user_id, $is_restricted);

        ResponseEmitter::emit(ApiResponse::json([
            'success' => true,
            'dataset' => [
                'user_id' => $user_id,
                'is_restricted' => $is_restricted,
            ],
            'message' => 'Restrizione aggiornata',
        ]));
    }

    public function changePassword()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);

        $userId = \Core\AuthGuard::api()->requireUser();
        $data = $this->requestDataObject((object) [], true);
        if (empty((array) $data)) {
            $this->failValidation('Dati mancanti');
        }
        $data->user_id = $userId;
        $this->authService()->changePassword($data);

        ResponseEmitter::emit(ApiResponse::json([
            'success' => true,
        ]));
    }

    public function revokeSessions()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);

        $userId = \Core\AuthGuard::api()->requireUser();
        $sessionVersion = $this->userService()->revokeSessions((int) $userId);
        if ($sessionVersion !== null) {
            $this->setSessionValue('user_session_version', $sessionVersion);
        }

        ResponseEmitter::emit(ApiResponse::json([
            'success' => true,
        ]));
    }

    public function create()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);

        $data = $this->requestDataObject();
        $this->checkDataset($data);

        $password = (empty($data->password)) ? $this->userService()->generateRandomPassword() : $data->password;
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $this->userService()->createUser($data, $hash);

        return $this;
    }

    public static function sys_create()
    {
        (\Core\AppContext::logger())->trace('Richiamato il metodo SYS: ' . __METHOD__);
        (new UserService())->createSystemSeedUser();

        return;
    }

    public function update()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);

        $data = $this->requestDataObject();
        $this->checkDataset($data);
        $this->userService()->updateUser($data);

        return $this;
    }

    public function active()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);

        $data = $this->requestDataObject();
        $userId = InputValidator::positiveInt($data, 'id', 'Utente non valido', 'user_invalid');
        $this->userService()->setUserActive($userId, true);

        return $this;
    }

    public function deactive()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);

        $data = $this->requestDataObject();
        $userId = InputValidator::positiveInt($data, 'id', 'Utente non valido', 'user_invalid');
        $this->userService()->setUserActive($userId, false);

        return $this;
    }

    protected function checkDataset($dataset)
    {
        if (!is_object($dataset) || !isset($dataset->email)) {
            $this->failValidation('Sistema: L\'indirizzo email inserito e obbligatorio.');
        }
        if ($this->userService()->emailExists((string) $dataset->email)) {
            $this->failValidation('Sistema: L\'indirizzo email inserito e gia presente nei nostri sistemi. Riprova con un altro indirizzo');
        }

        if (!isset($dataset->gender)) {
            $this->failValidation('Sistema: Il genere dell\'utente deve essere inserito.');
        }
    }
}


