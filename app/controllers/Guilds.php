<?php

declare(strict_types=1);

use App\Models\Guild;
use App\Services\GuildAdminService;
use App\Services\GuildEventAdminService;
use App\Services\GuildRoleAdminService;
use App\Services\GuildService;
use App\Services\NotificationService;
use Core\CurrencyLogs;

use Core\Http\ApiResponse;
use Core\Http\AppError;
use Core\Http\InputValidator;
use Core\Http\RequestData;
use Core\Http\ResponseEmitter;

use Core\Logging\LoggerInterface;

class Guilds extends Guild
{
    /** @var LoggerInterface|null */
    private $logger = null;
    /** @var GuildService|null */
    private $guildService = null;
    /** @var GuildAdminService|null */
    private $guildAdminService = null;
    /** @var GuildRoleAdminService|null */
    private $guildRoleAdminService = null;
    /** @var GuildEventAdminService|null */
    private $guildEventAdminService = null;
    /** @var NotificationService|null */
    private $notifService = null;

    public function setLogger(LoggerInterface $logger = null)
    {
        $this->logger = $logger;
        return $this;
    }

    public function setGuildService(GuildService $guildService = null)
    {
        $this->guildService = $guildService;
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

    protected function trace($message, $context = false): void
    {
        $this->logger()->trace($message, $context);
    }

    private function guildService(): GuildService
    {
        if ($this->guildService instanceof GuildService) {
            return $this->guildService;
        }

        $this->guildService = new GuildService();
        return $this->guildService;
    }

    private function notifService(): NotificationService
    {
        if ($this->notifService instanceof NotificationService) {
            return $this->notifService;
        }

        $this->notifService = new NotificationService();
        return $this->notifService;
    }

    private function failValidation($message, string $errorCode = '')
    {
        $message = (string) $message;
        if ($errorCode === '') {
            $errorCode = $this->resolveValidationErrorCode($message);
        }
        throw AppError::validation($message, [], $errorCode);
    }

    private function failUnauthorized($message = 'Operazione non autorizzata', string $errorCode = '')
    {
        $message = (string) $message;
        if ($errorCode === '') {
            $errorCode = $this->resolveUnauthorizedErrorCode($message);
        }
        throw AppError::unauthorized($message, [], $errorCode);
    }

    private function failNotFound($message = 'Risorsa non trovata', string $errorCode = '')
    {
        $message = (string) $message;
        if ($errorCode === '') {
            $errorCode = $this->resolveNotFoundErrorCode($message);
        }
        throw AppError::notFound($message, [], $errorCode);
    }

    private function resolveValidationErrorCode(string $message): string
    {
        $map = [
            'Gilda non valida' => 'guild_invalid',
            'Gilda non disponibile' => 'guild_unavailable',
            'Puoi appartenere al massimo a due gilde' => 'guild_membership_limit_reached',
            'Sei gia membro della gilda' => 'guild_already_member',
            'Requisiti non soddisfatti' => 'guild_requirements_not_met',
            'Candidatura gia inviata' => 'guild_application_already_pending',
            'Dati non validi' => 'payload_invalid',
            'Candidatura non valida' => 'guild_application_invalid',
            'Il personaggio ha gia due gilde' => 'guild_target_membership_limit_reached',
            'Il personaggio e gia membro' => 'guild_target_already_member',
            'Ruolo non valido' => 'guild_role_invalid',
            'La gilda ha gia un capo' => 'guild_leader_already_exists',
            'Membro non valido' => 'guild_member_invalid',
            'Non puoi richiedere l\'espulsione del capo' => 'guild_kick_leader_not_allowed',
            'Richiesta non valida' => 'guild_kick_request_invalid',
            'Nessuno stipendio disponibile' => 'guild_salary_not_available',
            'Stipendio gia riscosso questo mese' => 'guild_salary_already_claimed',
            'Tipo requisito non valido' => 'guild_requirement_type_invalid',
            'Valore requisito non valido' => 'guild_requirement_value_invalid',
            'Requisito non valido' => 'guild_requirement_invalid',
        ];

        return $map[$message] ?? 'validation_error';
    }

    private function resolveUnauthorizedErrorCode(string $message): string
    {
        $map = [
            'Operazione non autorizzata' => 'guild_forbidden',
            'Gilda non visibile' => 'guild_not_visible',
            'Accesso non autorizzato' => 'guild_forbidden',
            'Solo il capo puo gestire le candidature' => 'guild_leader_required',
            'Solo il capo puo gestire i ruoli' => 'guild_leader_required',
            'Solo il capo puo gestire i requisiti' => 'guild_leader_required',
            'Non puoi gestire questo ruolo' => 'guild_role_scope_forbidden',
            'Solo il capo puo approvare' => 'guild_leader_required',
            'Solo il capo puo espellere' => 'guild_leader_required',
            'Solo il capo puo promuovere' => 'guild_leader_required',
            'Non sei membro della gilda' => 'guild_membership_required',
            'Solo il capo puo pubblicare' => 'guild_leader_required',
            'Solo il capo puo eliminare' => 'guild_leader_required',
            'Solo il capo puo creare eventi' => 'guild_leader_required',
            'Solo il capo puo eliminare eventi' => 'guild_leader_required',
        ];

        return $map[$message] ?? 'unauthorized';
    }

    private function resolveNotFoundErrorCode(string $message): string
    {
        $map = [
            'Gilda non trovata' => 'guild_not_found',
        ];

        return $map[$message] ?? 'not_found';
    }

    private function requestDataObject()
    {
        $request = RequestData::fromGlobals();
        return InputValidator::postJsonObject($request, 'data', true);
    }

    private function requireAdmin()
    {
        \Core\AuthGuard::api()->requireAbility('settings.manage');
    }

    private function requireCharacter()
    {
        return \Core\AuthGuard::api()->requireCharacter();
    }

    public function list($echo = true)
    {
        $this->requireAdmin();
        return parent::list($echo);
    }

    public function create()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();

        $data = $this->requestDataObject();
        $this->guildService()->createGuild($data);

        return $this;
    }

