<?php

declare(strict_types=1);

use App\Models\Thread;
use App\Services\ThreadService;
use Core\Http\ApiResponse;
use Core\Http\AppError;
use Core\Http\InputValidator;
use Core\Http\RequestData;
use Core\Http\ResponseEmitter;


use Core\Logging\LoggerInterface;

class Threads extends Thread
{
    /** @var LoggerInterface|null */
    private $logger = null;
    /** @var ThreadService|null */
    private $threadService = null;

    public function setLogger(LoggerInterface $logger = null)
    {
        $this->logger = $logger;
        return $this;
    }

    public function setThreadService(ThreadService $threadService = null)
    {
        $this->threadService = $threadService;
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

    private function threadService(): ThreadService
    {
        if ($this->threadService instanceof ThreadService) {
            return $this->threadService;
        }

        $this->threadService = new ThreadService();
        return $this->threadService;
    }

    private function failValidation($message, string $errorCode = 'validation_error')
    {
        throw AppError::validation((string) $message, [], $errorCode);
    }

    private function failThreadInvalid(): void
    {
        $this->failValidation('Thread non valido', 'thread_invalid');
    }

    private function requestDataObject()
    {
        $request = RequestData::fromGlobals();
        return InputValidator::postJsonObject($request, 'data', true);
    }

    private function requireCharacter(): int
    {
        $guard = \Core\AuthGuard::api();
        $guard->requireUser();
        return $guard->requireCharacter();
    }

    private function enforceWritePermission()
    {
        $userId = \Core\AuthGuard::api()->requireUser();
        \Core\AuthGuard::enforceNotRestricted($userId, 'Il tuo account e ristretto: non puoi scrivere nel forum');
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
        $character_id = $this->requireCharacter();
        $this->enforceWritePermission();

        $data = $this->requestDataObject();
        $this->checkDataset($data);
        $this->threadService()->create($data, (int) $character_id);

        return $this;
    }

    public function update()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $character_id = $this->requireCharacter();
        $this->enforceWritePermission();

        $data = $this->requestDataObject();
        $this->checkDataset($data);
        $this->threadService()->update($data, (int) $character_id, \Core\AppContext::authContext()->isAdmin());

        return $this;
    }

    public function answer()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $character_id = $this->requireCharacter();
        $this->enforceWritePermission();

        $data = $this->requestDataObject();
        $this->checkDataset($data);
        $this->threadService()->answer($data, (int) $character_id);

        return $this;
    }

    public function lock()
    {
        $this->trace('Richiamato il medoto: ' . __METHOD__);
        $this->requireCharacter();
        \Core\AuthGuard::api()->requireAbility('forum.admin', [], 'Operazione non autorizzata');

        $data = $this->requestDataObject();
        $this->checkDataset($data);
        $id = (int) ($data->id ?? 0);
        $this->threadService()->setClosed($id, true);
    }

    public function unlock()
    {
        $this->trace('Richiamato il medoto: ' . __METHOD__);
        $this->requireCharacter();
        \Core\AuthGuard::api()->requireAbility('forum.admin', [], 'Operazione non autorizzata');

        $data = $this->requestDataObject();
        $this->checkDataset($data);
        $id = (int) ($data->id ?? 0);
        $this->threadService()->setClosed($id, false);
    }

    public function important()
    {
        $this->trace('Richiamato il medoto: ' . __METHOD__);
        $this->requireCharacter();
        \Core\AuthGuard::api()->requireAbility('forum.admin', [], 'Operazione non autorizzata');

        $data = $this->requestDataObject();
        $this->checkDataset($data);
        $id = (int) ($data->id ?? 0);
        $this->threadService()->setImportant($id, true);
    }

    public function common()
    {
        $this->trace('Richiamato il medoto: ' . __METHOD__);
        $this->requireCharacter();
        \Core\AuthGuard::api()->requireAbility('forum.admin', [], 'Operazione non autorizzata');

        $data = $this->requestDataObject();
        $this->checkDataset($data);
        $id = (int) ($data->id ?? 0);
        $this->threadService()->setImportant($id, false);
    }

    public function move()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireCharacter();
        \Core\AuthGuard::api()->requireAbility('forum.admin', [], 'Operazione non autorizzata');

        $data = $this->requestDataObject();
        $threadId = (int) ($data->id ?? 0);
        $forumId = (int) ($data->forum_id ?? 0);

        if ($threadId <= 0) {
            $this->failThreadInvalid();
        }

        $this->threadService()->move($threadId, $forumId);

        ResponseEmitter::emit(ApiResponse::json(['success' => true]));
        return $this;
    }

    public function delete($operator = '=')
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $character_id = $this->requireCharacter();

        $data = $this->requestDataObject();
        $id = isset($data->id) ? (int) $data->id : 0;
        if ($id <= 0) {
            $this->failThreadInvalid();
        }

        $this->threadService()->delete($id, (int) $character_id, \Core\AppContext::authContext()->isAdmin());

        ResponseEmitter::emit(ApiResponse::json([
            'success' => true,
        ]));

        return true;
    }

    protected function checkDataset($dataset)
    {
    }
}


