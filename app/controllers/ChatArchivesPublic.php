<?php

declare(strict_types=1);

use App\Services\ChatArchiveService;
use Core\Http\ApiResponse;
use Core\Http\AppError;
use Core\Http\InputValidator;
use Core\Http\RequestData;
use Core\Http\ResponseEmitter;

class ChatArchivesPublic
{
    private function archiveService(): ChatArchiveService
    {
        return new ChatArchiveService();
    }

    private function requestDataObject()
    {
        $request = RequestData::fromGlobals();
        return InputValidator::postJsonObject($request, 'data', true);
    }

    private function emitJson(array $payload): void
    {
        ResponseEmitter::emit(ApiResponse::json($payload));
    }

    public function show($echo = true)
    {
        $data  = $this->requestDataObject();
        $token = trim(InputValidator::string($data, 'token', ''));

        if ($token === '') {
            throw AppError::notFound('Archivio non trovato', [], 'archive_not_found');
        }

        $result = $this->archiveService()->getByPublicToken($token);
        if ($result === null) {
            throw AppError::notFound('Archivio non trovato', [], 'archive_not_found');
        }

        $response = ['dataset' => $result];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }
}
