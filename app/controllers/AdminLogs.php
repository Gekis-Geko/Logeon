<?php

declare(strict_types=1);

use App\Services\LogConflictsAdminService;
use App\Services\LogCurrencyAdminService;
use App\Services\LogExperienceAdminService;
use App\Services\LogFameAdminService;
use App\Services\LogGuildAdminService;
use App\Services\LogJobAdminService;
use App\Services\LogLocationAccessAdminService;
use App\Services\LogNarrativeAdminService;
use App\Services\LogSysAdminService;
use Core\Http\ApiResponse;
use Core\Http\InputValidator;
use Core\Http\RequestData;
use Core\Http\ResponseEmitter;

class AdminLogs
{
    /** @var LogConflictsAdminService|null */
    private $logConflictsAdminService = null;

    /** @var LogCurrencyAdminService|null */
    private $logCurrencyAdminService = null;

    /** @var LogExperienceAdminService|null */
    private $logExperienceAdminService = null;

    /** @var LogFameAdminService|null */
    private $logFameAdminService = null;

    /** @var LogGuildAdminService|null */
    private $logGuildAdminService = null;

    /** @var LogJobAdminService|null */
    private $logJobAdminService = null;

    /** @var LogLocationAccessAdminService|null */
    private $logLocationAccessAdminService = null;

    /** @var LogNarrativeAdminService|null */
    private $logNarrativeAdminService = null;

    /** @var LogSysAdminService|null */
    private $logSysAdminService = null;

    private function logConflictsAdminService(): LogConflictsAdminService
    {
        if ($this->logConflictsAdminService instanceof LogConflictsAdminService) {
            return $this->logConflictsAdminService;
        }

        $this->logConflictsAdminService = new LogConflictsAdminService();
        return $this->logConflictsAdminService;
    }

    private function logCurrencyAdminService(): LogCurrencyAdminService
    {
        if ($this->logCurrencyAdminService instanceof LogCurrencyAdminService) {
            return $this->logCurrencyAdminService;
        }

        $this->logCurrencyAdminService = new LogCurrencyAdminService();
        return $this->logCurrencyAdminService;
    }

    private function logExperienceAdminService(): LogExperienceAdminService
    {
        if ($this->logExperienceAdminService instanceof LogExperienceAdminService) {
            return $this->logExperienceAdminService;
        }

        $this->logExperienceAdminService = new LogExperienceAdminService();
        return $this->logExperienceAdminService;
    }

    private function logFameAdminService(): LogFameAdminService
    {
        if ($this->logFameAdminService instanceof LogFameAdminService) {
            return $this->logFameAdminService;
        }

        $this->logFameAdminService = new LogFameAdminService();
        return $this->logFameAdminService;
    }

    private function logGuildAdminService(): LogGuildAdminService
    {
        if ($this->logGuildAdminService instanceof LogGuildAdminService) {
            return $this->logGuildAdminService;
        }

        $this->logGuildAdminService = new LogGuildAdminService();
        return $this->logGuildAdminService;
    }

    private function logJobAdminService(): LogJobAdminService
    {
        if ($this->logJobAdminService instanceof LogJobAdminService) {
            return $this->logJobAdminService;
        }

        $this->logJobAdminService = new LogJobAdminService();
        return $this->logJobAdminService;
    }

    private function logLocationAccessAdminService(): LogLocationAccessAdminService
    {
        if ($this->logLocationAccessAdminService instanceof LogLocationAccessAdminService) {
            return $this->logLocationAccessAdminService;
        }

        $this->logLocationAccessAdminService = new LogLocationAccessAdminService();
        return $this->logLocationAccessAdminService;
    }

    private function logNarrativeAdminService(): LogNarrativeAdminService
    {
        if ($this->logNarrativeAdminService instanceof LogNarrativeAdminService) {
            return $this->logNarrativeAdminService;
        }

        $this->logNarrativeAdminService = new LogNarrativeAdminService();
        return $this->logNarrativeAdminService;
    }

    private function logSysAdminService(): LogSysAdminService
    {
        if ($this->logSysAdminService instanceof LogSysAdminService) {
            return $this->logSysAdminService;
        }

        $this->logSysAdminService = new LogSysAdminService();
        return $this->logSysAdminService;
    }

    private function requireAdmin(): void
    {
        \Core\AuthGuard::api()->requireUser();
        if (!\Core\AppContext::authContext()->isAdmin() && !\Core\AppContext::authContext()->isSuperuser()) {
            throw new \Core\Http\AppError('Accesso riservato ad admin e superuser', 403);
        }
    }

    private function requestDataObject()
    {
        $request = RequestData::fromGlobals();
        return InputValidator::postJsonObject($request, 'data', true);
    }

    public function listConflicts()
    {
        $this->requireAdmin();
        $data = $this->requestDataObject();
        ResponseEmitter::emit(ApiResponse::json($this->logConflictsAdminService()->list($data)));
    }

    public function listCurrency()
    {
        $this->requireAdmin();
        $data = $this->requestDataObject();
        ResponseEmitter::emit(ApiResponse::json($this->logCurrencyAdminService()->list($data)));
    }

    public function listExperience()
    {
        $this->requireAdmin();
        $data = $this->requestDataObject();
        ResponseEmitter::emit(ApiResponse::json($this->logExperienceAdminService()->list($data)));
    }

    public function listFame()
    {
        $this->requireAdmin();
        $data = $this->requestDataObject();
        ResponseEmitter::emit(ApiResponse::json($this->logFameAdminService()->list($data)));
    }

    public function listGuild()
    {
        $this->requireAdmin();
        $data = $this->requestDataObject();
        ResponseEmitter::emit(ApiResponse::json($this->logGuildAdminService()->list($data)));
    }

    public function listJob()
    {
        $this->requireAdmin();
        $data = $this->requestDataObject();
        ResponseEmitter::emit(ApiResponse::json($this->logJobAdminService()->list($data)));
    }

    public function listLocationAccess()
    {
        $this->requireAdmin();
        $data = $this->requestDataObject();
        ResponseEmitter::emit(ApiResponse::json($this->logLocationAccessAdminService()->list($data)));
    }

    public function listSys()
    {
        $this->requireAdmin();
        $data = $this->requestDataObject();
        $isSuperuser = \Core\AppContext::authContext()->isSuperuser();
        ResponseEmitter::emit(ApiResponse::json($this->logSysAdminService()->list($data, $isSuperuser)));
    }

    public function listNarrative()
    {
        $this->requireAdmin();
        $data = $this->requestDataObject();
        $isSuperuser = \Core\AppContext::authContext()->isSuperuser();
        ResponseEmitter::emit(ApiResponse::json($this->logNarrativeAdminService()->list($data, $isSuperuser)));
    }
}
