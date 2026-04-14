<?php

declare(strict_types=1);

use App\Services\SocialStatusAdminService;
use Core\Http\ApiResponse;
use Core\Http\AppError;
use Core\Http\InputValidator;
use Core\Http\RequestData;
use Core\Http\ResponseEmitter;
use Core\Logging\LegacyLoggerAdapter;
use Core\Logging\LoggerInterface;

class SocialStatuses
{
    /** @var LoggerInterface|null */
    private $logger = null;
    /** @var SocialStatusAdminService|null */
    private $service = null;

    public function setLogger(LoggerInterface $logger = null)
    {
        $this->logger = $logger;
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

    private function service(): SocialStatusAdminService
    {
        if ($this->service instanceof SocialStatusAdminService) {
            return $this->service;
        }
        $this->service = new SocialStatusAdminService();
        return $this->service;
    }

    private function requireAdmin(): void
    {
        \Core\AuthGuard::api()->requireAbility('settings.manage', [], 'Operazione non autorizzata');
    }

    private function requestDataObject()
    {
        $request = RequestData::fromGlobals();
        return InputValidator::postJsonObject($request, 'data', true);
    }

    public function adminList()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();

        $data = $this->requestDataObject();
        $result = $this->service()->list($data);

        ResponseEmitter::emit(ApiResponse::json($result));
    }

    public function adminCreate()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();

        $data = $this->requestDataObject();
        $name = InputValidator::string($data, 'name', '');
        if ($name === '') {
            throw AppError::validation('Nome obbligatorio', [], 'name_required');
        }

        $this->service()->create($data);

        ResponseEmitter::emit(ApiResponse::json(['success' => true, 'message' => 'Stato sociale creato']));
    }

    public function adminUpdate()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();

        $data = $this->requestDataObject();
        $id = InputValidator::integer($data, 'id', 0);
        $name = InputValidator::string($data, 'name', '');
        if ($id <= 0) {
            throw AppError::validation('ID non valido', [], 'id_invalid');
        }
        if ($name === '') {
            throw AppError::validation('Nome obbligatorio', [], 'name_required');
        }

        $this->service()->update($data);

        ResponseEmitter::emit(ApiResponse::json(['success' => true, 'message' => 'Stato sociale aggiornato']));
    }

    public function adminDelete()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();

        $data = $this->requestDataObject();
        $id = InputValidator::integer($data, 'id', 0);
        if ($id <= 0) {
            throw AppError::validation('ID non valido', [], 'id_invalid');
        }

        $this->service()->delete($id);

        ResponseEmitter::emit(ApiResponse::json(['success' => true, 'message' => 'Stato sociale eliminato']));
    }
}
