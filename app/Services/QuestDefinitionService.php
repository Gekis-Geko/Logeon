<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database\DbAdapterInterface;

class QuestDefinitionService
{
    /** @var QuestResolverService */
    private $resolver;

    public function __construct(DbAdapterInterface $db = null, QuestResolverService $resolver = null)
    {
        $this->resolver = $resolver ?: new QuestResolverService($db);
    }

    public function listForAdmin(array $filters = [], int $limit = 20, int $page = 1, string $sort = 'id|DESC'): array
    {
        return $this->resolver->listDefinitionsForAdmin($filters, $limit, $page, $sort);
    }

    public function getDetail(int $definitionId): array
    {
        return $this->resolver->getDefinitionDetail($definitionId);
    }

    public function create(array $payload, int $actorCharacterId): array
    {
        return $this->resolver->createDefinition($payload, $actorCharacterId);
    }

    public function update(int $definitionId, array $payload, int $actorCharacterId): array
    {
        return $this->resolver->updateDefinition($definitionId, $payload, $actorCharacterId);
    }

    public function setStatus(int $definitionId, string $status, int $actorCharacterId): array
    {
        return $this->resolver->setDefinitionStatus($definitionId, $status, $actorCharacterId);
    }

    public function delete(int $definitionId): array
    {
        return $this->resolver->deleteDefinition($definitionId);
    }

    public function reorder(array $items): array
    {
        return $this->resolver->reorderDefinitions($items);
    }

    public function listSteps(int $definitionId): array
    {
        return $this->resolver->listSteps($definitionId);
    }

    public function upsertStep(int $definitionId, array $payload): array
    {
        return $this->resolver->upsertStep($definitionId, $payload);
    }

    public function deleteStep(int $definitionId, int $stepId): array
    {
        return $this->resolver->deleteStep($definitionId, $stepId);
    }

    public function reorderSteps(int $definitionId, array $items): array
    {
        return $this->resolver->reorderSteps($definitionId, $items);
    }

    public function listConditions(array $filters): array
    {
        return $this->resolver->listConditions($filters);
    }

    public function upsertCondition(array $payload): array
    {
        return $this->resolver->upsertCondition($payload);
    }

    public function deleteCondition(int $conditionId): array
    {
        return $this->resolver->deleteCondition($conditionId);
    }

    public function listOutcomes(int $definitionId): array
    {
        return $this->resolver->listOutcomes($definitionId);
    }

    public function upsertOutcome(int $definitionId, array $payload): array
    {
        return $this->resolver->upsertOutcome($definitionId, $payload);
    }

    public function deleteOutcome(int $definitionId, int $outcomeId): array
    {
        return $this->resolver->deleteOutcome($definitionId, $outcomeId);
    }

    public function listLinks(int $definitionId = 0, int $instanceId = 0): array
    {
        return $this->resolver->listLinks($definitionId, $instanceId);
    }

    public function upsertLink(array $payload, int $actorCharacterId): array
    {
        return $this->resolver->upsertLink($payload, $actorCharacterId);
    }

    public function deleteLink(int $linkId): array
    {
        return $this->resolver->deleteLink($linkId);
    }
}
