<?php

declare(strict_types=1);

use App\Models\Nationality;

use Core\Http\InputValidator;
use Core\Http\RequestData;
use Core\Logging\LegacyLoggerAdapter;

use Core\Logging\LoggerInterface;

class Nationalities extends Nationality
{
    /** @var LoggerInterface|null */
    private $logger = null;

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

    private function requestDataObject()
    {
        $request = RequestData::fromGlobals();
        return InputValidator::postJsonObject($request, 'data', true);
    }

    public function create()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);

        $data = $this->requestDataObject();
        $this->checkDataset($data);

        return $this;
    }

    public function update()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);

        $data = $this->requestDataObject();
        $this->checkDataset($data);

        return $this;
    }

    protected function checkDataset($dataset)
    {
    }
}
