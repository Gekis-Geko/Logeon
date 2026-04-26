<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\FactionProviderInterface;

class CoreFactionProvider implements FactionProviderInterface
{
    /**
     * Fallback core no-op: con modulo OFF nessuna membership fazione disponibile.
     *
     * @return array<int>
     */
    public function getMembershipsForCharacter(int $characterId): array
    {
        return [];
    }

    /**
     * Fallback core no-op: con modulo OFF nessun mapping membri/fazioni disponibile.
     *
     * @param array<int> $factionIds
     * @return array<int>
     */
    public function getActiveCharacterIdsForFactions(array $factionIds): array
    {
        return [];
    }

    /**
     * Fallback core no-op: con modulo OFF le operazioni evento/fazione sono disattivate.
     */
    public function joinEventAsFaction(int $factionId, int $eventId, int $characterId): bool
    {
        return false;
    }

    /**
     * Fallback core no-op: con modulo OFF le operazioni evento/fazione sono disattivate.
     */
    public function leaveEventAsFaction(int $factionId, int $eventId, int $characterId): bool
    {
        return false;
    }

    /**
     * Fallback core no-op: con modulo OFF le operazioni evento/fazione sono disattivate.
     */
    public function inviteFactionToEvent(int $factionId, int $eventId, int $inviterCharacterId): bool
    {
        return false;
    }

    public function existsById(int $id): bool
    {
        return false;
    }

    public function getNameById(int $id): ?string
    {
        return null;
    }

    /**
     * @return array<int,array{id:int,label:string,secondary:string}>
     */
    public function search(string $needle, int $limit): array
    {
        return [];
    }
}
