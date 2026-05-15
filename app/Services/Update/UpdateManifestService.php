<?php

declare(strict_types=1);

namespace App\Services\Update;

use Core\Http\AppError;

class UpdateManifestService
{
    /**
     * @return array<string,mixed>
     */
    public function fetchAndValidate(string $url, int $timeoutSeconds = 8): array
    {
        $manifestUrl = trim($url);
        if ($manifestUrl === '') {
            throw AppError::validation(
                'URL manifest aggiornamenti non configurato',
                [],
                'update_manifest_unavailable',
            );
        }

        $raw = $this->request($manifestUrl, $timeoutSeconds);
        if (!is_string($raw) || trim($raw) === '') {
            throw AppError::validation(
                'Manifest aggiornamenti non raggiungibile',
                [],
                'update_manifest_unavailable',
            );
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw AppError::validation(
                'Manifest aggiornamenti non valido',
                [],
                'update_manifest_invalid',
            );
        }

        $schema = (int) ($decoded['schema'] ?? 0);
        if ($schema <= 0) {
            throw AppError::validation(
                'Schema manifest non valido',
                [],
                'update_manifest_invalid',
            );
        }

        $project = strtolower(trim((string) ($decoded['project'] ?? '')));
        if ($project !== 'logeon') {
            throw AppError::validation(
                'Manifest appartenente a un progetto diverso',
                [],
                'update_manifest_invalid',
            );
        }

        return $decoded;
    }

    private function request(string $url, int $timeoutSeconds): string
    {
        $timeout = $timeoutSeconds > 0 ? $timeoutSeconds : 8;

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $timeout,
                'ignore_errors' => true,
                'header' => "Accept: application/json\r\nUser-Agent: Logeon-Updater/1.0\r\n",
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $raw = @file_get_contents($url, false, $context);
        if (is_string($raw) && $raw !== '') {
            return $raw;
        }

        if (!function_exists('curl_init')) {
            return '';
        }

        $ch = curl_init($url);
        if ($ch === false) {
            return '';
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: application/json',
            'User-Agent: Logeon-Updater/1.0',
        ]);
        $out = curl_exec($ch);
        curl_close($ch);

        return is_string($out) ? $out : '';
    }
}

