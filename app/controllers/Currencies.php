<?php

declare(strict_types=1);

use App\Models\Currency;

use App\Services\CurrencyAdminService;
use Core\Http\InputValidator;
use Core\Http\RequestData;

use Core\Logging\LegacyLoggerAdapter;
use Core\Logging\LoggerInterface;

class Currencies extends Currency
{
    /** @var LoggerInterface|null */
    private $logger = null;
    /** @var CurrencyAdminService|null */
    private $currencyAdminService = null;

    public function setLogger(LoggerInterface $logger = null)
    {
        $this->logger = $logger;
        return $this;
    }

    public function setCurrencyAdminService(CurrencyAdminService $currencyAdminService = null)
    {
        $this->currencyAdminService = $currencyAdminService;
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

    private function currencyAdminService(): CurrencyAdminService
    {
        if ($this->currencyAdminService instanceof CurrencyAdminService) {
            return $this->currencyAdminService;
        }

        $this->currencyAdminService = new CurrencyAdminService();
        return $this->currencyAdminService;
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
        $this->currencyAdminService()->create($data);

        return $this;
    }

    public function update()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();

        $data = $this->requestDataObject();
        $this->currencyAdminService()->update($data);

        return $this;
    }

    public function delete($operator = '=')
    {
        $this->requireAdmin();
        return parent::delete($operator);
    }
}
