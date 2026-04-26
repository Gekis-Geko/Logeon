<?php

declare(strict_types=1);

use App\Models\GuildRoleScope;

use App\Services\GuildRoleScopeAdminService;
use Core\Http\InputValidator;
use Core\Http\RequestData;


use Core\Logging\LoggerInterface;

class GuildRoleScopes extends GuildRoleScope
{
    /** @var LoggerInterface|null */
    private $logger = null;
    /** @var GuildRoleScopeAdminService|null */
    private $guildRoleScopeAdminService = null;

    public function setLogger(LoggerInterface $logger = null)
    {
        $this->logger = $logger;
        return $this;
    }

    public function setGuildRoleScopeAdminService(GuildRoleScopeAdminService $service = null)
    {
        $this->guildRoleScopeAdminService = $service;
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

    private function guildRoleScopeAdminService(): GuildRoleScopeAdminService
    {
        if ($this->guildRoleScopeAdminService instanceof GuildRoleScopeAdminService) {
            return $this->guildRoleScopeAdminService;
        }

        $this->guildRoleScopeAdminService = new GuildRoleScopeAdminService();
        return $this->guildRoleScopeAdminService;
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
        $this->guildRoleScopeAdminService()->create($data);

        return $this;
    }

    public function update()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();

        $data = $this->requestDataObject();
        $this->guildRoleScopeAdminService()->update($data);

        return $this;
    }

    public function delete($operator = '=')
    {
        $this->requireAdmin();
        return parent::delete($operator);
    }
}


