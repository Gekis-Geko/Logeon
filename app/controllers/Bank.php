<?php

declare(strict_types=1);

use App\Services\BankService;
use Core\AuthGuard;
use Core\Http\ApiResponse;
use Core\Http\InputValidator;
use Core\Http\RequestData;
use Core\Http\ResponseEmitter;

class Bank
{
    /** @var BankService|null */
    private $service = null;

    public function setService(BankService $service = null)
    {
        $this->service = $service;
        return $this;
    }

    private function service(): BankService
    {
        if ($this->service instanceof BankService) {
            return $this->service;
        }

        $this->service = new BankService();
        return $this->service;
    }

    private function requestData(): object
    {
        $request = RequestData::fromGlobals();
        return InputValidator::postJsonObject($request, 'data', true);
    }

    private function emitJson(array $payload): void
    {
        ResponseEmitter::emit(ApiResponse::json($payload));
    }

    private function requireCharacterId(): int
    {
        return (int) AuthGuard::api()->requireCharacter();
    }

    public function summary()
    {
        $characterId = $this->requireCharacterId();
        $data = $this->requestData();
        $limit = max(1, min(100, InputValidator::integer($data, 'limit', 20)));

        $summary = $this->service()->getSummary($characterId, $limit);
        $this->emitJson([
            'success' => true,
            'dataset' => $summary,
        ]);
        return $this;
    }

    public function deposit()
    {
        $characterId = $this->requireCharacterId();
        $data = $this->requestData();
        $amount = InputValidator::string($data, 'amount', '0');

        $result = $this->service()->deposit($characterId, $amount);
        $summary = $this->service()->getSummary($characterId, 20);
        $this->emitJson([
            'success' => true,
            'dataset' => [
                'operation' => 'deposit',
                'result' => $result,
                'summary' => $summary,
            ],
        ]);
        return $this;
    }

    public function withdraw()
    {
        $characterId = $this->requireCharacterId();
        $data = $this->requestData();
        $amount = InputValidator::string($data, 'amount', '0');

        $result = $this->service()->withdraw($characterId, $amount);
        $summary = $this->service()->getSummary($characterId, 20);
        $this->emitJson([
            'success' => true,
            'dataset' => [
                'operation' => 'withdraw',
                'result' => $result,
                'summary' => $summary,
            ],
        ]);
        return $this;
    }

    public function transfer()
    {
        $characterId = $this->requireCharacterId();
        $data = $this->requestData();

        $targetCharacterId = InputValidator::integer($data, 'target_character_id', 0);
        if ($targetCharacterId <= 0) {
            $targetCharacterId = InputValidator::integer($data, 'recipient_character_id', 0);
        }
        if ($targetCharacterId <= 0) {
            $targetCharacterId = InputValidator::integer($data, 'to_character_id', 0);
        }

        $amount = InputValidator::string($data, 'amount', '0');
        $note = InputValidator::string($data, 'note', '');

        $result = $this->service()->transfer($characterId, $targetCharacterId, $amount, $note);
        $summary = $this->service()->getSummary($characterId, 20);
        $this->emitJson([
            'success' => true,
            'dataset' => [
                'operation' => 'transfer',
                'result' => $result,
                'summary' => $summary,
            ],
        ]);
        return $this;
    }
}
