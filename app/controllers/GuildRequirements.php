<?php

declare(strict_types=1);

use App\Models\GuildRequirement;

use App\Services\GuildRequirementAdminService;
use Core\Http\InputValidator;
use Core\Http\RequestData;


use Core\Logging\LoggerInterface;

class GuildRequirements extends GuildRequirement
{
    /** @var LoggerInterface|null */
    private $logger = null;
    /** @var GuildRequirementAdminService|null */
    private $guildRequirementAdminService = null;

    public function setLogger(LoggerInterface $logger = null)
    {
        $this->logger = $logger;
        return $this;
    }

    public function setGuildRequirementAdminService(GuildRequirementAdminService $service = null)
    {
        $this->guildRequirementAdminService = $service;
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

    private function guildRequirementAdminService(): GuildRequirementAdminService
    {
        if ($this->guildRequirementAdminService instanceof GuildRequirementAdminService) {
            return $this->guildRequirementAdminService;
        }

        $this->guildRequirementAdminService = new GuildRequirementAdminService();
        return $this->guildRequirementAdminService;
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
        $this->guildRequirementAdminService()->create($data);

        return $this;
    }

    public function update()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();

        $data = $this->requestDataObject();
        $this->guildRequirementAdminService()->update($data);

        return $this;
    }

    public function delete($operator = '=')
    {
        $this->requireAdmin();
        return parent::delete($operator);
    }
}


