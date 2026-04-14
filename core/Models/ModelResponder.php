<?php

declare(strict_types=1);

namespace Core\Models;

use Core\Http\ApiResponse;
use Core\Http\ResponseEmitter;

class ModelResponder
{
    public function itemResponse($dataset): array
    {
        return [
            'dataset' => $dataset,
        ];
    }

    public function deleteResponse(): array
    {
        return [];
    }

    public function emitJson(array $payload, bool $echo, int $status = 200, int $jsonFlags = 0): void
    {
        if ($echo) {
            ResponseEmitter::emit(ApiResponse::json($payload, $status, [], $jsonFlags));
        }
    }
}
