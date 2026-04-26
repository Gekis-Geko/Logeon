<?php

declare(strict_types=1);

use App\Services\CurrencyAdminService;
use Core\AuthGuard;
use Core\Http\ApiResponse;
use Core\Http\InputValidator;
use Core\Http\RequestData;
use Core\Http\ResponseEmitter;

class Currencies
{
    /** @var CurrencyAdminService|null */
    private $service = null;

    public function setService(CurrencyAdminService $service = null): self
    {
        $this->service = $service;
        return $this;
    }

    private function service(): CurrencyAdminService
    {
        if ($this->service instanceof CurrencyAdminService) {
            return $this->service;
        }

        $this->service = new CurrencyAdminService();
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
