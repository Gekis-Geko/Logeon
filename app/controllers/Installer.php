<?php

declare(strict_types=1);

use App\Services\InstallerService;
use Core\Http\ApiResponse;
use Core\Http\AppError;
use Core\Http\RequestData;
use Core\Http\ResponseEmitter;

class Installer
{
    private $service;

    public function __construct()
    {
        $this->service = new InstallerService();
    }

    public function status()
    {
        $state = $this->service->getInstallState();
        $defaults = [
            'app' => $this->service->getDefaultAppConfig(),
            'db' => $this->service->getDefaultDbConfig(),
        ];

        ResponseEmitter::emit(ApiResponse::json([
            'success' => true,
            'installed' => $state['installed'],
            'defaults' => $defaults,
        ]));
    }

    public function validateApp()
    {
        $payload = $this->readPayload();

        $result = $this->service->validateApp($payload['app'] ?? $payload);
        $this->respondResult($result);
    }

    public function testDb()
    {
        $payload = $this->readPayload();

        $result = $this->service->testDb($payload['db'] ?? $payload);
        $this->respondResult($result);
    }

    public function writeConfig()
    {
        $payload = $this->readPayload();

        $app = $payload['app'] ?? [];
        $db = $payload['db'] ?? [];

        $appResult = $this->service->writeAppConfig($app);
        if (!$appResult['ok']) {
            $this->respondResult($appResult);
            return;
        }

        $dbResult = $this->service->writeDbConfig($db);
        if (!$dbResult['ok']) {
            $this->respondResult($dbResult);
            return;
        }

        ResponseEmitter::emit(ApiResponse::json([
            'success' => true,
        ]));
    }

    public function initDb()
    {
        $payload = $this->readPayload();

        $result = $this->service->initDatabase($payload['db'] ?? $payload);
        $this->respondResult($result);
    }

    public function createAdmin()
    {
        $payload = $this->readPayload();

        $result = $this->service->createAdmin($payload);
        $this->respondResult($result);
    }

    public function finalize()
    {
        $result = $this->service->finalizeInstall();
        $this->respondResult($result);
    }

    private function readPayload()
    {
        $request = RequestData::fromGlobals();
        $data = $request->postJson('data', [], true);
        if (!is_array($data)) {
            $this->failValidation('Payload installer non valido.');
        }

        return $data;
    }

    private function respondResult(array $result)
    {
        if (empty($result['ok'])) {
            $this->failValidation((string) ($result['error'] ?? 'Errore installer.'));
        }

        $payload = $result;
        unset($payload['ok']);
        ResponseEmitter::emit(ApiResponse::json(array_merge(['success' => true], $payload)));
    }

    private function failValidation(string $message, string $errorCode = ''): void
    {
        if ($errorCode === '') {
            $errorCode = $this->resolveValidationErrorCode($message);
        }
        throw AppError::validation($message, [], $errorCode);
    }

    private function resolveValidationErrorCode(string $message): string
    {
        $map = [
            'Payload installer non valido.' => 'installer_payload_invalid',
            'Errore installer.' => 'installer_error',
        ];

        return $map[$message] ?? 'installer_error';
    }
}
