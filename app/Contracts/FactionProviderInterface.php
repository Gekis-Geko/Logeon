<?php

declare(strict_types=1);

namespace App\Contracts;

interface FactionProviderInterface
{
    /**
     * @return array<int>
     */
    public function getMembershipsForCharacter(int $characterId): array;

    /**
     * @param array<int> $factionIds
     * @return array<int>
     */
    public function getActiveCharacterIdsForFactions(array $factionIds): array;

    public function joinEventAsFaction(int $factionId, int $eventId, int $characterId): bool;

    public function leaveEventAsFaction(int $factionId, int $eventId, int $characterId): bool;

    public function inviteFactionToEvent(int $factionId, int $eventId, int $inviterCharacterId): bool;

    public function existsById(int $id): bool;

    public function getNameById(int $id): ?string;

    /**
     * @return array<int,array{id:int,label:string,secondary:string}>
     */
    public function search(string $needle, int $limit): array;
}
