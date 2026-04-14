<?php

declare(strict_types=1);

use App\Models\EquipmentSlot;
use App\Services\EquipmentSlotAdminService;
use Core\Http\ApiResponse;
use Core\Http\InputValidator;
use Core\Http\RequestData;
use Core\Http\ResponseEmitter;

use Core\Logging\LegacyLoggerAdapter;
use Core\Logging\LoggerInterface;

class EquipmentSlots extends EquipmentSlot
{
    /** @var LoggerInterface|null */
    private $logger = null;
    /** @var EquipmentSlotAdminService|null */
    private $equipmentSlotAdminService = null;

    public function setLogger(LoggerInterface $logger = null)
    {
        $this->logger = $logger;
        return $this;
    }

    public function setEquipmentSlotAdminService(EquipmentSlotAdminService $service = null)
    {
        $this->equipmentSlotAdminService = $service;
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

    private function equipmentSlotAdminService(): EquipmentSlotAdminService
    {
        if ($this->equipmentSlotAdminService instanceof EquipmentSlotAdminService) {
            return $this->equipmentSlotAdminService;
        }

        $this->equipmentSlotAdminService = new EquipmentSlotAdminService();
        return $this->equipmentSlotAdminService;
    }

    private function requestDataObject()
    {
        $request = RequestData::fromGlobals();
        return InputValidator::postJsonObject($request, 'data', true);
    }

    private function requireAdmin()
    {
        \Core\AuthGuard::api()->requireAbility('settings.manage');
    }

    private function emitJson(array $payload): void
    {
        ResponseEmitter::emit(ApiResponse::json($payload));
    }

    public function list($echo = true)
    {
        $this->requireAdmin();
        return parent::list($echo);
    }

    public function adminSimpleList()
    {
        $this->requireAdmin();
        $dataset = $this->equipmentSlotAdminService()->listActive();
        ResponseEmitter::emit(ApiResponse::json(['dataset' => $dataset]));
        return $this;
    }

    public function create()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();

        $this->equipmentSlotAdminService()->create($this->requestDataObject());
        return $this;
    }

    public function update()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();

        $this->equipmentSlotAdminService()->update($this->requestDataObject());
        return $this;
    }

    public function delete($operator = '=')
    {
        $this->requireAdmin();
        $impact = $this->equipmentSlotAdminService()->delete($this->requestDataObject());
        $this->emitJson([
            'success' => true,
            'message' => 'Slot equipaggiamento eliminato',
            'impact' => $impact,
        ]);
        return $this;
    }
}
