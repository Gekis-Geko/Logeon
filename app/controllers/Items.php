<?php

declare(strict_types=1);

use App\Models\Item;
use App\Services\ItemRarityAdminService;
use App\Services\ItemService;

use Core\Http\ApiResponse;
use Core\Http\InputValidator;
use Core\Http\RequestData;

use Core\Http\ResponseEmitter;
use Core\Logging\LegacyLoggerAdapter;
use Core\Logging\LoggerInterface;

class Items extends Item
{
    /** @var LoggerInterface|null */
    private $logger = null;
    /** @var ItemService|null */
    private $itemService = null;
    /** @var ItemRarityAdminService|null */
    private $itemRarityAdminService = null;

    public function setLogger(LoggerInterface $logger = null)
    {
        $this->logger = $logger;
        return $this;
    }

    public function setItemService(ItemService $itemService = null)
    {
        $this->itemService = $itemService;
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

    private function itemService(): ItemService
    {
        if ($this->itemService instanceof ItemService) {
            return $this->itemService;
        }

        $this->itemService = new ItemService();
        return $this->itemService;
    }

    private function itemRarityAdminService(): ItemRarityAdminService
    {
        if ($this->itemRarityAdminService instanceof ItemRarityAdminService) {
            return $this->itemRarityAdminService;
        }

        $this->itemRarityAdminService = new ItemRarityAdminService();
        return $this->itemRarityAdminService;
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

    public function create()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();

        $data = $this->requestDataObject();
        if (property_exists($data, 'metadata_json')) {
            unset($data->metadata_json);
        }
        $this->checkDataset($data);

        $this->itemService()->create($data);

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
        $this->checkDataset($data);

        $this->itemService()->update($data);

        return $this;
    }

    public function adminList()
    {
        $this->requireAdmin();
        return parent::list();
    }

    public function adminDelete()
    {
        $this->requireAdmin();
        return parent::delete();
    }

    public function adminTypesList()
    {
        $this->requireAdmin();
        $types = $this->itemService()->listDistinctTypes();
        ResponseEmitter::emit(ApiResponse::json(['dataset' => $types]));
        return $this;
    }

    public function adminRaritiesList()
    {
        $this->requireAdmin();
        $dataset = $this->itemService()->listRarityOptions(false);
        ResponseEmitter::emit(ApiResponse::json(['dataset' => $dataset]));
        return $this;
    }

    public function adminRaritiesAdminList()
    {
        $this->requireAdmin();
        $data = $this->requestDataObject();
        $result = $this->itemRarityAdminService()->list($data);
        ResponseEmitter::emit(ApiResponse::json($result));
        return $this;
    }

    public function adminRarityCreate()
    {
        $this->requireAdmin();
        $data = $this->requestDataObject();
        $this->itemRarityAdminService()->create($data);
        return $this;
    }

    public function adminRarityUpdate()
    {
        $this->requireAdmin();
        $data = $this->requestDataObject();
        $this->itemRarityAdminService()->update($data);
        return $this;
    }

    public function adminRarityDelete()
    {
        $this->requireAdmin();
        $data = $this->requestDataObject();
        $this->itemRarityAdminService()->delete((int) ($data->id ?? 0));
        return $this;
    }
}
