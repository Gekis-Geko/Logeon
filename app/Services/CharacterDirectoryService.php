<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database\DbAdapterFactory;
use Core\Database\DbAdapterInterface;

class CharacterDirectoryService
{
    /** @var DbAdapterInterface */
    private $db;

    public function __construct(DbAdapterInterface $db = null)
    {
        $this->db = $db ?: DbAdapterFactory::createFromConfig();
    }

    private function firstPrepared(string $sql, array $params = [])
    {
        return $this->db->fetchOnePrepared($sql, $params);
    }

    private function fetchPrepared(string $sql, array $params = []): array
    {
        return $this->db->fetchAllPrepared($sql, $params);
    }

    private function execPrepared(string $sql, array $params = []): bool
    {
        return $this->db->executePrepared($sql, $params);
    }

    private function queryLength(string $value): int
    {
        if (function_exists('mb_strlen')) {
            return (int) mb_strlen($value, 'UTF-8');
        }

        return strlen($value);
    }

    private function normalizeViewerContext(array $viewer = []): array
    {
        return [
            'character_id' => isset($viewer['character_id']) ? (int) $viewer['character_id'] : 0,
            'is_administrator' => isset($viewer['is_administrator']) ? ((int) $viewer['is_administrator'] === 1 ? 1 : 0) : 0,
            'is_moderator' => isset($viewer['is_moderator']) ? ((int) $viewer['is_moderator'] === 1 ? 1 : 0) : 0,
            'is_master' => isset($viewer['is_master']) ? ((int) $viewer['is_master'] === 1 ? 1 : 0) : 0,
        ];
    }

