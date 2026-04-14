<?php

declare(strict_types=1);

use App\Models\GuildRole;

use App\Services\GuildRoleAdminService;
use Core\Http\InputValidator;
use Core\Http\RequestData;

use Core\Logging\LegacyLoggerAdapter;
use Core\Logging\LoggerInterface;

class GuildRoles extends GuildRole
{
    /** @var LoggerInterface|null */
    private $logger = null;
    /** @var GuildRoleAdminService|null */
    private $guildRoleAdminService = null;

    public function setLogger(LoggerInterface $logger = null)
    {
        $this->logger = $logger;
        return $this;
    }

    public function setGuildRoleAdminService(GuildRoleAdminService $service = null)
    {
        $this->guildRoleAdminService = $service;
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

    private function guildRoleAdminService(): GuildRoleAdminService
    {
        if ($this->guildRoleAdminService instanceof GuildRoleAdminService) {
            return $this->guildRoleAdminService;
        }

        $this->guildRoleAdminService = new GuildRoleAdminService();
        return $this->guildRoleAdminService;
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
        $this->guildRoleAdminService()->create($data);

        return $this;
    }

    public function update()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();

        $data = $this->requestDataObject();
        $this->guildRoleAdminService()->update($data);

        return $this;
    }

    public function delete($operator = '=')
    {
        $this->requireAdmin();
        return parent::delete($operator);
    }
}
