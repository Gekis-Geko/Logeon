<?php

declare(strict_types=1);

use App\Models\Forum;
use App\Services\ForumAdminService;
use App\Services\ForumService;
use Core\Http\InputValidator;
use Core\Http\RequestData;
use Core\Logging\LegacyLoggerAdapter;

use Core\Logging\LoggerInterface;

class Forums extends Forum
{
    /** @var LoggerInterface|null */
    private $logger = null;
    /** @var ForumService|null */
    private $forumService = null;
    /** @var ForumAdminService|null */
    private $forumAdminService = null;

    public function setLogger(LoggerInterface $logger = null)
    {
        $this->logger = $logger;
        return $this;
    }

    public function setForumService(ForumService $forumService = null)
    {
        $this->forumService = $forumService;
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

    private function forumService(): ForumService
    {
        if ($this->forumService instanceof ForumService) {
            return $this->forumService;
        }

        $this->forumService = new ForumService();
        return $this->forumService;
    }

    private function forumAdminService(): ForumAdminService
    {
        if ($this->forumAdminService instanceof ForumAdminService) {
            return $this->forumAdminService;
        }

        $this->forumAdminService = new ForumAdminService();
        return $this->forumAdminService;
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

    public function list($echo = true)
    {
        $this->requireCharacter();
        return parent::list($echo);
    }

    public function getByID($echo = true)
    {
        $this->requireCharacter();
        return parent::getByID($echo);
    }

    public function create()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireCharacter();

        $data = $this->requestDataObject();
        $this->forumService()->validateDataset($data);

        return $this;
    }

    public function update()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireCharacter();

        $data = $this->requestDataObject();
        $this->forumService()->validateDataset($data);

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
        $result = $this->forumAdminService()->list($data);
        \Core\Http\ResponseEmitter::json($result);
    }

    public function adminTypesList()
    {
        $this->requireAdmin();
        $types = $this->forumAdminService()->getTypes();
        \Core\Http\ResponseEmitter::json(['types' => $types]);
    }

    public function adminCreate()
    {
        $this->requireAdmin();
        $data = $this->requestDataObject();
        $this->forumAdminService()->create($data);
        \Core\Http\ResponseEmitter::json(['ok' => true]);
    }

    public function adminUpdate()
    {
        $this->requireAdmin();
        $data = $this->requestDataObject();
        $this->forumAdminService()->update($data);
        \Core\Http\ResponseEmitter::json(['ok' => true]);
    }

    public function adminDelete()
    {
        $this->requireAdmin();
        $data = $this->requestDataObject();
        $id = (int) ($data->id ?? 0);
        $this->forumAdminService()->delete($id);
        \Core\Http\ResponseEmitter::json(['ok' => true]);
    }
}
