<?php

declare(strict_types=1);

use App\Models\HowToPlay;
use App\Services\HowToPlayService;
use Core\HtmlSanitizer;
use Core\Http\ApiResponse;
use Core\Http\AppError;
use Core\Http\InputValidator;
use Core\Http\RequestData;
use Core\Http\ResponseEmitter;


use Core\Logging\LoggerInterface;

class HowToPlays extends HowToPlay
{
    /** @var LoggerInterface|null */
    private $logger = null;
    /** @var HowToPlayService|null */
    private $howToPlayService = null;

    public function setLogger(LoggerInterface $logger = null)
    {
        $this->logger = $logger;
        return $this;
    }

    public function setHowToPlayService(HowToPlayService $howToPlayService = null)
    {
        $this->howToPlayService = $howToPlayService;
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

    private function howToPlayService(): HowToPlayService
    {
        if ($this->howToPlayService instanceof HowToPlayService) {
            return $this->howToPlayService;
        }

        $this->howToPlayService = new HowToPlayService();
        return $this->howToPlayService;
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
            'Passo e sottopasso gia presenti.' => 'how_to_play_duplicate',
            'Dati non validi' => 'payload_invalid',
            'Titolo obbligatorio' => 'title_required',
        ];

        return $map[$message] ?? 'validation_error';
    }

    private function requireAdmin()
    {
        \Core\AuthGuard::api()->requireAbility('settings.manage');
    }

    private function resolveStepValue($data): int
    {
        if (isset($data->step)) {
            return (int) $data->step;
        }
        if (isset($data->chapter)) {
            return (int) $data->chapter;
        }
        return 0;
    }

    private function resolveSubstepValue($data): int
    {
        if (isset($data->substep)) {
            return (int) $data->substep;
        }
        if (isset($data->subchapter)) {
            return (int) $data->subchapter;
        }
        return 0;
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
        $this->howToPlayService()->create(
            $this->resolveStepValue($data),
            $this->resolveSubstepValue($data),
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
        $this->howToPlayService()->update(
            $id,
            $this->resolveStepValue($data),
            $this->resolveSubstepValue($data),
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
            'chapters' => $this->howToPlayService()->listPublicChapters(),
        ];

        return ResponseEmitter::emit(ApiResponse::json($response));
    }
}


