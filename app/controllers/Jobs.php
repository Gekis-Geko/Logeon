<?php

declare(strict_types=1);

use App\Services\JobAdminService;
use App\Services\JobLevelAdminService;
use App\Services\JobService;
use App\Services\JobTaskAdminService;
use Core\CurrencyLogs;
use Core\Http\ApiResponse;
use Core\Http\AppError;
use Core\Http\InputValidator;
use Core\Http\RequestData;
use Core\Http\ResponseEmitter;
use Core\Logging\LegacyLoggerAdapter;
use Core\Logging\LoggerInterface;

class Jobs
{
    /** @var LoggerInterface|null */
    private $logger = null;
    /** @var JobService|null */
    private $jobService = null;
    /** @var JobAdminService|null */
    private $jobAdminService = null;
    /** @var JobTaskAdminService|null */
    private $jobTaskAdminService = null;
    /** @var JobLevelAdminService|null */
    private $jobLevelAdminService = null;

    public function setLogger(LoggerInterface $logger = null)
    {
        $this->logger = $logger;
        return $this;
    }

    public function setJobService(JobService $jobService = null)
    {
        $this->jobService = $jobService;
        return $this;
    }

    private function logger(): LoggerInterface
    {
        if ($this->logger instanceof LoggerInterface) {
            return $this->logger;
        }

        $this->logger = new LegacyLoggerAdapter();
        return $this->logger;
    }

    protected function trace($message, $context = false): void
    {
        $this->logger()->trace($message, $context);
    }

    private function jobService(): JobService
    {
        if ($this->jobService instanceof JobService) {
            return $this->jobService;
        }

        $this->jobService = new JobService();
        return $this->jobService;
    }

    private function jobAdminService(): JobAdminService
    {
        if ($this->jobAdminService instanceof JobAdminService) {
            return $this->jobAdminService;
        }

        $this->jobAdminService = new JobAdminService();
        return $this->jobAdminService;
    }

    private function jobLevelAdminService(): JobLevelAdminService
    {
        if ($this->jobLevelAdminService instanceof JobLevelAdminService) {
            return $this->jobLevelAdminService;
        }

        $this->jobLevelAdminService = new JobLevelAdminService();
        return $this->jobLevelAdminService;
    }

    private function jobTaskAdminService(): JobTaskAdminService
    {
        if ($this->jobTaskAdminService instanceof JobTaskAdminService) {
            return $this->jobTaskAdminService;
        }

        $this->jobTaskAdminService = new JobTaskAdminService();
        return $this->jobTaskAdminService;
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
            'Personaggio non valido' => 'character_invalid',
            'Lavoro non valido' => 'job_invalid',
            'Lavoro non trovato' => 'job_not_found',
            'Requisiti non soddisfatti' => 'job_requirements_not_met',
            'Stato sociale insufficiente' => 'job_social_status_insufficient',
            'Incarico non valido' => 'job_task_invalid',
            'Incarico non trovato' => 'job_task_not_found',
            'Incarico non disponibile' => 'job_task_not_available',
            'Scelta non valida' => 'job_choice_invalid',
            'Devi essere nella location richiesta' => 'job_location_required',
            'La tua gilda non consente lavori' => 'job_blocked_by_guild',
            'Devi attendere 24 ore dalla scelta del lavoro' => 'job_cooldown_active',
        ];

