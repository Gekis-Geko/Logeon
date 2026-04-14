<?php

declare(strict_types=1);

use App\Services\SettingsService;
use Core\Http\ApiResponse;
use Core\Http\RequestData;
use Core\Http\ResponseEmitter;

class Settings
{
    /** @var SettingsService */
    private $settingsService;

    public function __construct(SettingsService $settingsService = null)
    {
        $this->settingsService = $settingsService ?: new SettingsService();
    }

    private function requireAdmin()
    {
        \Core\AuthGuard::api()->requireAbility('settings.manage');
    }

    public function upload()
    {
        \Core\AuthGuard::api()->requireUser();

        $dataset = $this->settingsService->getUploadDataset();

        return ResponseEmitter::emit(ApiResponse::json([
            'dataset' => $dataset,
        ]));
    }

    public function updateUpload()
    {
        $this->requireAdmin();
        $request = RequestData::fromGlobals();
        $data = $request->postJson('data', [], true);
        $dataset = $this->settingsService->updateUploadSettings($data);

        return ResponseEmitter::emit(ApiResponse::json([
            'dataset' => $dataset,
        ]));
    }

    public function adminGet()
    {
        $this->requireAdmin();
        $dataset = $this->settingsService->getAdminSettingsDataset();
        return ResponseEmitter::emit(ApiResponse::json(['dataset' => $dataset]));
    }

    public function adminUpdate()
    {
        $this->requireAdmin();
        $request = RequestData::fromGlobals();
        $data = $request->postJson('data', [], true);
        $dataset = $this->settingsService->updateAdminSettings($data);
        return ResponseEmitter::emit(ApiResponse::json(['dataset' => $dataset]));
    }

    public function narrativeDelegationGet()
    {
        $this->requireAdmin();
        $dataset = $this->settingsService->getNarrativeDelegationDataset();
        return ResponseEmitter::emit(ApiResponse::json(['dataset' => $dataset]));
    }

    public function narrativeDelegationUpdate()
    {
        $this->requireAdmin();
        $request = RequestData::fromGlobals();
        $data = $request->postJson('data', [], true);
        $dataset = $this->settingsService->updateNarrativeDelegationSettings($data);
        return ResponseEmitter::emit(ApiResponse::json(['dataset' => $dataset]));
    }
}
