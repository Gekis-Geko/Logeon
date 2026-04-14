<?php

declare(strict_types=1);

namespace App\Services;

use Core\Http\AppError;

class ForumService
{
    private function failValidation($message, string $errorCode = 'validation_error'): void
    {
        throw AppError::validation((string) $message, [], $errorCode);
    }

    public function validateDataset($dataset): void
    {
        if (!is_object($dataset)) {
            $this->failValidation('Dati non validi', 'payload_invalid');
        }

        if (property_exists($dataset, 'name') && trim((string) $dataset->name) === '') {
            $this->failValidation('Nome forum obbligatorio', 'forum_name_required');
        }

        if (property_exists($dataset, 'type') && $dataset->type !== null && $dataset->type !== '') {
            $type = (int) $dataset->type;
            if ($type <= 0) {
                $this->failValidation('Tipo forum non valido', 'forum_type_invalid');
            }
        }
    }
}