    private function buildOnlineVisibilityClause(array $viewer, string $characterAlias = 'characters', string $userAlias = 'users'): string
    {
        $viewer = $this->normalizeViewerContext($viewer);
        $isAdmin = ((int) $viewer['is_administrator'] === 1);
        $isModerator = ((int) $viewer['is_moderator'] === 1);
        $isMaster = ((int) $viewer['is_master'] === 1);

        if ($isAdmin) {
            return '1 = 1';
        }

        if ($isModerator || $isMaster) {
            return '(' . $characterAlias . '.is_visible = 1 OR (' . $characterAlias . '.is_visible = 0
                AND ' . $userAlias . '.is_administrator = 0
                AND (' . $userAlias . '.is_moderator = 1 OR ' . $userAlias . '.is_master = 1)
            ))';
        }

        return $characterAlias . '.is_visible = 1';
    }

    public function resolveIdleMinutes(): int
    {
        $idleMinutes = 0;
        $idleRow = $this->firstPrepared("SELECT `value` FROM sys_configs WHERE `key` = 'availability_idle_minutes' LIMIT 1");
        if (!empty($idleRow) && isset($idleRow->value)) {
            $idleMinutes = (int) $idleRow->value;
        }

        if ($idleMinutes <= 0) {
            $idleMinutes = 20;
        }

        return $idleMinutes;
    }

    public function syncAvailabilityByIdleMinutes(int $idleMinutes): void
    {
        if ($idleMinutes <= 0) {
            $idleMinutes = 20;
        }

        $this->execPrepared(
            'UPDATE characters
            SET availability = 2
            WHERE availability = 1
              AND TIMESTAMPDIFF(MINUTE, date_last_seed, NOW()) > ?',
            [$idleMinutes],
        );
    }

    public function getOnlineSummary(int $locationId, int $mapId, array $viewer = []): array
    {
        $viewer = $this->normalizeViewerContext($viewer);

        return [
            'totOnlines' => $this->totOnlines($viewer),
            'inLocation' => $this->inLocation($locationId, $mapId, $viewer),
            'loggedIn' => $this->loggedIn($viewer),
            'loggedOut' => $this->loggedOut($viewer),
        ];
    }

    public function getOnlineComplete(array $viewer = []): array
    {
        $viewer = $this->normalizeViewerContext($viewer);
        $visibilityClause = $this->buildOnlineVisibilityClause($viewer, 'characters', 'users');

        $rows = $this->fetchPrepared(
            'SELECT
                characters.id,
                characters.name,
                characters.surname,
                characters.gender,
                characters.is_visible,
                characters.availability,
                characters.avatar,
                characters.online_status,
                characters.socialstatus_id,
                characters.last_location AS location_id,
                characters.last_map AS map_id,
                locations.name AS location_name,
                maps.name AS map_name,
                maps.icon AS map_icon
            FROM characters
                LEFT JOIN users ON characters.user_id = users.id
                LEFT JOIN locations ON characters.last_location = locations.id
                LEFT JOIN maps ON characters.last_map = maps.id
            WHERE characters.date_last_signin > characters.date_last_signout
                AND DATE_ADD(characters.date_last_seed, INTERVAL 15 MINUTE) > NOW()
                AND ' . $visibilityClause . '
                AND characters.privacy_show_online = 1
            ORDER BY characters.last_map, characters.last_location, characters.name',
        );

        if (empty($rows)) {
            return [];
        }

        $statusMap = [];
        foreach (SocialStatusProviderRegistry::listAll() as $s) {
            $statusMap[(int) $s->id] = $s;
        }
        foreach ($rows as $row) {
            $sid = (int) ($row->socialstatus_id ?? 0);
            $s = $statusMap[$sid] ?? null;
            $row->socialstatus_name = $s->name ?? null;
            $row->socialstatus_icon = $s->icon ?? null;
        }

        return $this->applyLocationVisibilityForViewer($rows, (int) $viewer['character_id']);
    }

    private function applyLocationVisibilityForViewer(array $rows, int $viewerCharacterId): array
    {
        $viewerCharacterId = (int) $viewerCharacterId;

        $locationService = new LocationService($this->db);
        $viewer = ($viewerCharacterId > 0) ? $locationService->getCharacterById($viewerCharacterId) : null;
        $invitedSet = ($viewerCharacterId > 0) ? $locationService->getAcceptedInvitesSet($viewerCharacterId) : [];
        $guildAccessSet = ($viewerCharacterId > 0) ? $locationService->getGuildAccessSet($viewerCharacterId) : [];
        $accessCache = [];

        foreach ($rows as $index => $row) {
            $rows[$index]->can_enter_location = 0;
            $rows[$index]->location_hidden = 0;

            $locationId = isset($row->location_id) ? (int) $row->location_id : 0;
            if ($locationId <= 0) {
                continue;
            }

            if (empty($viewer)) {
                $rows[$index]->location_hidden = 1;
                $rows[$index]->location_id = null;
                $rows[$index]->location_name = null;
                continue;
            }

            if (!array_key_exists($locationId, $accessCache)) {
                $location = $locationService->getLocationForAccess($locationId);
                if (empty($location)) {
                    $accessCache[$locationId] = [
                        'allowed' => false,
                    ];
                } else {
                    $accessCache[$locationId] = $locationService->evaluateAccess($location, $viewer, $invitedSet, $guildAccessSet);
                }
            }

            $access = $accessCache[$locationId];
            $allowed = !empty($access['allowed']);
            $rows[$index]->can_enter_location = $allowed ? 1 : 0;

            if (!$allowed) {
                $rows[$index]->location_hidden = 1;
                $rows[$index]->location_id = null;
                $rows[$index]->location_name = null;
            }
        }

        return $rows;
    }

    public function search(int $excludeCharacterId, string $query, int $limit = 10, $locationId = null, bool $viewerIsStaff = false): array
    {
        $query = trim($query);
        if ($query === '' || $this->queryLength($query) < 2) {
            return [];
        }

        if ($limit <= 0) {
            $limit = 10;
        }

        $locationClause = '';
        $params = [];
        $locationId = ($locationId !== null) ? (int) $locationId : 0;
        if ($locationId > 0) {
            $locationClause = ' AND last_location = ?';
            $params[] = $locationId;
        }

        // Non-staff searchers cannot see invisible characters
        $visibilityClause = $viewerIsStaff ? '' : ' AND is_visible = 1';

        $like = '%' . $query . '%';
        $rows = $this->fetchPrepared(
            'SELECT id, name, surname, avatar
            FROM characters
            WHERE id <> ?
              AND (
                name LIKE ?
                OR surname LIKE ?
                OR CONCAT(name, " ", IFNULL(surname, "")) LIKE ?
              )
              ' . $locationClause . $visibilityClause . '
            ORDER BY name
            LIMIT ?',
            array_merge([$excludeCharacterId, $like, $like, $like], $params, [(int) $limit]),
        );

        return !empty($rows) ? $rows : [];
    }

    private function totOnlines(array $viewer): array
    {
        $visibilityClause = $this->buildOnlineVisibilityClause($viewer, 'characters', 'users');
        $rows = $this->fetchPrepared(
            'SELECT
            COUNT(*) AS tot_onlines
            FROM characters
            LEFT JOIN users ON characters.user_id = users.id
            WHERE characters.date_last_signin > characters.date_last_signout
                AND DATE_ADD(characters.date_last_seed, INTERVAL 20 MINUTE) > NOW()
                AND ' . $visibilityClause . '
                AND characters.privacy_show_online = 1',
        );

        return !empty($rows) ? $rows : [];
    }

    private function inLocation(int $locationId, int $mapId, array $viewer): array
    {
        $visibilityClause = $this->buildOnlineVisibilityClause($viewer, 'characters', 'users');
        $locationFilter = '';
        $params = [];
        if ($locationId > 0) {
            $locationFilter = 'characters.last_location = ? AND characters.last_map = ?';
            $params[] = $locationId;
            $params[] = $mapId;
        } else {
            $locationFilter = '(characters.last_location IS NULL OR characters.last_location = 0)';
        }

        $rows = $this->fetchPrepared(
            'SELECT
            characters.id,
            characters.name,
            characters.surname,
            characters.gender,
            characters.is_visible,
            characters.availability,
            characters.last_location,
            characters.last_map,
            locations.name AS location_name,
            maps.name AS map_name
            FROM characters
                LEFT JOIN users ON characters.user_id = users.id
                LEFT JOIN locations ON characters.last_location = locations.id
                LEFT JOIN maps ON characters.last_map = maps.id
            WHERE (characters.date_last_signin > characters.date_last_signout
                AND DATE_ADD(characters.date_last_seed, INTERVAL 20 MINUTE) > NOW()
                )
                AND ' . $locationFilter . '
                AND ' . $visibilityClause . '
                AND characters.privacy_show_online = 1
            ORDER BY characters.last_location, characters.name',
            $params,
        );

        return !empty($rows) ? $rows : [];
    }

    private function loggedIn(array $viewer): array
    {
        $visibilityClause = $this->buildOnlineVisibilityClause($viewer, 'characters', 'users');
        $rows = $this->fetchPrepared(
            'SELECT
            characters.id,
            characters.name,
            characters.surname,
            characters.gender,
            characters.is_visible,
            characters.availability,
            characters.last_location,
            characters.last_map,
            locations.name AS location_name,
            maps.name AS map_name
            FROM characters
            LEFT JOIN users ON characters.user_id = users.id
            LEFT JOIN locations ON characters.last_location = locations.id
            LEFT JOIN maps ON characters.last_map = maps.id
            WHERE DATE_ADD(characters.date_last_signin, INTERVAL 20 MINUTE) > NOW()
                AND ' . $visibilityClause . '
                AND characters.privacy_show_online = 1
            ORDER BY characters.date_last_signin, characters.name',
        );

        return !empty($rows) ? $rows : [];
    }

    private function loggedOut(array $viewer): array
    {
        $visibilityClause = $this->buildOnlineVisibilityClause($viewer, 'characters', 'users');
        $rows = $this->fetchPrepared(
            'SELECT
            characters.id,
            characters.name,
            characters.surname,
            characters.gender,
            characters.is_visible,
            characters.availability
            FROM characters
            LEFT JOIN users ON characters.user_id = users.id
            WHERE characters.privacy_show_online = 1 AND ' . $visibilityClause . ' AND (
                (
                    characters.date_last_signout > characters.date_last_signin
                AND
                    DATE_ADD(characters.date_last_signout, INTERVAL 20 MINUTE) > NOW()
                ) OR (
                    characters.date_last_signout < characters.date_last_signin
                AND
                    DATE_ADD(characters.date_last_seed, INTERVAL 20 MINUTE) > NOW() AND DATE_ADD(characters.date_last_seed, INTERVAL 19 MINUTE) < NOW()
                )
            )
            ORDER BY characters.date_last_seed, characters.name',
        );

        return !empty($rows) ? $rows : [];
    }
}
