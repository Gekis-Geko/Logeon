<?php

declare(strict_types=1);

namespace App\Services;

class AuthPasswordPolicy
{
    public const MIN_LENGTH = 10;
    public const MAX_LENGTH = 72;

    /**
     * @return array{valid:bool,error_code:string,message:string}
     */
    public static function validate(string $password, string $confirm = '', bool $requireConfirm = true): array
    {
        $length = strlen($password);
        if ($length === 0) {
            return [
                'valid' => false,
                'error_code' => 'password_required',
                'message' => 'La password e obbligatoria.',
            ];
        }

        if ($length < self::MIN_LENGTH) {
            return [
                'valid' => false,
                'error_code' => 'password_too_short',
                'message' => 'La password deve contenere almeno ' . self::MIN_LENGTH . ' caratteri.',
            ];
        }

        if ($length > self::MAX_LENGTH) {
            return [
                'valid' => false,
                'error_code' => 'password_too_long',
                'message' => 'La password non puo superare ' . self::MAX_LENGTH . ' caratteri.',
            ];
        }

        if (!preg_match('/[a-z]/', $password)) {
            return [
                'valid' => false,
                'error_code' => 'password_missing_lowercase',
                'message' => 'La password deve includere almeno una lettera minuscola.',
            ];
        }

        if (!preg_match('/[A-Z]/', $password)) {
            return [
                'valid' => false,
                'error_code' => 'password_missing_uppercase',
                'message' => 'La password deve includere almeno una lettera maiuscola.',
            ];
        }

        if (!preg_match('/[0-9]/', $password)) {
            return [
                'valid' => false,
                'error_code' => 'password_missing_number',
                'message' => 'La password deve includere almeno un numero.',
            ];
        }

        if (!preg_match('/[^A-Za-z0-9]/', $password)) {
            return [
                'valid' => false,
                'error_code' => 'password_missing_symbol',
                'message' => 'La password deve includere almeno un simbolo.',
            ];
        }

        if ($requireConfirm && !hash_equals($password, $confirm)) {
            return [
                'valid' => false,
                'error_code' => 'password_confirm_mismatch',
                'message' => 'Le password non corrispondono.',
            ];
        }

        return [
            'valid' => true,
            'error_code' => '',
            'message' => '',
        ];
    }
}
