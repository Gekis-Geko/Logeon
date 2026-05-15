<?php

declare(strict_types=1);

namespace App\Services\Update;

use Core\ReleaseInfo;

class UpdateDistributionService
{
    /**
     * @return array<string,mixed>
     */
    public function status(): array
    {
        $status = ReleaseInfo::status();
        $status['manifest_url'] = $this->manifestUrl();
        $status['manifest_timeout_seconds'] = $this->manifestTimeoutSeconds();
        return $status;
    }

    public function manifestUrl(): string
    {
        $updates = (array) (APP['updates'] ?? []);
        return trim((string) ($updates['manifest_url'] ?? ''));
    }

    public function manifestTimeoutSeconds(): int
    {
        $updates = (array) (APP['updates'] ?? []);
        $seconds = (int) ($updates['manifest_timeout_seconds'] ?? 8);
        if ($seconds < 2) {
            $seconds = 2;
        }
        if ($seconds > 30) {
            $seconds = 30;
        }
        return $seconds;
    }

}
