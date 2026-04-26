<?php

declare(strict_types=1);

namespace Modules\Logeon\Archetypes\Contracts;

interface ArchetypeProviderInterface
{
    /**
     * @return array{archetypes_enabled:int,archetype_required:int,multiple_archetypes_allowed:int}
     */
    public function getConfig(): array;

    /**
     * @return array{archetypes_enabled:int,archetype_required:int,multiple_archetypes_allowed:int}
     */
    public function updateConfig(object $data): array;

    /**
     * @return array{config:array{archetypes_enabled:int,archetype_required:int,multiple_archetypes_allowed:int},dataset:array<int,array<string,mixed>>}
     */
    public function publicList(): array;

    /**
     * @return array<int,array<string,mixed>>
     */
    public function getCharacterArchetypes(int $characterId): array;

    public function assignArchetype(int $characterId, int $archetypeId, bool $multipleAllowed = false): void;

    public function removeArchetype(int $characterId, int $archetypeId): void;

    public function clearCharacterArchetypes(int $characterId): void;

    /**
     * @param  array<int> $archetypeIds
     * @return array<int>
     */
    public function validateSelectableArchetypes(array $archetypeIds): array;

    /**
     * @param  array<string,mixed> $filters
     * @return array{total:int,page:int,limit:int,rows:array<int,array<string,mixed>>}
     */
    public function adminList(array $filters = [], int $limit = 20, int $page = 1, string $sort = 'sort_order|ASC'): array;

    /**
     * @return array<string,mixed>
     */
    public function adminGet(int $id): array;

    /**
     * @return array<string,mixed>
     */
    public function adminCreate(object $data): array;

    /**
     * @return array<string,mixed>
     */
    public function adminUpdate(object $data): array;

    public function adminDelete(int $id): void;
}
