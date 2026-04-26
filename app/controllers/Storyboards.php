<?php

declare(strict_types=1);

use App\Models\Storyboard;
use App\Services\StoryboardService;
use Core\HtmlSanitizer;
use Core\Http\ApiResponse;
use Core\Http\AppError;
use Core\Http\InputValidator;
use Core\Http\RequestData;
use Core\Http\ResponseEmitter;


use Core\Logging\LoggerInterface;

class Storyboards extends Storyboard
{
    /** @var LoggerInterface|null */
    private $logger = null;
    /** @var StoryboardService|null */
    private $storyboardService = null;

    public function setLogger(LoggerInterface $logger = null)
    {
        $this->logger = $logger;
        return $this;
    }

    public function setStoryboardService(StoryboardService $storyboardService = null)
    {
        $this->storyboardService = $storyboardService;
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

    private function storyboardService(): StoryboardService
    {
        if ($this->storyboardService instanceof StoryboardService) {
            return $this->storyboardService;
        }

        $this->storyboardService = new StoryboardService();
        return $this->storyboardService;
    }

    protected function trace($message, $context = false): void
    {
        $this->logger()->trace($message, $context);
    }

    private function failValidation($message, string $errorCode = '')
    {
        $message = (string) $message;
        if ($errorCode === '') {
            $errorCode = $this->resolveValidationErrorCode($message);
        }
        throw AppError::validation($message, [], $errorCode);
    }

    private function resolveValidationErrorCode(string $message): string
    {
        $map = [
            'Capitolo e sottocapitolo gia presenti.' => 'storyboard_duplicate',
            'Dati non validi' => 'payload_invalid',
            'Titolo obbligatorio' => 'title_required',
        ];

        return $map[$message] ?? 'validation_error';
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

        $request = RequestData::fromGlobals();
        $data = InputValidator::postJsonObject($request, 'data', false, 'Dati non validi', 'payload_invalid');
        $title = InputValidator::string($data, 'title');
        if ($title === '') {
            $this->failValidation('Titolo obbligatorio');
        }
        $body = HtmlSanitizer::sanitize(InputValidator::string($data, 'body'), ['allow_images' => true]);
        $this->storyboardService()->create(
            InputValidator::integer($data, 'chapter'),
            InputValidator::integer($data, 'subchapter'),
            $title,
            $body,
        );

        return $this;
    }

    public function update()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();

        $request = RequestData::fromGlobals();
        $data = InputValidator::postJsonObject($request, 'data', false, 'Dati non validi', 'payload_invalid');
        $id = InputValidator::positiveInt($data, 'id', 'Dati non validi', 'payload_invalid');
        $title = InputValidator::string($data, 'title');
        if ($title === '') {
            $this->failValidation('Titolo obbligatorio');
        }
        $body = HtmlSanitizer::sanitize(InputValidator::string($data, 'body'), ['allow_images' => true]);
        $this->storyboardService()->update(
            $id,
            InputValidator::integer($data, 'chapter'),
            InputValidator::integer($data, 'subchapter'),
            $title,
            $body,
        );

        return $this;
    }

    public function delete($operator = '=')
    {
        $this->requireAdmin();
        return parent::delete($operator);
    }

    public function publicList()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);

        $response = [
            'chapters' => $this->storyboardService()->listPublicChapters(),
        ];

        return ResponseEmitter::emit(ApiResponse::json($response));
    }
}


