<?php

declare(strict_types=1);

use App\Models\GuildRoleLocation;

use App\Services\GuildRoleLocationAdminService;
use Core\Http\InputValidator;
use Core\Http\RequestData;

use Core\Logging\LegacyLoggerAdapter;
use Core\Logging\LoggerInterface;

class GuildRoleLocations extends GuildRoleLocation
{
    /** @var LoggerInterface|null */
    private $logger = null;
    /** @var GuildRoleLocationAdminService|null */
    private $guildRoleLocationAdminService = null;

    public function setLogger(LoggerInterface $logger = null)
    {
        $this->logger = $logger;
        return $this;
    }

    public function setGuildRoleLocationAdminService(GuildRoleLocationAdminService $service = null)
    {
        $this->guildRoleLocationAdminService = $service;
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

    private function guildRoleLocationAdminService(): GuildRoleLocationAdminService
    {
        if ($this->guildRoleLocationAdminService instanceof GuildRoleLocationAdminService) {
            return $this->guildRoleLocationAdminService;
        }

        $this->guildRoleLocationAdminService = new GuildRoleLocationAdminService();
        return $this->guildRoleLocationAdminService;
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
        $this->guildRoleLocationAdminService()->create($data);

        return $this;
    }

    public function update()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();

        $data = $this->requestDataObject();
        $this->guildRoleLocationAdminService()->update($data);

        return $this;
    }

    public function delete($operator = '=')
    {
        $this->requireAdmin();
        return parent::delete($operator);
    }
}
