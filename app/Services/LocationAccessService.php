<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database\DbAdapterFactory;
use Core\Database\DbAdapterInterface;

class LocationAccessService
{
    /** @var DbAdapterInterface */
    private $db;

    public function __construct(DbAdapterInterface $db = null)
    {
        $this->db = $db ?: DbAdapterFactory::createFromConfig();
    }

    public function getLocation($locationId)
    {
        $locationId = (int) $locationId;
        if ($locationId <= 0) {
            return null;
        }

        return $this->db->fetchOnePrepared(
            'SELECT *
             FROM locations
             WHERE id = ?
             LIMIT 1',
            [$locationId],
        );
    }

    public function getCharacter($characterId)
    {
        $characterId = (int) $characterId;
        if ($characterId <= 0) {
            return null;
        }

        return $this->db->fetchOnePrepared(
            'SELECT id, user_id, name, surname, socialstatus_id, fame
             FROM characters
             WHERE id = ?
             LIMIT 1',
            [$characterId],
        );
    }

    public function isInvited($locationId, $characterId)
    {
        $locationId = (int) $locationId;
        $characterId = (int) $characterId;
        if ($locationId <= 0 || $characterId <= 0) {
            return false;
        }

        $row = $this->db->fetchOnePrepared(
            'SELECT id
             FROM location_invites
             WHERE location_id = ?
               AND invited_id = ?
               AND status = "accepted"
             ORDER BY id DESC
             LIMIT 1',
            [$locationId, $characterId],
        );

        return !empty($row);
    }

    public function canAccess($locationId, $characterId, $options = [])
    {
        $location = $this->getLocation($locationId);
        if (empty($location) || !empty($location->date_deleted)) {
            return [
                'allowed' => false,
                'reason' => 'Luogo non trovato',
                'reason_code' => 'not_found',
            ];
        }

        $character = $this->getCharacter($characterId);
        if (empty($character)) {
            return [
                'allowed' => false,
                'reason' => 'Personaggio non valido',
                'reason_code' => 'character_not_found',
            ];
        }

        if (\Core\AppContext::authContext()->isAdmin()) {
            return [
                'allowed' => true,
                'reason' => 'Admin bypass',
                'reason_code' => 'admin_bypass',
            ];
        }

        $ownerId = (int) ($location->owner_id ?? 0);
        $isOwner = ($ownerId > 0 && $ownerId === (int) $character->id);
        $isPrivate = ((int) ($location->is_private ?? 0) === 1);
        $isHouse = ((int) ($location->is_house ?? 0) === 1);
        $isInvited = $this->isInvited($locationId, $characterId);

        if (($isPrivate || $isHouse) && !$isOwner && !$isInvited) {
            return [
                'allowed' => false,
                'reason' => 'Location privata',
                'reason_code' => 'private_location',
            ];
        }

        return [
            'allowed' => true,
            'reason' => 'Accesso consentito',
            'reason_code' => 'ok',
        ];
    }
}
