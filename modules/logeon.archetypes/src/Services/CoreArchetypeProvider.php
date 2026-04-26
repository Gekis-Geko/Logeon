<?php

declare(strict_types=1);

namespace Modules\Logeon\Archetypes\Services;

use Modules\Logeon\Archetypes\Contracts\ArchetypeProviderInterface;

class CoreArchetypeProvider implements ArchetypeProviderInterface
{
    private ArchetypeService $service;

    public function __construct(ArchetypeService $service = null)
    {
        $this->service = $service ?: new ArchetypeService();
    }

    public function getConfig(): array
    {
        return $this->service->getConfig();
    }

    public function updateConfig(object $data): array
    {
        return $this->service->updateConfig($data);
    }

    public function publicList(): array
    {
        return $this->service->publicList();
    }

    public function getCharacterArchetypes(int $characterId): array
    {
        return $this->service->getCharacterArchetypes($characterId);
    }

    public function assignArchetype(int $characterId, int $archetypeId, bool $multipleAllowed = false): void
    {
        $this->service->assignArchetype($characterId, $archetypeId, $multipleAllowed);
    }

    public function removeArchetype(int $characterId, int $archetypeId): void
    {
        $this->service->removeArchetype($characterId, $archetypeId);
    }

    public function clearCharacterArchetypes(int $characterId): void
    {
        $this->service->clearCharacterArchetypes($characterId);
    }

    public function validateSelectableArchetypes(array $archetypeIds): array
    {
        return $this->service->validateSelectableArchetypes($archetypeIds);
    }

    public function adminList(array $filters = [], int $limit = 20, int $page = 1, string $sort = 'sort_order|ASC'): array
    {
        return $this->service->adminList($filters, $limit, $page, $sort);
    }

    public function adminGet(int $id): array
    {
        return $this->service->adminGet($id);
    }

    public function adminCreate(object $data): array
    {
        return $this->service->adminCreate($data);
    }

    public function adminUpdate(object $data): array
    {
        return $this->service->adminUpdate($data);
    }

    public function adminDelete(int $id): void
    {
        $this->service->adminDelete($id);
    }
}
