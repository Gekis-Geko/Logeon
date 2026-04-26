<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database\DbAdapterInterface;

class QuestConditionService
{
    /** @var QuestResolverService */
    private $resolver;

    public function __construct(DbAdapterInterface $db = null, QuestResolverService $resolver = null)
    {
        $this->resolver = $resolver ?: new QuestResolverService($db);
    }

    public function list(array $filters): array
    {
        return $this->resolver->listConditions($filters);
    }

    public function upsert(array $payload): array
    {
        return $this->resolver->upsertCondition($payload);
    }

    public function delete(int $conditionId): array
    {
        return $this->resolver->deleteCondition($conditionId);
    }
}
