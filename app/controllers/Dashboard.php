<?php

declare(strict_types=1);

use App\Services\DashboardService;
use Core\Http\ApiResponse;
use Core\Http\InputValidator;
use Core\Http\RequestData;
use Core\Http\ResponseEmitter;

class Dashboard
{
    /** @var DashboardService */
    private $dashboardService;

    public function __construct(DashboardService $dashboardService = null)
    {
        $this->dashboardService = $dashboardService ?: new DashboardService();
    }

    private function requireAdmin()
    {
        \Core\AuthGuard::api()->requireAbility('settings.manage');
    }

    private function requestDataObject($default = null)
    {
        $request = RequestData::fromGlobals();
        return InputValidator::postJsonObject($request, 'data', true);
    }

    public function dashboardSummary()
    {
        $this->requireAdmin();

        $data = $this->requestDataObject((object) []);
        $payload = $this->dashboardService->buildSummary($data->period ?? '30d');

        ResponseEmitter::emit(ApiResponse::json($payload));
    }
}
