<?php

declare(strict_types=1);

use App\Services\Update\UpdateApplyService;
use App\Services\Update\UpdateBackupService;
use App\Services\Update\UpdateCheckService;
use App\Services\Update\UpdateDownloadService;
use App\Services\Update\UpdateLogService;
use App\Services\Update\UpdatePreflightService;
use App\Services\Update\UpdateRollbackService;
use Core\AuthGuard;
use Core\Http\ApiResponse;
use Core\Http\RequestData;
use Core\Http\ResponseEmitter;
use Core\SessionStore;

class SystemUpdate
{
    /** @var UpdateCheckService|null */
    private $checkService = null;
    /** @var UpdatePreflightService|null */
    private $preflightService = null;
    /** @var UpdateBackupService|null */
    private $backupService = null;
    /** @var UpdateDownloadService|null */
    private $downloadService = null;
    /** @var UpdateApplyService|null */
    private $applyService = null;
    /** @var UpdateRollbackService|null */
    private $rollbackService = null;
    /** @var UpdateLogService|null */
    private $logService = null;

    public function setService(UpdateCheckService $service = null)
    {
        $this->checkService = $service;
        return $this;
    }

    private function checkService(): UpdateCheckService
    {
        if ($this->checkService instanceof UpdateCheckService) {
            return $this->checkService;
        }

        $this->checkService = new UpdateCheckService();
        return $this->checkService;
    }

    private function preflightService(): UpdatePreflightService
    {
        if ($this->preflightService instanceof UpdatePreflightService) {
            return $this->preflightService;
        }
        $this->preflightService = new UpdatePreflightService();
        return $this->preflightService;
    }

    private function backupService(): UpdateBackupService
    {
        if ($this->backupService instanceof UpdateBackupService) {
            return $this->backupService;
        }
        $this->backupService = new UpdateBackupService();
        return $this->backupService;
    }

    private function downloadService(): UpdateDownloadService
    {
        if ($this->downloadService instanceof UpdateDownloadService) {
            return $this->downloadService;
        }
        $this->downloadService = new UpdateDownloadService();
        return $this->downloadService;
    }

    private function applyService(): UpdateApplyService
    {
        if ($this->applyService instanceof UpdateApplyService) {
            return $this->applyService;
        }
        $this->applyService = new UpdateApplyService();
        return $this->applyService;
    }

    private function rollbackService(): UpdateRollbackService
    {
        if ($this->rollbackService instanceof UpdateRollbackService) {
            return $this->rollbackService;
        }
        $this->rollbackService = new UpdateRollbackService();
        return $this->rollbackService;
    }

    private function logService(): UpdateLogService
    {
        if ($this->logService instanceof UpdateLogService) {
            return $this->logService;
        }
        $this->logService = new UpdateLogService();
        return $this->logService;
    }

    private function requireAdmin(): void
    {
        AuthGuard::api()->requireAbility('settings.manage', [], 'Operazione non autorizzata');
    }

    private function requireCreator(): void
    {
        $this->requireAdmin();

        $creatorFlag = SessionStore::get('user_is_superuser_creator');
        if ($creatorFlag === null) {
            $creatorFlag = SessionStore::get('user_is_superuser');
        }

        $isCreator = ((int) ($creatorFlag ?? 0) === 1);
        if (!$isCreator) {
            AuthGuard::api()->requireAbility('system.update', [], 'Operazione consentita solo al creator');
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function payload(): array
    {
        $request = RequestData::fromGlobals();
        $jsonBody = $request->jsonBody([], true);
        if (is_array($jsonBody)) {
            return $jsonBody;
        }
        $dataField = $request->postJson('data', [], true);
        if (is_array($dataField)) {
            return $dataField;
        }
        return [];
    }

    public function status()
    {
        $this->requireAdmin();
        $dataset = $this->checkService()->status();
        $dataset['runtime'] = $this->logService()->statusSnapshot();
        ResponseEmitter::emit(ApiResponse::json(['dataset' => $dataset]));
        return $this;
    }

    public function check()
    {
        $this->requireAdmin();
        $dataset = $this->checkService()->check();
        $dataset['runtime'] = $this->logService()->statusSnapshot();
        ResponseEmitter::emit(ApiResponse::json(['dataset' => $dataset]));
        return $this;
    }

    public function preflight()
    {
        $this->requireCreator();
        $payload = $this->payload();
        $targetVersion = trim((string) ($payload['target_version'] ?? ''));
        $dataset = $this->preflightService()->run($targetVersion);
        ResponseEmitter::emit(ApiResponse::json(['dataset' => $dataset]));
        return $this;
    }

    public function backup()
    {
        $this->requireCreator();
        $payload = $this->payload();
        $targetVersion = trim((string) ($payload['target_version'] ?? ''));
        $dataset = $this->backupService()->create($targetVersion);
        ResponseEmitter::emit(ApiResponse::json(['dataset' => $dataset]));
        return $this;
    }

    public function download()
    {
        $this->requireCreator();
        $payload = $this->payload();
        $targetVersion = trim((string) ($payload['target_version'] ?? ''));
        $dataset = $this->downloadService()->download($targetVersion);
        ResponseEmitter::emit(ApiResponse::json(['dataset' => $dataset]));
        return $this;
    }

    public function apply()
    {
        $this->requireCreator();
        $payload = $this->payload();
        $targetVersion = trim((string) ($payload['target_version'] ?? ''));
        $backupId = trim((string) ($payload['backup_id'] ?? ''));
        $dataset = $this->applyService()->apply($targetVersion, $backupId);
        ResponseEmitter::emit(ApiResponse::json(['dataset' => $dataset]));
        return $this;
    }

    public function rollback()
    {
        $this->requireCreator();
        $payload = $this->payload();
        $clearLocks = !empty($payload['clear_locks']);
        $dataset = $this->rollbackService()->recoveryGuide($clearLocks);
        ResponseEmitter::emit(ApiResponse::json(['dataset' => $dataset]));
        return $this;
    }

    public function logs()
    {
        $this->requireCreator();
        $payload = $this->payload();
        $limit = (int) ($payload['limit'] ?? 20);
        if ($limit <= 0) {
            $limit = 20;
        }
        $dataset = [
            'updates' => $this->logService()->listUpdates($limit),
            'runtime' => $this->logService()->statusSnapshot(),
        ];
        ResponseEmitter::emit(ApiResponse::json(['dataset' => $dataset]));
        return $this;
    }
}
