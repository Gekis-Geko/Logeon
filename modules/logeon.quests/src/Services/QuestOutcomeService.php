<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database\DbAdapterInterface;

class QuestOutcomeService
{
    /** @var QuestResolverService */
    private $resolver;

    public function __construct(DbAdapterInterface $db = null, QuestResolverService $resolver = null)
    {
        $this->resolver = $resolver ?: new QuestResolverService($db);
    }

    public function list(int $definitionId): array
    {
        return $this->resolver->listOutcomes($definitionId);
    }

    public function upsert(int $definitionId, array $payload): array
    {
        return $this->resolver->upsertOutcome($definitionId, $payload);
    }

    public function delete(int $definitionId, int $outcomeId): array
    {
        return $this->resolver->deleteOutcome($definitionId, $outcomeId);
    }
}
