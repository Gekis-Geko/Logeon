<?php

declare(strict_types=1);

use App\Models\ItemsCategory;

use App\Services\ItemCategoryService;
use Core\Http\InputValidator;
use Core\Http\RequestData;


use Core\Logging\LoggerInterface;

class ItemsCategories extends ItemsCategory
{
    /** @var LoggerInterface|null */
    private $logger = null;
    /** @var ItemCategoryService|null */
    private $itemCategoryService = null;

    public function setLogger(LoggerInterface $logger = null)
    {
        $this->logger = $logger;
        return $this;
    }

    public function setItemCategoryService(ItemCategoryService $itemCategoryService = null)
    {
        $this->itemCategoryService = $itemCategoryService;
        return $this;
    }

    private function logger(): LoggerInterface
    {
        if ($this->logger instanceof LoggerInterface) {
            return $this->logger;
        }

        $this->logger = \Core\AppContext::logger();
        return $this->logger;
    }

    protected function trace($message, $context = false): void
    {
        $this->logger()->trace($message, $context);
    }

    private function itemCategoryService(): ItemCategoryService
    {
        if ($this->itemCategoryService instanceof ItemCategoryService) {
            return $this->itemCategoryService;
        }

        $this->itemCategoryService = new ItemCategoryService();
        return $this->itemCategoryService;
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
        $this->itemCategoryService()->create($data);

        return $this;
    }

    public function update()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();

        $data = $this->requestDataObject();
        $this->itemCategoryService()->update($data);

        return $this;
    }

    public function delete($operator = '=')
    {
        $this->requireAdmin();
        return parent::delete($operator);
    }
}


