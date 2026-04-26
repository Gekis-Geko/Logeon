<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database\DbAdapterInterface;

class QuestProgressService
{
    /** @var QuestResolverService */
    private $resolver;

    public function __construct(DbAdapterInterface $db = null, QuestResolverService $resolver = null)
    {
        $this->resolver = $resolver ?: new QuestResolverService($db);
    }

    public function setInstanceStatus(
        int $instanceId,
        string $status,
        int $actorCharacterId,
        string $sourceType = 'manual',
        ?string $instanceIntensityLevel = null,
    ): array {
        return $this->resolver->setInstanceStatus($instanceId, $status, $actorCharacterId, $sourceType, $instanceIntensityLevel);
    }

    public function setStepStatus(int $instanceId, int $stepInstanceId, string $status, int $actorCharacterId, string $sourceType = 'staff'): array
    {
        return $this->resolver->setStepStatus($instanceId, $stepInstanceId, $status, $actorCharacterId, $sourceType);
    }

    public function confirmStepForStaff(array $payload, int $actorCharacterId): array
    {
        return $this->resolver->confirmStepForStaff($payload, $actorCharacterId);
    }

    public function forceProgress(array $payload, int $actorCharacterId): array
    {
        return $this->resolver->forceProgress($payload, $actorCharacterId);
    }

    public function listLogs(array $filters, int $limit = 50, int $page = 1): array
    {
        return $this->resolver->listLogs($filters, $limit, $page);
    }

    public function maintenanceRun(bool $force = false): array
    {
        return $this->resolver->maintenanceRun($force);
    }
}
