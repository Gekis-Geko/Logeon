<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database\DbAdapterInterface;

class QuestAssignmentService
{
    /** @var QuestResolverService */
    private $resolver;

    public function __construct(DbAdapterInterface $db = null, QuestResolverService $resolver = null)
    {
        $this->resolver = $resolver ?: new QuestResolverService($db);
    }

    public function assign(array $payload, int $actorCharacterId): array
    {
        return $this->resolver->assignInstance($payload, $actorCharacterId);
    }

    public function listForGame(
        array $filters,
        int $viewerCharacterId,
        bool $isStaff,
        array $viewerFactionIds,
        array $viewerGuildIds,
        int $limit = 30,
        int $page = 1,
    ): array {
        return $this->resolver->listForGame(
            $filters,
            $viewerCharacterId,
            $isStaff,
            $viewerFactionIds,
            $viewerGuildIds,
            $limit,
            $page,
        );
    }

    public function getForGame(int $definitionId, int $viewerCharacterId, bool $isStaff, array $viewerFactionIds, array $viewerGuildIds): array
    {
        return $this->resolver->getForGame($definitionId, $viewerCharacterId, $isStaff, $viewerFactionIds, $viewerGuildIds);
    }

    public function joinForGame(int $definitionId, array $payload, int $viewerCharacterId, bool $isStaff): array
    {
        return $this->resolver->joinForGame($definitionId, $payload, $viewerCharacterId, $isStaff);
    }

    public function leaveForGame(int $definitionId, array $payload, int $viewerCharacterId, bool $isStaff): array
    {
        return $this->resolver->leaveForGame($definitionId, $payload, $viewerCharacterId, $isStaff);
    }

    public function listInstancesForStaff(array $filters, int $limit = 30, int $page = 1, string $sort = 'id|DESC'): array
    {
        return $this->resolver->listInstancesForStaff($filters, $limit, $page, $sort);
    }

    public function viewerFactionIds(int $characterId): array
    {
        return $this->resolver->viewerFactionIds($characterId);
    }

    public function viewerGuildIds(int $characterId): array
    {
        return $this->resolver->viewerGuildIds($characterId);
    }
}
