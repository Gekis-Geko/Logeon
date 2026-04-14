<?php

declare(strict_types=1);

use App\Services\ShopService;
use Core\Http\ApiResponse;
use Core\Http\AppError;
use Core\Http\InputValidator;
use Core\Http\RequestData;
use Core\Http\ResponseEmitter;
use Core\Logging\LegacyLoggerAdapter;
use Core\Logging\LoggerInterface;

class Shop
{
    /** @var LoggerInterface|null */
    private $logger = null;
    /** @var ShopService|null */
    private $shopService = null;

    public function setLogger(LoggerInterface $logger = null)
    {
        $this->logger = $logger;
        return $this;
    }

    public function setShopService(ShopService $shopService = null)
    {
        $this->shopService = $shopService;
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

    private function shopService(): ShopService
    {
        if ($this->shopService instanceof ShopService) {
            return $this->shopService;
        }

        $this->shopService = new ShopService();
        return $this->shopService;
    }

    private function failValidation($message, string $errorCode = 'validation_error')
    {
        throw AppError::validation((string) $message, [], $errorCode);
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

    public function items()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $me = \Core\AuthGuard::api()->requireCharacter();
        $data = $this->requestDataObject();

        $shop = $this->shopService()->resolveShop($data);
        if (empty($shop)) {
            $this->failValidation('Negozio non disponibile', 'shop_unavailable');
        }

        $items = $this->shopService()->listItems($shop->id, $me);

        $sellRatio = $this->shopService()->getSellRatio();
        $discount = $this->shopService()->getSocialDiscount($me);
        $items = $this->shopService()->decorateCatalogItems($items, $discount, $sellRatio);

        $categories = $this->shopService()->listCategories($shop->id);

        $currencies = $this->shopService()->getActiveCurrencies();

        $balances = $this->shopService()->getBalances($me, $currencies);

        $this->emitJson([
            'shop' => $shop,
            'items' => $items,
            'categories' => $categories,
            'currencies' => $currencies,
            'balances' => $balances,
            'social_discount' => $discount,
        ]);
    }

    public function sellables()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $me = \Core\AuthGuard::api()->requireCharacter();
        $sell_ratio = $this->shopService()->getSellRatio();
        $items = $this->shopService()->listSellables($me, $sell_ratio);
        $currency = $this->shopService()->getDefaultCurrency();

        $this->emitJson([
            'items' => $items,
            'currency' => $currency,
            'sell_ratio' => $sell_ratio,
        ]);
    }

    public function buy()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $me = \Core\AuthGuard::api()->requireCharacter();
        $data = $this->requestDataObject();
        $this->shopService()->buy($me, $data);

        $currencies = $this->shopService()->getActiveCurrencies();

        $balances = $this->shopService()->getBalances($me, $currencies);

        $this->emitJson([
            'success' => true,
            'balances' => $balances,
        ]);
    }

    public function sell()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $me = \Core\AuthGuard::api()->requireCharacter();
        $data = $this->requestDataObject();
        $result = $this->shopService()->sell($me, $data);

        $balances = $this->shopService()->getBalances($me, $this->shopService()->getActiveCurrencies());

        $this->emitJson([
            'success' => true,
            'balances' => $balances,
            'sold' => $result['sold'],
        ]);
    }

}
