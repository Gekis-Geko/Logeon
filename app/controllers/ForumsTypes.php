<?php

declare(strict_types=1);

use App\Models\ForumType;

use App\Services\ForumTypeAdminService;
use Core\Http\InputValidator;
use Core\Http\RequestData;
use Core\Logging\LegacyLoggerAdapter;

use Core\Logging\LoggerInterface;

class ForumsTypes extends ForumType
{
    /** @var ForumTypeAdminService|null */
    private $forumTypeAdminService = null;
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

    private function requireCharacter()
    {
        \Core\AuthGuard::api()->requireUserCharacter();
    }

    private function requireAdmin()
    {
        \Core\AuthGuard::api()->requireAbility('settings.manage');
    }

    private function forumTypeAdminService(): ForumTypeAdminService
    {
        if ($this->forumTypeAdminService instanceof ForumTypeAdminService) {
            return $this->forumTypeAdminService;
        }
        $this->forumTypeAdminService = new ForumTypeAdminService();
        return $this->forumTypeAdminService;
    }

    public function list($echo = true)
    {
        $this->requireCharacter();
        return parent::list($echo);
    }

    public function create()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireCharacter();

        $data = $this->requestDataObject();
        $this->checkDataset($data);

        return $this;
    }

    public function update()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireCharacter();

        $data = $this->requestDataObject();
        $this->checkDataset($data);

        return $this;
    }

    protected function checkDataset($dataset)
    {
    }

    // ── Admin ──────────────────────────────────────────────────────────────────

    public function adminList()
    {
        $this->requireAdmin();
        $data = $this->requestDataObject();
        $result = $this->forumTypeAdminService()->list($data);
        \Core\Http\ResponseEmitter::json($result);
    }

    public function adminCreate()
    {
        $this->requireAdmin();
        $data = $this->requestDataObject();
        $this->forumTypeAdminService()->create($data);
        \Core\Http\ResponseEmitter::json(['ok' => true]);
    }

    public function adminUpdate()
    {
        $this->requireAdmin();
        $data = $this->requestDataObject();
        $this->forumTypeAdminService()->update($data);
        \Core\Http\ResponseEmitter::json(['ok' => true]);
    }

    public function adminDelete()
    {
        $this->requireAdmin();
        $data = $this->requestDataObject();
        $id = InputValidator::integer($data, 'id', 0);
        $this->forumTypeAdminService()->delete($id);
        \Core\Http\ResponseEmitter::json(['ok' => true]);
    }
}
