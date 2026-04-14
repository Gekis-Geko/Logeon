<?php

declare(strict_types=1);

use App\Services\InventoryService;
use Core\Http\ApiResponse;
use Core\Http\InputValidator;
use Core\Http\RequestData;
use Core\Http\ResponseEmitter;
use Core\Logging\LegacyLoggerAdapter;
use Core\Logging\LoggerInterface;

class Inventory
{
    /** @var LoggerInterface|null */
    private $logger = null;
    /** @var InventoryService|null */
    private $inventoryService = null;

    public function setLogger(LoggerInterface $logger = null)
    {
        $this->logger = $logger;
        return $this;
    }

    public function setInventoryService(InventoryService $inventoryService = null)
    {
        $this->inventoryService = $inventoryService;
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

    private function inventoryService(): InventoryService
    {
        if ($this->inventoryService instanceof InventoryService) {
            return $this->inventoryService;
        }

        $this->inventoryService = new InventoryService();
        return $this->inventoryService;
    }

    private function requestDataObject()
    {
        $request = RequestData::fromGlobals();
        return InputValidator::postJsonObject($request, 'data', true);
    }

    private function parseListRequest()
    {
        $post = $this->requestDataObject();

        $page = max(1, InputValidator::integer($post, 'page', 1));
        $results = max(1, InputValidator::integer($post, 'results', 10));

        $orderBy = InputValidator::string($post, 'orderBy', 'item_name|ASC');

        $query = $post->query ?? null;

        return [$page, $results, $orderBy, $query];
    }

    private function emitJson(array $payload): void
    {
        ResponseEmitter::emit(ApiResponse::json($payload));
    }

    public function bag($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $me = \Core\AuthGuard::api()->requireCharacter();

        [$page, $results, $orderBy, $query] = $this->parseListRequest();
        $bag = $this->inventoryService()->listBag($me, $page, $results, $orderBy, $query);

        $response = [
            'properties' => [
                'query' => $query,
                'page' => $page,
                'results_page' => $results,
                'orderBy' => $orderBy,
                'tot' => $bag['count'],
            ],
            'dataset' => $bag['dataset'],
            'capacity' => $this->inventoryService()->getCapacitySnapshot($me),
        ];

        if (true == $echo) {
            $this->emitJson($response);
        }

        return $response;
    }

    public function categories()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $me = \Core\AuthGuard::api()->requireCharacter();

        $dataset = $this->inventoryService()->getCategoriesByCharacter($me);

        $this->emitJson([
            'dataset' => $dataset,
        ]);
    }

    public function slots()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        \Core\AuthGuard::api()->requireCharacter();

        $slots = $this->inventoryService()->getEquipmentSlots();

        $this->emitJson([
            'slots' => $slots,
        ]);
    }

    public function equipped()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $me = \Core\AuthGuard::api()->requireCharacter();

        $items = $this->inventoryService()->getEquippedByCharacter($me);

        $this->emitJson([
            'items' => $items,
        ]);
    }

    public function available()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $me = \Core\AuthGuard::api()->requireCharacter();

        $items = $this->inventoryService()->getUnequippedEquippables($me);

        $this->emitJson([
            'items' => $items,
        ]);
    }

    public function equip()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $me = \Core\AuthGuard::api()->requireCharacter();
        $data = $this->requestDataObject();
        $instance_id = InputValidator::integer($data, 'character_item_instance_id', 0);
        $inventory_item_id = InputValidator::integer($data, 'inventory_item_id', 0);
        if ($instance_id <= 0 && $inventory_item_id > 0) {
            $instance_id = $this->inventoryService()->resolveInstanceIdByInventoryItem($me, $inventory_item_id);
        }
        $slot = InputValidator::string($data, 'slot', '');
        if ($slot === '') {
            $slot = null;
        }
        $result = $this->inventoryService()->equipStrict($me, $instance_id, $slot);
        $this->emitJson($result);
    }

    public function unequip()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $me = \Core\AuthGuard::api()->requireCharacter();
        $data = $this->requestDataObject();
        $instance_id = InputValidator::integer($data, 'character_item_instance_id', 0);
        $inventory_item_id = InputValidator::integer($data, 'inventory_item_id', 0);
        if ($instance_id <= 0 && $inventory_item_id > 0) {
            $instance_id = $this->inventoryService()->resolveInstanceIdByInventoryItem($me, $inventory_item_id);
        }
        $result = $this->inventoryService()->unequipStrict($me, $instance_id);
        $this->emitJson($result);
    }

    public function destroy()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $me = \Core\AuthGuard::api()->requireCharacter();
        $data = $this->requestDataObject();
        $this->inventoryService()->destroyItem((int) $me, $data);
        $this->emitJson(['success' => true]);
    }

    public function swap()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $me = \Core\AuthGuard::api()->requireCharacter();
        $data = $this->requestDataObject();
        $fromSlot = InputValidator::string($data, 'from_slot', '');
        $toSlot = InputValidator::string($data, 'to_slot', '');
        $result = $this->inventoryService()->swapStrict($me, $fromSlot, $toSlot);
        $this->emitJson($result);
    }

    public function useItem()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $me = \Core\AuthGuard::api()->requireCharacter();
        $data = $this->requestDataObject();
        $inventoryItemId = InputValidator::integer($data, 'inventory_item_id', 0);
        $result = $this->inventoryService()->useItem($me, $inventoryItemId);
        $this->emitJson($result);
    }

    public function reloadItem()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $me = \Core\AuthGuard::api()->requireCharacter();
        $data = $this->requestDataObject();
        $instanceId = InputValidator::integer($data, 'character_item_instance_id', 0);
        $inventoryItemId = InputValidator::integer($data, 'inventory_item_id', 0);
        $result = $this->inventoryService()->reloadItem($me, $instanceId, $inventoryItemId);
        $this->emitJson($result);
    }

    public function maintainItem()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $me = \Core\AuthGuard::api()->requireCharacter();
        $data = $this->requestDataObject();
        $instanceId = InputValidator::integer($data, 'character_item_instance_id', 0);
        $inventoryItemId = InputValidator::integer($data, 'inventory_item_id', 0);
        $result = $this->inventoryService()->maintainItem($me, $instanceId, $inventoryItemId);
        $this->emitJson($result);
    }
}
