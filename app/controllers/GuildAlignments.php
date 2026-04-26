<?php

declare(strict_types=1);

use App\Models\GuildAlignment;

use App\Services\GuildAlignmentAdminService;
use Core\Http\InputValidator;
use Core\Http\RequestData;


use Core\Logging\LoggerInterface;

class GuildAlignments extends GuildAlignment
{
    /** @var LoggerInterface|null */
    private $logger = null;
    /** @var GuildAlignmentAdminService|null */
    private $guildAlignmentAdminService = null;

    public function setLogger(LoggerInterface $logger = null)
    {
        $this->logger = $logger;
        return $this;
    }

    public function setGuildAlignmentAdminService(GuildAlignmentAdminService $service = null)
    {
        $this->guildAlignmentAdminService = $service;
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

    private function guildAlignmentAdminService(): GuildAlignmentAdminService
    {
        if ($this->guildAlignmentAdminService instanceof GuildAlignmentAdminService) {
            return $this->guildAlignmentAdminService;
        }

        $this->guildAlignmentAdminService = new GuildAlignmentAdminService();
        return $this->guildAlignmentAdminService;
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
        $this->guildAlignmentAdminService()->create($data);

        return $this;
    }

    public function update()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();

        $data = $this->requestDataObject();
        $this->guildAlignmentAdminService()->update($data);

        return $this;
    }

    public function delete($operator = '=')
    {
        $this->requireAdmin();
        return parent::delete($operator);
    }
}


