<?php

declare(strict_types=1);

namespace Modules\Logeon\Weather\Services;

use Core\Database\DbAdapterInterface;

/**
 * @deprecated Legacy alias kept for compatibility.
 *             New code should use WeatherOverrideService directly.
 */
class WeatherStaffService
{
    /** @var WeatherOverrideService */
    private $override;

    public function __construct(DbAdapterInterface $db = null)
    {
        $this->override = new WeatherOverrideService($db);
    }

    public function locationExists(int $locationId): bool
    {
        return $this->override->locationExists($locationId);
    }

    public function getLocationOverride(int $locationId)
    {
        return $this->override->getLocationOverride($locationId);
    }

    public function deleteLocationOverride(int $locationId): void
    {
        $this->override->deleteLocationOverride($locationId);
    }

    public function upsertLocationOverride(
        int $locationId,
        ?string $weatherKey,
        ?int $degrees,
        ?string $moonPhase,
        int $updatedBy,
    ): void {
        $this->override->upsertLocationOverride($locationId, $weatherKey, $degrees, $moonPhase, $updatedBy, null, null);
    }

    public function getGlobalOverrideRows(): array
    {
        return $this->override->getGlobalOverrideRows();
    }

    public function saveGlobalOverride(?string $weatherKey, ?int $degrees, ?string $moonPhase): void
    {
        $this->override->saveGlobalOverride($weatherKey, $degrees, $moonPhase);
    }
}