        return $map[$message] ?? 'validation_error';
    }

    private function requestDataObject($default = null, $allowInvalidJson = true)
    {
        $request = RequestData::fromGlobals();
        return InputValidator::postJsonObject($request, 'data', true);
    }

    private function requireAdmin()
    {
        \Core\AuthGuard::api()->requireAbility('settings.manage', [], 'Operazione non autorizzata');
    }

    public function adminList()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();

        $data = $this->requestDataObject();
        $result = $this->jobAdminService()->list($data);

        ResponseEmitter::emit(ApiResponse::json($result));
    }

    public function adminCreate()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();

        $data = $this->requestDataObject();
        $name = trim((string) ($data->name ?? ''));
        if ($name === '') {
            throw AppError::validation('Nome obbligatorio', [], 'name_required');
        }

        $this->jobAdminService()->create($data);

        ResponseEmitter::emit(ApiResponse::json(['success' => true, 'message' => 'Lavoro creato']));
    }

    public function adminUpdate()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();

        $data = $this->requestDataObject();
        $id = (int) ($data->id ?? 0);
        $name = trim((string) ($data->name ?? ''));
        if ($id <= 0) {
            throw AppError::validation('ID non valido', [], 'id_invalid');
        }
        if ($name === '') {
            throw AppError::validation('Nome obbligatorio', [], 'name_required');
        }

        $this->jobAdminService()->update($data);

        ResponseEmitter::emit(ApiResponse::json(['success' => true, 'message' => 'Lavoro aggiornato']));
    }

    public function adminDelete()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();

        $data = $this->requestDataObject();
        $id = (int) ($data->id ?? 0);
        if ($id <= 0) {
            throw AppError::validation('ID non valido', [], 'id_invalid');
        }

        $this->jobAdminService()->delete($id);

        ResponseEmitter::emit(ApiResponse::json(['success' => true, 'message' => 'Lavoro eliminato']));
    }

    // ── Job Tasks Admin ──────────────────────────────────────────────────────

    public function adminTaskList()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();

        $data = $this->requestDataObject();
        $result = $this->jobTaskAdminService()->list($data);

        ResponseEmitter::emit(ApiResponse::json($result));
    }

    public function adminTaskGet()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();

        $data = $this->requestDataObject();
        $id = (int) ($data->id ?? 0);
        if ($id <= 0) {
            throw AppError::validation('ID non valido', [], 'id_invalid');
        }

        $task = $this->jobTaskAdminService()->getWithChoices($id);
        if (empty($task)) {
            throw AppError::validation('Compito non trovato', [], 'task_not_found');
        }

        ResponseEmitter::emit(ApiResponse::json(['task' => $task]));
    }

    public function adminTaskCreate()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();

        $data = $this->requestDataObject();
        $title = trim((string) ($data->title ?? ''));
        $jobId = (int) ($data->job_id ?? 0);
        if ($title === '') {
            throw AppError::validation('Titolo obbligatorio', [], 'title_required');
        }
        if ($jobId <= 0) {
            throw AppError::validation('Lavoro obbligatorio', [], 'job_required');
        }

        $newId = $this->jobTaskAdminService()->create($data);

        ResponseEmitter::emit(ApiResponse::json(['success' => true, 'id' => $newId, 'message' => 'Compito creato']));
    }

    public function adminTaskUpdate()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();

        $data = $this->requestDataObject();
        $id = (int) ($data->id ?? 0);
        $title = trim((string) ($data->title ?? ''));
        if ($id <= 0) {
            throw AppError::validation('ID non valido', [], 'id_invalid');
        }
        if ($title === '') {
            throw AppError::validation('Titolo obbligatorio', [], 'title_required');
        }

        $this->jobTaskAdminService()->update($data);

        ResponseEmitter::emit(ApiResponse::json(['success' => true, 'message' => 'Compito aggiornato']));
    }

    public function adminTaskDelete()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();

        $data = $this->requestDataObject();
        $id = (int) ($data->id ?? 0);
        if ($id <= 0) {
            throw AppError::validation('ID non valido', [], 'id_invalid');
        }

        $this->jobTaskAdminService()->delete($id);

        ResponseEmitter::emit(ApiResponse::json(['success' => true, 'message' => 'Compito eliminato']));
    }

    // ── Job Levels Admin ─────────────────────────────────────────────────────

    public function adminLevelList()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();

        $data = $this->requestDataObject();
        $result = $this->jobLevelAdminService()->list($data);

        ResponseEmitter::emit(ApiResponse::json($result));
    }

    public function adminLevelCreate()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();

        $data = $this->requestDataObject();
        $title = trim((string) ($data->title ?? ''));
        $jobId = (int) ($data->job_id ?? 0);
        if ($title === '') {
            throw AppError::validation('Titolo obbligatorio', [], 'title_required');
        }
        if ($jobId <= 0) {
            throw AppError::validation('Lavoro obbligatorio', [], 'job_required');
        }

        $this->jobLevelAdminService()->create($data);

        ResponseEmitter::emit(ApiResponse::json(['success' => true, 'message' => 'Livello creato']));
    }

    public function adminLevelUpdate()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();

        $data = $this->requestDataObject();
        $id = (int) ($data->id ?? 0);
        $title = trim((string) ($data->title ?? ''));
        if ($id <= 0) {
            throw AppError::validation('ID non valido', [], 'id_invalid');
        }
        if ($title === '') {
            throw AppError::validation('Titolo obbligatorio', [], 'title_required');
        }

        $this->jobLevelAdminService()->update($data);

        ResponseEmitter::emit(ApiResponse::json(['success' => true, 'message' => 'Livello aggiornato']));
    }

    public function adminLevelDelete()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();

        $data = $this->requestDataObject();
        $id = (int) ($data->id ?? 0);
        if ($id <= 0) {
            throw AppError::validation('ID non valido', [], 'id_invalid');
        }

        $this->jobLevelAdminService()->delete($id);

        ResponseEmitter::emit(ApiResponse::json(['success' => true, 'message' => 'Livello eliminato']));
    }

    public function available()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $character_id = \Core\AuthGuard::api()->requireCharacter();
        $data = $this->requestDataObject((object) [], true);
        $location_id = isset($data->location_id) ? (int) $data->location_id : null;

        $character = $this->jobService()->getCharacter($character_id);
        if (empty($character)) {
            $this->failValidation('Personaggio non valido');
        }

        $jobs = $this->jobService()->listAvailableJobs($location_id);

        if (!empty($jobs)) {
            foreach ($jobs as $job) {
                $check = $this->jobService()->checkJobRequirements($job, $character);
                $job->is_available = $check['allowed'];
                $job->reason = $check['reason'];
            }
        }

        ResponseEmitter::emit(ApiResponse::json([
            'dataset' => $jobs,
        ]));
    }

    public function assign()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $character_id = \Core\AuthGuard::api()->requireCharacter();
        $data = $this->requestDataObject();

        $job_id = isset($data->job_id) ? (int) $data->job_id : null;
        if (empty($job_id)) {
            $this->failValidation('Lavoro non valido');
        }

        $character = $this->jobService()->getCharacter($character_id);
        if (empty($character)) {
            $this->failValidation('Personaggio non valido');
        }

        if ($this->jobService()->isCharacterBlockedFromJobsByGuild((int) $character_id)) {
            $this->failValidation('La tua gilda non consente lavori');
        }

        $currentJob = $this->jobService()->getCurrentActiveCharacterJob($character_id);
        if (!empty($currentJob) && (int) $currentJob->job_id === (int) $job_id) {
            ResponseEmitter::emit(ApiResponse::json([
                'status' => 'ok',
                'already_active' => true,
            ]));
            return;
        }

        $job = $this->jobService()->getActiveJobById($job_id);
        if (empty($job)) {
            $this->failValidation('Lavoro non trovato');
        }

        $check = $this->jobService()->checkJobRequirements($job, $character);
        if (!$check['allowed']) {
            $this->failValidation($check['reason'] ?: 'Requisiti non soddisfatti');
        }

        $existing = $this->jobService()->findCharacterJobByJob($character_id, $job_id);

        $this->jobService()->deactivateCharacterJobs($character_id);

        if (!empty($existing)) {
            $this->jobService()->reactivateCharacterJob($existing->id);
        } else {
            $start_level = $this->jobService()->getJobStartLevel($job_id);
            $this->jobService()->createCharacterJob($character_id, $job_id, $start_level);
        }

        ResponseEmitter::emit(ApiResponse::json([
            'status' => 'ok',
        ]));
    }

    public function leave()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $character_id = \Core\AuthGuard::api()->requireCharacter();

        $character = $this->jobService()->getCharacter($character_id);
        if (empty($character)) {
            $this->failValidation('Personaggio non valido');
        }

        $this->jobService()->leaveCurrentJob($character_id);

        ResponseEmitter::emit(ApiResponse::json([
            'status' => 'ok',
        ]));
    }

    public function current()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $character_id = \Core\AuthGuard::api()->requireCharacter();

        $character = $this->jobService()->getCharacter($character_id);
        if (empty($character)) {
            $this->failValidation('Personaggio non valido');
        }

        $job = $this->jobService()->getCurrentActiveCharacterJob($character_id);

        if (empty($job)) {
            ResponseEmitter::emit(ApiResponse::json([
                'job' => null,
                'tasks' => [],
                'history' => [],
            ]));
            return;
        }

        $this->jobService()->syncLevel($job->character_job_id, $job->job_id);
        $job = $this->jobService()->getCharacterJob($job->character_job_id);

        $this->jobService()->expireOldTasks($job->character_job_id);
        $this->jobService()->ensureDailyTasks($job, $character);

        $tasks = $this->jobService()->getDailyTasks($job->character_job_id, $character);
        $history = $this->jobService()->getRecentCompletions($job->character_job_id, 5);

        ResponseEmitter::emit(ApiResponse::json([
            'job' => $job,
            'tasks' => $tasks,
            'history' => $history,
        ]));
    }

    public function completeTask()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $character_id = \Core\AuthGuard::api()->requireCharacter();
        $data = $this->requestDataObject();

        $assignment_id = isset($data->assignment_id) ? (int) $data->assignment_id : null;
        $choice_id = isset($data->choice_id) ? (int) $data->choice_id : null;

        if (empty($assignment_id) || empty($choice_id)) {
            $this->failValidation('Incarico non valido');
        }

        $assignment = $this->jobService()->getTaskAssignmentForCharacter($assignment_id, $character_id);

        if (empty($assignment)) {
            $this->failValidation('Incarico non trovato');
        }
        if ($assignment->status !== 'pending') {
            $this->failValidation('Incarico non disponibile');
        }

        $choice = $this->jobService()->getTaskChoice($choice_id, $assignment->task_id);
        if (empty($choice)) {
            $this->failValidation('Scelta non valida');
        }

        $character = $this->jobService()->getCharacter($character_id);
        if (empty($character)) {
            $this->failValidation('Personaggio non valido');
        }

        if ($this->jobService()->isCharacterBlockedFromJobsByGuild((int) $character_id)) {
            $this->failValidation('La tua gilda non consente lavori');
        }

        if ($this->jobService()->isTaskCooldownActive($assignment->job_date_assigned ?? null)) {
            $unlockAt = $this->jobService()->getTaskUnlockAt($assignment->job_date_assigned ?? null);
            $unlockAtLabel = $this->jobService()->formatDateTimeIt($unlockAt);
            if (!empty($unlockAtLabel)) {
                $this->failValidation('Devi attendere 24 ore dalla scelta del lavoro. Disponibile dal ' . $unlockAtLabel, 'job_cooldown_active');
            }
            $this->failValidation('Devi attendere 24 ore dalla scelta del lavoro', 'job_cooldown_active');
        }

        $money_before = isset($character->money) ? (int) $character->money : null;

        if (!empty($assignment->requires_location_id)) {
            if ((int) $character->last_location !== (int) $assignment->requires_location_id) {
                $this->failValidation('Devi essere nella location richiesta');
            }
        }

        $levelBonus = $this->jobService()->getLevelBonus($assignment->job_id, $assignment->level);
        $pay = (float) $choice->pay;
        if ($levelBonus > 0 && $pay > 0) {
            $pay = round($pay + ($pay * ($levelBonus / 100)), 2);
        }
        $fame = (float) $choice->fame;
        $points = (int) $choice->points;

        $fame_before = (float) $character->fame;
        $fame_after = $fame_before + $fame;

        $this->jobService()->applyTaskCompletion(
            (int) $character_id,
            $assignment,
            $choice,
            (float) $pay,
            (float) $fame,
            (int) $points,
            (float) $fame_before,
            (float) $fame_after,
        );
        $money_after = ($money_before !== null) ? $money_before + $pay : null;
        $currency_id = CurrencyLogs::getDefaultCurrencyId();
        if (!empty($currency_id) && $pay != 0) {
            CurrencyLogs::write($character_id, $currency_id, 'money', $pay, $money_before, $money_after, 'job_reward', [
                'job_id' => $assignment->job_id,
                'task_id' => $assignment->task_id,
                'choice_id' => $choice->id,
                'assignment_id' => $assignment_id,
            ]);
        }

        $this->jobService()->syncLevel($assignment->character_job_id, $assignment->job_id);
        $updated = $this->jobService()->getCharacterJob($assignment->character_job_id);

        $fresh = $this->jobService()->getCharacter($character_id);

        ResponseEmitter::emit(ApiResponse::json([
            'status' => 'ok',
            'reward' => [
                'pay' => $pay,
                'fame' => $fame,
                'points' => $points,
            ],
            'character' => [
                'money' => isset($fresh->money) ? $fresh->money : null,
                'fame' => isset($fresh->fame) ? $fresh->fame : null,
            ],
            'job' => [
                'level' => $updated->level ?? null,
                'points' => $updated->points ?? null,
            ],
        ]));
    }
}
