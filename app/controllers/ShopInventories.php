<?php

declare(strict_types=1);

use App\Services\ShopInventoryAdminService;
use Core\Http\ApiResponse;
use Core\Http\InputValidator;
use Core\Http\RequestData;
use Core\Http\ResponseEmitter;

class ShopInventories
{
    /** @var ShopInventoryAdminService|null */
    private $shopInventoryAdminService = null;

    public function setShopInventoryAdminService(?ShopInventoryAdminService $service = null)
    {
        $this->shopInventoryAdminService = $service;
        return $this;
    }

    private function shopInventoryAdminService(): ShopInventoryAdminService
    {
        if ($this->shopInventoryAdminService instanceof ShopInventoryAdminService) {
            return $this->shopInventoryAdminService;
        }

        $this->shopInventoryAdminService = new ShopInventoryAdminService();
        return $this->shopInventoryAdminService;
    }

    private function requireAdmin(): void
    {
        \Core\AuthGuard::api()->requireAbility('settings.manage');
    }

    private function requestDataObject()
    {
        $request = RequestData::fromGlobals();
        return InputValidator::postJsonObject($request, 'data', true);
    }

    private function emitJson(array $payload): void
    {
        ResponseEmitter::emit(ApiResponse::json($payload));
    }

    public function list()
    {
        $this->requireAdmin();

        $data = $this->requestDataObject();
        $result = $this->shopInventoryAdminService()->list($data);

        $this->emitJson([
            'success' => true,
            'properties' => [
                'query' => $result['query'],
                'page' => (int) $result['page'],
                'results_page' => (int) $result['results_page'],
                'orderBy' => (string) $result['orderBy'],
                'tot' => $result['tot'],
            ],
            'dataset' => $result['dataset'],
        ]);

        return $this;
    }

    public function create()
    {
        $this->requireAdmin();

        $data = $this->requestDataObject();
        $this->shopInventoryAdminService()->create($data);
        $this->emitJson(['success' => true, 'message' => 'Voce inventario creata']);

        return $this;
    }

    public function update()
    {
        $this->requireAdmin();

        $data = $this->requestDataObject();
        $this->shopInventoryAdminService()->update($data);
        $this->emitJson(['success' => true, 'message' => 'Voce inventario aggiornata']);

        return $this;
    }

    public function delete()
    {
        $this->requireAdmin();

        $data = $this->requestDataObject();
        $id = InputValidator::integer($data, 'id', 0);
        $this->shopInventoryAdminService()->delete($id);
        $this->emitJson(['success' => true, 'message' => 'Voce inventario eliminata']);

        return $this;
    }
}
