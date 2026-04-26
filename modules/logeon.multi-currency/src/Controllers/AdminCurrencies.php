<?php

declare(strict_types=1);

namespace Modules\Logeon\MultiCurrency\Controllers;

use Core\AuthGuard;
use Core\Http\ApiResponse;
use Core\Http\InputValidator;
use Core\Http\RequestData;
use Core\Http\ResponseEmitter;
use Modules\Logeon\MultiCurrency\Services\AdditionalCurrencyAdminService;

class AdminCurrencies
{
    private ?AdditionalCurrencyAdminService $service = null;

    public function setService(AdditionalCurrencyAdminService $service = null): self
    {
        $this->service = $service;
        return $this;
    }

    private function service(): AdditionalCurrencyAdminService
    {
        if ($this->service instanceof AdditionalCurrencyAdminService) {
            return $this->service;
        }

        $this->service = new AdditionalCurrencyAdminService();
        return $this->service;
    }

    private function requireAdmin(): void
    {
        AuthGuard::api()->requireAbility('settings.manage', [], 'Operazione non autorizzata');
    }

    private function requestDataObject(): object
    {
        return InputValidator::postJsonObject(RequestData::fromGlobals(), 'data', true);
    }

    public function list()
    {
        $this->requireAdmin();
        $data = $this->requestDataObject();
        ResponseEmitter::emit(ApiResponse::json($this->service()->list($data)));
        return $this;
    }

    public function create()
    {
        $this->requireAdmin();
        $data = InputValidator::postJsonObject(
            RequestData::fromGlobals(),
            'data',
            false,
            'Dati mancanti',
            'payload_missing',
        );

        $dataset = $this->service()->create($data);
        ResponseEmitter::emit(ApiResponse::json(['dataset' => $dataset]));
        return $this;
    }

    public function update()
    {
        $this->requireAdmin();
        $data = InputValidator::postJsonObject(
            RequestData::fromGlobals(),
            'data',
            false,
            'Dati mancanti',
            'payload_missing',
        );

        $dataset = $this->service()->update($data);
        ResponseEmitter::emit(ApiResponse::json(['dataset' => $dataset]));
        return $this;
    }

    public function delete()
    {
        $this->requireAdmin();
        $data = InputValidator::postJsonObject(
            RequestData::fromGlobals(),
            'data',
            false,
            'Dati mancanti',
            'payload_missing',
        );

        $id = (int) ($data->id ?? 0);
        $this->service()->delete($id);
        ResponseEmitter::emit(ApiResponse::json(['success' => true]));
        return $this;
    }
}
