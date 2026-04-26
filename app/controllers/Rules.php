<?php

declare(strict_types=1);

use App\Models\Rule;
use App\Services\RuleService;
use Core\HtmlSanitizer;
use Core\Http\ApiResponse;
use Core\Http\AppError;
use Core\Http\InputValidator;
use Core\Http\RequestData;
use Core\Http\ResponseEmitter;


use Core\Logging\LoggerInterface;

class Rules extends Rule
{
    /** @var LoggerInterface|null */
    private $logger = null;
    /** @var RuleService|null */
    private $ruleService = null;

    public function setLogger(LoggerInterface $logger = null)
    {
        $this->logger = $logger;
        return $this;
    }

    public function setRuleService(RuleService $ruleService = null)
    {
        $this->ruleService = $ruleService;
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

    private function ruleService(): RuleService
    {
        if ($this->ruleService instanceof RuleService) {
            return $this->ruleService;
        }

        $this->ruleService = new RuleService();
        return $this->ruleService;
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
            'Regola e sottoregola gia presenti.' => 'rule_duplicate',
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

    public function publicList()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);

        $response = [
            'chapters' => $this->ruleService()->listPublicChapters(),
        ];

        return ResponseEmitter::emit(ApiResponse::json($response));
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
        $this->ruleService()->create(
            InputValidator::integer($data, 'article'),
            InputValidator::integer($data, 'subarticle'),
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
        $this->ruleService()->update(
            $id,
            InputValidator::integer($data, 'article'),
            InputValidator::integer($data, 'subarticle'),
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

    protected function checkDataset($dataset)
    {
    }
}


