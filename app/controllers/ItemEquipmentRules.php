<?php

declare(strict_types=1);

use App\Models\ItemEquipmentRule;
use App\Services\ItemEquipmentRuleAdminService;
use Core\Http\InputValidator;
use Core\Http\RequestData;

use Core\Logging\LegacyLoggerAdapter;
use Core\Logging\LoggerInterface;

class ItemEquipmentRules extends ItemEquipmentRule
{
    /** @var LoggerInterface|null */
    private $logger = null;
    /** @var ItemEquipmentRuleAdminService|null */
    private $itemEquipmentRuleAdminService = null;

    public function setLogger(LoggerInterface $logger = null)
    {
        $this->logger = $logger;
        return $this;
    }

    public function setItemEquipmentRuleAdminService(ItemEquipmentRuleAdminService $service = null)
    {
        $this->itemEquipmentRuleAdminService = $service;
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

    private function itemEquipmentRuleAdminService(): ItemEquipmentRuleAdminService
    {
        if ($this->itemEquipmentRuleAdminService instanceof ItemEquipmentRuleAdminService) {
            return $this->itemEquipmentRuleAdminService;
        }

        $this->itemEquipmentRuleAdminService = new ItemEquipmentRuleAdminService();
        return $this->itemEquipmentRuleAdminService;
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

    public function list($echo = true)
    {
        $this->requireAdmin();
        return parent::list($echo);
    }

    public function create()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();

        $data = $this->requestDataObject();
        if (property_exists($data, 'metadata_json')) {
            unset($data->metadata_json);
        }

        $this->itemEquipmentRuleAdminService()->create($data);
        return $this;
    }

    public function update()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();

        $data = $this->requestDataObject();
        if (property_exists($data, 'metadata_json')) {
            unset($data->metadata_json);
        }

        $this->itemEquipmentRuleAdminService()->update($data);
        return $this;
    }

    public function delete($operator = '=')
    {
        $this->requireAdmin();
        return parent::delete($operator);
    }
}
