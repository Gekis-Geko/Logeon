<?php

declare(strict_types=1);

use Core\Http\ApiResponse;
use Core\Http\AppError;
use Core\Http\InputValidator;
use Core\Http\RequestData;
use Core\Http\ResponseEmitter;
use Core\ModuleRuntime;

class Modules
{
    private function requestDataObject()
    {
        $request = RequestData::fromGlobals();
        return InputValidator::postJsonObject($request, 'data', true);
    }

    private function emitJson(array $payload): void
    {
        ResponseEmitter::emit(ApiResponse::json($payload));
    }

    private function requireAdmin(): void
    {
        \Core\AuthGuard::api()->requireAbility('settings.manage');
    }

    private function readModuleId($data): string
    {
        return InputValidator::firstString($data, ['module_id', 'id'], '');
    }

    private function readBool($data, string $key): bool
    {
        return InputValidator::boolean($data, $key, false);
    }

    public function list()
    {
        $this->requireAdmin();
        $dataset = ModuleRuntime::instance()->manager()->listModules();
        $this->emitJson(['dataset' => $dataset]);
        return $this;
    }

    public function activate()
    {
        $this->requireAdmin();
        $data = $this->requestDataObject();
        $moduleId = $this->readModuleId($data);
        if ($moduleId === '') {
            throw AppError::validation('Modulo non valido', [], 'module_not_found');
        }

        $result = ModuleRuntime::instance()->manager()->activate($moduleId);
        if (empty($result['ok'])) {
            throw AppError::validation(
                (string) ($result['message'] ?? 'Attivazione modulo non riuscita'),
                (array) ($result['payload'] ?? []),
                (string) ($result['error_code'] ?? 'module_activation_failed'),
            );
        }

        $this->emitJson(['dataset' => $result['dataset'] ?? ['module_id' => $moduleId, 'status' => 'active']]);
        return $this;
    }

    public function deactivate()
    {
        $this->requireAdmin();
        $data = $this->requestDataObject();
        $moduleId = $this->readModuleId($data);
        $cascade = $this->readBool($data, 'cascade');
        if ($moduleId === '') {
            throw AppError::validation('Modulo non valido', [], 'module_not_found');
        }

        $result = ModuleRuntime::instance()->manager()->deactivate($moduleId, [
            'cascade' => $cascade ? 1 : 0,
        ]);
        if (empty($result['ok'])) {
            throw AppError::validation(
                (string) ($result['message'] ?? 'Disattivazione modulo non riuscita'),
                (array) ($result['payload'] ?? []),
                (string) ($result['error_code'] ?? 'module_activation_failed'),
            );
        }

        $this->emitJson(['dataset' => $result['dataset'] ?? ['module_id' => $moduleId, 'status' => 'inactive']]);
        return $this;
    }

    public function uninstall()
    {
        $this->requireAdmin();
        $data = $this->requestDataObject();
        $moduleId = $this->readModuleId($data);
        $purge = $this->readBool($data, 'purge');

        if ($moduleId === '') {
            throw AppError::validation('Modulo non valido', [], 'module_not_found');
        }

        $result = ModuleRuntime::instance()->manager()->uninstall($moduleId, [
            'purge' => $purge ? 1 : 0,
        ]);
        if (empty($result['ok'])) {
            throw AppError::validation(
                (string) ($result['message'] ?? 'Disinstallazione modulo non riuscita'),
                (array) ($result['payload'] ?? []),
                (string) ($result['error_code'] ?? 'module_uninstall_failed'),
            );
        }

        $this->emitJson(['dataset' => $result['dataset'] ?? ['module_id' => $moduleId, 'status' => 'detected']]);
        return $this;
    }

    public function audit()
    {
        $this->requireAdmin();
        $result = ModuleRuntime::instance()->manager()->audit();
        if (empty($result['ok'])) {
            throw AppError::validation(
                (string) ($result['message'] ?? 'Audit moduli non riuscito'),
                (array) ($result['payload'] ?? []),
                (string) ($result['error_code'] ?? 'module_audit_failed'),
            );
        }

        $this->emitJson(['dataset' => $result['dataset'] ?? []]);
        return $this;
    }
}