    public function update()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();

        $data = $this->requestDataObject();
        $this->guildService()->updateGuild($data);

        return $this;
    }

    public function delete($operator = '=')
    {
        $this->requireAdmin();
        return parent::delete($operator);
    }

    public function publicList()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);

        $character_id = $this->requireCharacter();
        $guilds = $this->guildService()->listPublicGuildsForCharacter((int) $character_id);

        ResponseEmitter::emit(ApiResponse::json([
            'dataset' => $guilds,
        ]));
    }

    public function characterGuilds()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);

        $data = $this->requestDataObject();
        $characterId = (int) ($data->character_id ?? 0);

        if ($characterId <= 0) {
            $characterId = (int) \Core\AuthGuard::api()->requireCharacter();
        }

        $guilds = $this->guildService()->getCharacterGuildsForProfile($characterId);

        ResponseEmitter::emit(\Core\Http\ApiResponse::json([
            'dataset' => $guilds,
        ]));
    }

    public function get($field = '', $value = null, $operator = '=', $order_fields = '', $echo = true)
    {
        if ($field !== '' || $value !== null || $order_fields !== '' || $echo !== true) {
            $this->requireAdmin();
            return parent::get($field, $value, $operator, $order_fields, $echo);
        }

        $this->trace('Richiamato il metodo: ' . __METHOD__);

        $character_id = $this->requireCharacter();
        $data = $this->requestDataObject();
        $guild_id = isset($data->id) ? (int) $data->id : null;
        if (empty($guild_id)) {
            $this->failValidation('Gilda non valida');
        }

        $guild = $this->guildService()->getGuild($guild_id);
        if (empty($guild)) {
            $this->failNotFound('Gilda non trovata');
        }

        $membership = $this->guildService()->getMembership($character_id, $guild_id);
        $is_member = !empty($membership);

        if ((int) $guild->is_visible !== 1 && !$is_member && !\Core\AppContext::authContext()->isAdmin()) {
            $this->failUnauthorized('Gilda non visibile');
        }

        $character = $this->guildService()->getCharacter($character_id);
        $requirements = $this->guildService()->getRequirements($guild_id);
        $reqCheck = $this->guildService()->checkRequirements($requirements, $character);

        $memberCount = $this->guildService()->getMemberCount($character_id);
        $can_apply = (!$is_member && $memberCount < 2 && $reqCheck['allowed']);

        $can_claim = false;
        if ($membership && isset($membership->monthly_salary)) {
            $can_claim = $this->guildService()->canClaimSalary($membership->salary_last_claim_at);
        }

        ResponseEmitter::emit(ApiResponse::json([
            'guild' => $guild,
            'requirements' => $requirements,
            'requirements_missing' => $reqCheck['missing'],
            'is_member' => $is_member,
            'membership' => $membership,
            'membership_count' => $memberCount,
            'can_apply' => $can_apply,
            'can_claim_salary' => $can_claim,
        ]));
    }

    public function apply()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);

        $character_id = $this->requireCharacter();
        $data = $this->requestDataObject();
        $guild_id = isset($data->guild_id) ? (int) $data->guild_id : null;
        $message = isset($data->message) ? trim($data->message) : null;

        if (empty($guild_id)) {
            $this->failValidation('Gilda non valida');
        }

        $guild = $this->guildService()->getGuild($guild_id);
        if (empty($guild) || (int) $guild->is_visible !== 1) {
            $this->failValidation('Gilda non disponibile');
        }

        if ($this->guildService()->getMemberCount($character_id) >= 2) {
            $this->failValidation('Puoi appartenere al massimo a due gilde');
        }

        $membership = $this->guildService()->getMembership($character_id, $guild_id);
        if (!empty($membership)) {
            $this->failValidation('Sei gia membro della gilda');
        }

        $requirements = $this->guildService()->getRequirements($guild_id);
        $character = $this->guildService()->getCharacter($character_id);
        $reqCheck = $this->guildService()->checkRequirements($requirements, $character);
        if (!$reqCheck['allowed']) {
            $this->failValidation('Requisiti non soddisfatti');
        }

        $existing = $this->guildService()->findApplicationByGuildAndCharacter($guild_id, $character_id);

        if (!empty($existing) && $existing->status === 'pending') {
            $this->failValidation('Candidatura gia inviata');
        }

        if (!empty($existing)) {
            $this->guildService()->setApplicationPending($existing->id, $message);
        } else {
            $this->guildService()->createApplication($guild_id, $character_id, $message);
        }

        $this->guildService()->logEvent($guild_id, 'application_submitted', $character_id, null, [
            'message' => $message,
        ]);

        // Notify guild leader about new application
        $leaderCharId = (int) ($guild->leader_character_id ?? 0);
        if ($leaderCharId > 0) {
            $leaderUserId = $this->guildService()->getCharacterUserId($leaderCharId);
            if ($leaderUserId > 0) {
                $this->notifService()->create(
                    $leaderUserId,
                    $leaderCharId,
                    NotificationService::KIND_SYSTEM_UPDATE,
                    'guild_application',
                    'Nuova candidatura per la gilda: ' . $guild->name,
                    [
                        'actor_character_id' => $character_id,
                        'source_type' => 'guild_application',
                        'source_id' => $guild_id,
                        'action_url' => '/game/guild/' . $guild_id,
                    ],
                );
            }
        }

        ResponseEmitter::emit(ApiResponse::json(['status' => 'ok']));
    }

    public function applications()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);

        $character_id = $this->requireCharacter();
        $data = $this->requestDataObject();
        $guild_id = isset($data->guild_id) ? (int) $data->guild_id : null;

        if (empty($guild_id)) {
            $this->failValidation('Gilda non valida');
        }

        $member = $this->guildService()->getMembership($character_id, $guild_id);
        if (empty($member) || (!$member->is_leader && !$member->is_officer)) {
            $this->failUnauthorized('Accesso non autorizzato');
        }

        $apps = $this->guildService()->listPendingApplications($guild_id);

        ResponseEmitter::emit(ApiResponse::json([
            'dataset' => $apps,
        ]));
    }

    public function decideApplication()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);

        $character_id = $this->requireCharacter();
        $data = $this->requestDataObject();
        $application_id = isset($data->application_id) ? (int) $data->application_id : null;
        $action = isset($data->action) ? trim($data->action) : '';
        $role_id = isset($data->role_id) ? (int) $data->role_id : null;

        if (empty($application_id) || ($action !== 'accept' && $action !== 'decline')) {
            $this->failValidation('Dati non validi');
        }

        $application = $this->guildService()->findApplicationById($application_id);
        if (empty($application) || $application->status !== 'pending') {
            $this->failValidation('Candidatura non valida');
        }

        $guild_id = (int) $application->guild_id;
        $member = $this->guildService()->getMembership($character_id, $guild_id);
        if (empty($member) || !$member->is_leader) {
            $this->failUnauthorized('Solo il capo puo gestire le candidature');
        }

        if ($action === 'decline') {
            $this->guildService()->setApplicationReviewed($application_id, 'declined', $character_id);
            $this->guildService()->logEvent($guild_id, 'application_declined', $character_id, $application->character_id);

            // Notify applicant of rejection
            $applicantUserId = $this->guildService()->getCharacterUserId((int) $application->character_id);
            if ($applicantUserId > 0) {
                $guild = $this->guildService()->getGuild($guild_id);
                $this->notifService()->create(
                    $applicantUserId,
                    (int) $application->character_id,
                    NotificationService::KIND_DECISION_RESULT,
                    'guild_application_result',
                    'Candidatura rifiutata: ' . ($guild->name ?? ''),
                    [
                        'actor_character_id' => $character_id,
                        'source_type' => 'guild_application',
                        'source_id' => $application_id,
                        'action_url' => '/game/guild/' . $guild_id,
                    ],
                );
            }

            ResponseEmitter::emit(ApiResponse::json(['status' => 'ok']));
            return;
        }

        if ($this->guildService()->getMemberCount($application->character_id) >= 2) {
            $this->failValidation('Il personaggio ha gia due gilde');
        }

        $alreadyMember = $this->guildService()->getMembership($application->character_id, $guild_id);
        if (!empty($alreadyMember)) {
            $this->failValidation('Il personaggio e gia membro');
        }

        if (empty($role_id)) {
            $role_id = $this->guildService()->getDefaultRole($guild_id);
        }

        $currentRole = $this->guildService()->getMemberRole($guild_id, (int) $application->character_id);

        $role = $this->guildService()->getRoleInGuild($guild_id, $role_id);
        if (empty($role)) {
            $this->failValidation('Ruolo non valido');
        }

        if ((int) $role->is_leader === 1 && $this->guildService()->hasLeader($guild_id)) {
            $this->failValidation('La gilda ha gia un capo');
        }

        $isPrimary = ($this->guildService()->getMemberCount($application->character_id) === 0) ? 1 : 0;
        $this->guildService()->addMember($guild_id, $application->character_id, $role_id, $isPrimary);

        if ((int) $role->is_leader === 1) {
            $this->guildService()->setGuildLeader($guild_id, $application->character_id);
        }

        $this->guildService()->setApplicationReviewed($application_id, 'accepted', $character_id);

        $this->guildService()->logEvent($guild_id, 'application_accepted', $character_id, $application->character_id, [
            'role_id' => $role_id,
            'old_role_id' => !empty($currentRole) ? $currentRole->role_id : null,
        ]);

        // Notify applicant of acceptance
        $applicantUserId = $this->guildService()->getCharacterUserId((int) $application->character_id);
        if ($applicantUserId > 0) {
            $guild = $this->guildService()->getGuild($guild_id);
            $this->notifService()->create(
                $applicantUserId,
                (int) $application->character_id,
                NotificationService::KIND_DECISION_RESULT,
                'guild_application_result',
                'Candidatura accettata: ' . ($guild->name ?? ''),
                [
                    'actor_character_id' => $character_id,
                    'source_type' => 'guild_application',
                    'source_id' => $application_id,
                    'action_url' => '/game/guild/' . $guild_id,
                ],
            );
        }

        ResponseEmitter::emit(ApiResponse::json(['status' => 'ok']));
    }
    public function members()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);

        $character_id = $this->requireCharacter();
        $data = $this->requestDataObject();
        $guild_id = isset($data->guild_id) ? (int) $data->guild_id : null;

        if (empty($guild_id)) {
            $this->failValidation('Gilda non valida');
        }

        $guild = $this->guildService()->getGuild($guild_id);
        if (empty($guild)) {
            $this->failNotFound('Gilda non trovata');
        }

        $membership = $this->guildService()->getMembership($character_id, $guild_id);
        if ((int) $guild->is_visible !== 1 && empty($membership)) {
            $this->failUnauthorized('Accesso non autorizzato');
        }

        $members = $this->guildService()->listMembers($guild_id);

        ResponseEmitter::emit(ApiResponse::json([
            'dataset' => $members,
        ]));
    }
    public function requirementsUpsert()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);

        $character_id = $this->requireCharacter();
        $data = $this->requestDataObject();

        $guild_id = isset($data->guild_id) ? (int) $data->guild_id : null;
        $requirement_id = isset($data->id) ? (int) $data->id : null;
        $type = isset($data->type) ? trim((string) $data->type) : '';
        $value = isset($data->value) ? trim((string) $data->value) : '';
        $label = isset($data->label) ? trim((string) $data->label) : null;

        if (empty($guild_id) || $type === '') {
            $this->failValidation('Dati non validi');
        }

        $allowedTypes = ['min_fame', 'min_socialstatus_id', 'job_id', 'no_job'];
        if (!in_array($type, $allowedTypes, true)) {
            $this->failValidation('Tipo requisito non valido');
        }

        if ($type === 'no_job') {
            $value = '1';
        }

        if ($type !== 'no_job' && $value === '') {
            $this->failValidation('Valore requisito non valido');
        }

        $member = $this->guildService()->getMembership($character_id, $guild_id);
        if (empty($member) || !$member->is_leader) {
            $this->failUnauthorized('Solo il capo puo gestire i requisiti');
        }

        if (!empty($requirement_id)) {
            $existing = $this->guildService()->findRequirementById($requirement_id);
            if (empty($existing) || (int) $existing->guild_id !== (int) $guild_id) {
                $this->failValidation('Requisito non valido');
            }
            $this->guildService()->updateRequirement($requirement_id, $type, $value, $label);
        } else {
            $this->guildService()->createRequirement($guild_id, $type, $value, $label);
        }

        $this->guildService()->logEvent($guild_id, 'requirement_upserted', $character_id, null, [
            'requirement_id' => $requirement_id,
            'type' => $type,
            'value' => $value,
        ]);

        $requirements = $this->guildService()->getRequirements($guild_id);

        ResponseEmitter::emit(ApiResponse::json([
            'status' => 'ok',
            'dataset' => $requirements,
        ]));
    }

    public function requirementsOptions()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);

        $character_id = $this->requireCharacter();
        $data = $this->requestDataObject();
        $guild_id = isset($data->guild_id) ? (int) $data->guild_id : null;

        if (empty($guild_id)) {
            $this->failValidation('Gilda non valida');
        }

        $member = $this->guildService()->getMembership($character_id, $guild_id);
        if (empty($member) || !$member->is_leader) {
            $this->failUnauthorized('Solo il capo puo gestire i requisiti');
        }

        $socialStatuses = $this->guildService()->listRequirementSocialStatuses();
        $jobs = $this->guildService()->listRequirementJobs();

        ResponseEmitter::emit(ApiResponse::json([
            'dataset' => [
                'social_statuses' => $socialStatuses,
                'jobs' => $jobs,
            ],
        ]));
    }

    public function requirementsDelete()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);

        $character_id = $this->requireCharacter();
        $data = $this->requestDataObject();

        $guild_id = isset($data->guild_id) ? (int) $data->guild_id : null;
        $requirement_id = isset($data->id) ? (int) $data->id : null;

        if (empty($guild_id) || empty($requirement_id)) {
            $this->failValidation('Dati non validi');
        }

        $member = $this->guildService()->getMembership($character_id, $guild_id);
        if (empty($member) || !$member->is_leader) {
            $this->failUnauthorized('Solo il capo puo gestire i requisiti');
        }

        $existing = $this->guildService()->findRequirementById($requirement_id);
        if (empty($existing) || (int) $existing->guild_id !== (int) $guild_id) {
            $this->failValidation('Requisito non valido');
        }

        $this->guildService()->deleteRequirement($requirement_id);

        $this->guildService()->logEvent($guild_id, 'requirement_deleted', $character_id, null, [
            'requirement_id' => $requirement_id,
        ]);

        $requirements = $this->guildService()->getRequirements($guild_id);

        ResponseEmitter::emit(ApiResponse::json([
            'status' => 'ok',
            'dataset' => $requirements,
        ]));
    }

    public function roles()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);

        $character_id = $this->requireCharacter();
        $data = $this->requestDataObject();
        $guild_id = isset($data->guild_id) ? (int) $data->guild_id : null;

        if (empty($guild_id)) {
            $this->failValidation('Gilda non valida');
        }

        $member = $this->guildService()->getMembership($character_id, $guild_id);
        if (empty($member) || !$member->is_leader) {
            $this->failUnauthorized('Solo il capo puo gestire i ruoli');
        }

        $roles = $this->guildService()->listRoles($guild_id);

        ResponseEmitter::emit(ApiResponse::json([
            'dataset' => $roles,
        ]));
    }
    public function requestKick()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);

        $character_id = $this->requireCharacter();
        $data = $this->requestDataObject();
        $guild_id = isset($data->guild_id) ? (int) $data->guild_id : null;
        $target_id = isset($data->target_id) ? (int) $data->target_id : null;
        $reason = isset($data->reason) ? trim($data->reason) : null;

        if (empty($guild_id) || empty($target_id)) {
            $this->failValidation('Dati non validi');
        }

        $member = $this->guildService()->getMembership($character_id, $guild_id);
        if (empty($member) || (!$member->is_leader && !$member->is_officer)) {
            $this->failUnauthorized('Accesso non autorizzato');
        }

        $target = $this->guildService()->getMembership($target_id, $guild_id);
        if (empty($target)) {
            $this->failValidation('Membro non valido');
        }
        if ($target->is_leader) {
            $this->failValidation('Non puoi richiedere l\'espulsione del capo');
        }

        if (!$member->is_leader && !$this->guildService()->canManageRole($member->role_id, $target->role_id)) {
            $this->failUnauthorized('Non puoi gestire questo ruolo');
        }

        $this->guildService()->createKickRequest($guild_id, $character_id, $target_id, $reason);

        $this->guildService()->logEvent($guild_id, 'kick_requested', $character_id, $target_id, [
            'reason' => $reason,
        ]);

        ResponseEmitter::emit(ApiResponse::json(['status' => 'ok']));
    }
    public function decideKick()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);

        $character_id = $this->requireCharacter();
        $data = $this->requestDataObject();
        $request_id = isset($data->request_id) ? (int) $data->request_id : null;
        $action = isset($data->action) ? trim($data->action) : '';

        if (empty($request_id) || ($action !== 'approve' && $action !== 'decline')) {
            $this->failValidation('Dati non validi');
        }

        $request = $this->guildService()->findKickRequestById($request_id);
        if (empty($request) || $request->status !== 'pending') {
            $this->failValidation('Richiesta non valida');
        }

        $member = $this->guildService()->getMembership($character_id, $request->guild_id);
        if (empty($member) || !$member->is_leader) {
            $this->failUnauthorized('Solo il capo puo approvare');
        }

        if ($action === 'approve') {
            $this->guildService()->removeMember($request->guild_id, $request->target_id);
            $this->guildService()->logEvent($request->guild_id, 'kick_approved', $character_id, $request->target_id, [
                'reason' => $request->reason,
            ]);

            // Notify kicked member
            $targetUserId = $this->guildService()->getCharacterUserId((int) $request->target_id);
            if ($targetUserId > 0) {
                $guild = $this->guildService()->getGuild((int) $request->guild_id);
                $this->notifService()->create(
                    $targetUserId,
                    (int) $request->target_id,
                    NotificationService::KIND_SYSTEM_UPDATE,
                    'guild_kicked',
                    'Sei stato espulso dalla gilda: ' . ($guild->name ?? ''),
                    [
                        'actor_character_id' => $character_id,
                        'source_type' => 'guild_kick_request',
                        'source_id' => $request_id,
                        'action_url' => '/game/guilds',
                    ],
                );
            }
        }

        $this->guildService()->setKickRequestReviewed($request_id, $action === 'approve' ? 'approved' : 'declined', $character_id);

        if ($action === 'decline') {
            $this->guildService()->logEvent($request->guild_id, 'kick_declined', $character_id, $request->target_id);
        }

        ResponseEmitter::emit(ApiResponse::json(['status' => 'ok']));
    }
    public function directKick()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);

        $character_id = $this->requireCharacter();
        $data = $this->requestDataObject();
        $guild_id = isset($data->guild_id) ? (int) $data->guild_id : null;
        $target_id = isset($data->target_id) ? (int) $data->target_id : null;

        if (empty($guild_id) || empty($target_id)) {
            $this->failValidation('Dati non validi');
        }

        $member = $this->guildService()->getMembership($character_id, $guild_id);
        if (empty($member) || !$member->is_leader) {
            $this->failUnauthorized('Solo il capo puo espellere');
        }

        $this->guildService()->removeMember($guild_id, $target_id);

        $this->guildService()->logEvent($guild_id, 'member_removed', $character_id, $target_id);

        // Notify kicked member
        $targetUserId = $this->guildService()->getCharacterUserId($target_id);
        if ($targetUserId > 0) {
            $guild = $this->guildService()->getGuild($guild_id);
            $this->notifService()->create(
                $targetUserId,
                $target_id,
                NotificationService::KIND_SYSTEM_UPDATE,
                'guild_kicked',
                'Sei stato espulso dalla gilda: ' . ($guild->name ?? ''),
                [
                    'actor_character_id' => $character_id,
                    'source_type' => 'guild_membership',
                    'source_id' => $guild_id,
                    'action_url' => '/game/guilds',
                ],
            );
        }

        ResponseEmitter::emit(ApiResponse::json(['status' => 'ok']));
    }

    public function promote()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);

        $character_id = $this->requireCharacter();
        $data = $this->requestDataObject();
        $guild_id = isset($data->guild_id) ? (int) $data->guild_id : null;
        $target_id = isset($data->target_id) ? (int) $data->target_id : null;
        $role_id = isset($data->role_id) ? (int) $data->role_id : null;

        if (empty($guild_id) || empty($target_id) || empty($role_id)) {
            $this->failValidation('Dati non validi');
        }

        $member = $this->guildService()->getMembership($character_id, $guild_id);
        if (empty($member) || !$member->is_leader) {
            $this->failUnauthorized('Solo il capo puo promuovere');
        }

        $currentRole = $this->guildService()->getMemberRole($guild_id, $target_id);

        $role = $this->guildService()->getRoleInGuild($guild_id, $role_id);
        if (empty($role)) {
            $this->failValidation('Ruolo non valido');
        }

        if ((int) $role->is_leader === 1 && $this->guildService()->hasLeader($guild_id)) {
            $this->failValidation('La gilda ha gia un capo');
        }

        $this->guildService()->updateMemberRole($guild_id, $target_id, $role_id);

        if ((int) $role->is_leader === 1) {
            $this->guildService()->setGuildLeader($guild_id, $target_id);
        }

        $this->guildService()->logEvent($guild_id, 'role_changed', $character_id, $target_id, [
            'old_role_id' => !empty($currentRole) ? $currentRole->role_id : null,
            'new_role_id' => $role_id,
        ]);

        // Notify promoted member of role change
        $targetUserId = $this->guildService()->getCharacterUserId($target_id);
        if ($targetUserId > 0) {
            $guild = $this->guildService()->getGuild($guild_id);
            $this->notifService()->create(
                $targetUserId,
                $target_id,
                NotificationService::KIND_SYSTEM_UPDATE,
                'guild_role_changed',
                'Ruolo aggiornato in gilda: ' . ($guild->name ?? ''),
                [
                    'actor_character_id' => $character_id,
                    'source_type' => 'guild_membership',
                    'source_id' => $guild_id,
                    'action_url' => '/game/guild/' . $guild_id,
                ],
            );
        }

        ResponseEmitter::emit(ApiResponse::json(['status' => 'ok']));
    }

    public function claimSalary()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);

        $character_id = $this->requireCharacter();
        $data = $this->requestDataObject();
        $guild_id = isset($data->guild_id) ? (int) $data->guild_id : null;

        if (empty($guild_id)) {
            $this->failValidation('Gilda non valida');
        }

        $membership = $this->guildService()->getMembership($character_id, $guild_id);
        if (empty($membership)) {
            $this->failUnauthorized('Non sei membro della gilda');
        }

        $salary = (int) $membership->monthly_salary;
        if ($salary <= 0) {
            $this->failValidation('Nessuno stipendio disponibile');
        }

        if (!$this->guildService()->canClaimSalary($membership->salary_last_claim_at)) {
            $this->failValidation('Stipendio gia riscosso questo mese');
        }

        $bank_before = $this->guildService()->getCharacterBank($character_id);

        $this->guildService()->addCharacterBank($character_id, $salary);
        $this->guildService()->markSalaryClaimed($membership->member_id);

        $bank_after = $this->guildService()->getCharacterBank($character_id);
        $currency_id = CurrencyLogs::getDefaultCurrencyId();
        if (!empty($currency_id) && $salary > 0) {
            CurrencyLogs::write($character_id, $currency_id, 'bank', $salary, $bank_before, $bank_after, 'guild_salary', [
                'guild_id' => $guild_id,
                'member_id' => $membership->member_id,
            ]);
        }

        $this->guildService()->logEvent($guild_id, 'salary_claimed', $character_id, null, [
            'amount' => $salary,
        ]);

        ResponseEmitter::emit(ApiResponse::json([
            'status' => 'ok',
            'bank' => $bank_after,
        ]));
    }

    public function setPrimary()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);

        $character_id = $this->requireCharacter();
        $data = $this->requestDataObject();
        $guild_id = isset($data->guild_id) ? (int) $data->guild_id : null;

        if (empty($guild_id)) {
            $this->failValidation('Gilda non valida');
        }

        $member = $this->guildService()->getMembership($character_id, $guild_id);
        if (empty($member)) {
            $this->failUnauthorized('Non sei membro della gilda');
        }

        $this->guildService()->setPrimaryMembership($character_id, $guild_id);

        $this->guildService()->logEvent($guild_id, 'primary_set', $character_id, null, [
            'guild_id' => $guild_id,
        ]);

        ResponseEmitter::emit(ApiResponse::json(['status' => 'ok']));
    }

    public function logs()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);

        $character_id = $this->requireCharacter();
        $data = $this->requestDataObject();
        $guild_id = isset($data->guild_id) ? (int) $data->guild_id : null;

        if (empty($guild_id)) {
            $this->failValidation('Gilda non valida');
        }

        $member = $this->guildService()->getMembership($character_id, $guild_id);
        if (empty($member) || (!$member->is_leader && !$member->is_officer)) {
            $this->failUnauthorized('Accesso non autorizzato');
        }

        $rows = $this->guildService()->listLogs($guild_id);

        ResponseEmitter::emit(ApiResponse::json([
            'dataset' => $rows,
        ]));
    }

    public function announcements()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);

        $character_id = $this->requireCharacter();
        $data = $this->requestDataObject();
        $guild_id = isset($data->guild_id) ? (int) $data->guild_id : null;

        if (empty($guild_id)) {
            $this->failValidation('Gilda non valida');
        }

        $member = $this->guildService()->getMembership($character_id, $guild_id);
        if (empty($member)) {
            $this->failUnauthorized('Accesso non autorizzato');
        }

        $rows = $this->guildService()->listAnnouncements($guild_id);

        ResponseEmitter::emit(ApiResponse::json([
            'dataset' => $rows,
        ]));
    }

    public function announcementCreate()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);

        $character_id = $this->requireCharacter();
        $data = $this->requestDataObject();
        $guild_id = isset($data->guild_id) ? (int) $data->guild_id : null;
        $title = isset($data->title) ? trim($data->title) : '';
        $body = isset($data->body_html) ? $data->body_html : null;
        $is_pinned = isset($data->is_pinned) ? (int) $data->is_pinned : 0;

        if (empty($guild_id) || $title === '') {
            $this->failValidation('Dati non validi');
        }

        $member = $this->guildService()->getMembership($character_id, $guild_id);
        if (empty($member) || !$member->is_leader) {
            $this->failUnauthorized('Solo il capo puo pubblicare');
        }

        $this->guildService()->createAnnouncement($guild_id, $title, $body, $is_pinned, $character_id);

        $this->guildService()->logEvent($guild_id, 'announcement_created', $character_id, null, [
            'title' => $title,
        ]);

        ResponseEmitter::emit(ApiResponse::json(['status' => 'ok']));
    }

    public function announcementDelete()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);

        $character_id = $this->requireCharacter();
        $data = $this->requestDataObject();
        $guild_id = isset($data->guild_id) ? (int) $data->guild_id : null;
        $announcement_id = isset($data->announcement_id) ? (int) $data->announcement_id : null;

        if (empty($guild_id) || empty($announcement_id)) {
            $this->failValidation('Dati non validi');
        }

        $member = $this->guildService()->getMembership($character_id, $guild_id);
        if (empty($member) || !$member->is_leader) {
            $this->failUnauthorized('Solo il capo puo eliminare');
        }

        $this->guildService()->deleteAnnouncement($guild_id, $announcement_id);

        $this->guildService()->logEvent($guild_id, 'announcement_deleted', $character_id, null, [
            'announcement_id' => $announcement_id,
        ]);

        ResponseEmitter::emit(ApiResponse::json(['status' => 'ok']));
    }

    public function events()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);

        $character_id = $this->requireCharacter();
        $data = $this->requestDataObject();
        $guild_id = isset($data->guild_id) ? (int) $data->guild_id : null;

        if (empty($guild_id)) {
            $this->failValidation('Gilda non valida');
        }

        $member = $this->guildService()->getMembership($character_id, $guild_id);
        if (empty($member)) {
            $this->failUnauthorized('Accesso non autorizzato');
        }

        $rows = $this->guildService()->listEvents($guild_id);

        ResponseEmitter::emit(ApiResponse::json([
            'dataset' => $rows,
        ]));
    }

    public function eventCreate()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);

        $character_id = $this->requireCharacter();
        $data = $this->requestDataObject();
        $guild_id = isset($data->guild_id) ? (int) $data->guild_id : null;
        $title = isset($data->title) ? trim($data->title) : '';
        $body = isset($data->body_html) ? $data->body_html : null;
        $starts_at = isset($data->starts_at) ? trim($data->starts_at) : '';
        $ends_at = isset($data->ends_at) ? trim($data->ends_at) : null;
        if ($starts_at !== '') {
            $starts_at = str_replace('T', ' ', $starts_at);
        }
        if (!empty($ends_at)) {
            $ends_at = str_replace('T', ' ', $ends_at);
        }

        if (empty($guild_id) || $title === '' || $starts_at === '') {
            $this->failValidation('Dati non validi');
        }

        $member = $this->guildService()->getMembership($character_id, $guild_id);
        if (empty($member) || !$member->is_leader) {
            $this->failUnauthorized('Solo il capo puo creare eventi');
        }

        $this->guildService()->createEvent($guild_id, $title, $body, $starts_at, $ends_at, $character_id);

        $this->guildService()->logEvent($guild_id, 'event_created', $character_id, null, [
            'title' => $title,
            'starts_at' => $starts_at,
        ]);

        ResponseEmitter::emit(ApiResponse::json(['status' => 'ok']));
    }

    public function eventDelete()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);

        $character_id = $this->requireCharacter();
        $data = $this->requestDataObject();
        $guild_id = isset($data->guild_id) ? (int) $data->guild_id : null;
        $event_id = isset($data->event_id) ? (int) $data->event_id : null;

        if (empty($guild_id) || empty($event_id)) {
            $this->failValidation('Dati non validi');
        }

        $member = $this->guildService()->getMembership($character_id, $guild_id);
        if (empty($member) || !$member->is_leader) {
            $this->failUnauthorized('Solo il capo puo eliminare eventi');
        }

        $this->guildService()->deleteEvent($guild_id, $event_id);

        $this->guildService()->logEvent($guild_id, 'event_deleted', $character_id, null, [
            'event_id' => $event_id,
        ]);

        ResponseEmitter::emit(ApiResponse::json(['status' => 'ok']));
    }

    // ── Admin ─────────────────────────────────────────────────────────────

    private function guildAdminService(): GuildAdminService
    {
        if ($this->guildAdminService instanceof GuildAdminService) {
            return $this->guildAdminService;
        }
        $this->guildAdminService = new GuildAdminService();
        return $this->guildAdminService;
    }

    private function guildRoleAdminService(): GuildRoleAdminService
    {
        if ($this->guildRoleAdminService instanceof GuildRoleAdminService) {
            return $this->guildRoleAdminService;
        }
        $this->guildRoleAdminService = new GuildRoleAdminService();
        return $this->guildRoleAdminService;
    }

    public function adminList(): void
    {
        $this->requireAdmin();
        $data = $this->requestDataObject();
        $result = $this->guildAdminService()->list($data);
        ResponseEmitter::emit(ApiResponse::json($result));
    }

    public function adminCreate(): void
    {
        $this->requireAdmin();
        $data = $this->requestDataObject();
        $id = $this->guildAdminService()->create($data);
        ResponseEmitter::emit(ApiResponse::json(['status' => 'ok', 'id' => $id]));
    }

    public function adminUpdate(): void
    {
        $this->requireAdmin();
        $data = $this->requestDataObject();
        $this->guildAdminService()->update($data);
        ResponseEmitter::emit(ApiResponse::json(['status' => 'ok']));
    }

    public function adminDelete(): void
    {
        $this->requireAdmin();
        $data = $this->requestDataObject();
        $id = isset($data->id) ? (int) $data->id : 0;
        $this->guildAdminService()->delete($id);
        ResponseEmitter::emit(ApiResponse::json(['status' => 'ok']));
    }

    public function adminRolesList(): void
    {
        $this->requireAdmin();
        $data = $this->requestDataObject();
        $guildId = isset($data->guild_id) ? (int) $data->guild_id : 0;
        $roles = $this->guildAdminService()->getRoles($guildId);
        ResponseEmitter::emit(ApiResponse::json(['roles' => $roles]));
    }

    public function adminRoleCreate(): void
    {
        $this->requireAdmin();
        $data = $this->requestDataObject();
        $this->guildRoleAdminService()->create($data);
        ResponseEmitter::emit(ApiResponse::json(['status' => 'ok']));
    }

    public function adminRoleUpdate(): void
    {
        $this->requireAdmin();
        $data = $this->requestDataObject();
        $this->guildRoleAdminService()->update($data);
        ResponseEmitter::emit(ApiResponse::json(['status' => 'ok']));
    }

    public function adminRoleDelete(): void
    {
        $this->requireAdmin();
        $data = $this->requestDataObject();
        $id = isset($data->id) ? (int) $data->id : 0;
        $this->guildAdminService()->deleteRole($id);
        ResponseEmitter::emit(ApiResponse::json(['status' => 'ok']));
    }

    private function guildEventAdminService(): GuildEventAdminService
    {
        if ($this->guildEventAdminService instanceof GuildEventAdminService) {
            return $this->guildEventAdminService;
        }
        $this->guildEventAdminService = new GuildEventAdminService();
        return $this->guildEventAdminService;
    }

    public function adminEventList(): void
    {
        $this->requireAdmin();
        $data = $this->requestDataObject();
        $result = $this->guildEventAdminService()->list($data);
        ResponseEmitter::emit(ApiResponse::json($result));
    }

    public function adminEventCreate(): void
    {
        $this->requireAdmin();
        $data = $this->requestDataObject();
        $this->guildEventAdminService()->create($data);
        ResponseEmitter::emit(ApiResponse::json(['status' => 'ok']));
    }

    public function adminEventUpdate(): void
    {
        $this->requireAdmin();
        $data = $this->requestDataObject();
        $this->guildEventAdminService()->update($data);
        ResponseEmitter::emit(ApiResponse::json(['status' => 'ok']));
    }

    public function adminEventDelete(): void
    {
        $this->requireAdmin();
        $data = $this->requestDataObject();
        $id = isset($data->id) ? (int) $data->id : 0;
        $this->guildEventAdminService()->delete($id);
        ResponseEmitter::emit(ApiResponse::json(['status' => 'ok']));
    }
}


