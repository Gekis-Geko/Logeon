<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database\DbAdapterFactory;
use Core\Database\DbAdapterInterface;
use Core\Http\AppError;

/**
 * Gestisce i PNG Effimeri: NPC temporanei legati a una scena narrativa aperta.
 * Vengono eliminati automaticamente (CASCADE) alla chiusura della scena.
 */
class NarrativeEphemeralNpcService
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

    private function execPrepared(string $sql, array $params = []): void
    {
        $this->db->executePrepared($sql, $params);
    }

    private function rowToArray($row): array
    {
        if (is_object($row)) {
            return (array) $row;
        }
        return is_array($row) ? $row : [];
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Spawna un PNG Effimero all'interno di una scena aperta.
     *
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    public function spawn(int $eventId, array $params): array
    {
        // Verifica che la scena esista e sia aperta
        $event = $this->firstPrepared(
            'SELECT `id`, `event_mode`, `status`, `location_id` FROM `narrative_events` WHERE `id` = ? LIMIT 1',
            [$eventId],
        );
        if (empty($event)) {
            throw AppError::notFound('Scena non trovata.', [], 'scene_not_found');
        }
        $event = $this->rowToArray($event);
        if (($event['event_mode'] ?? '') !== 'scene') {
            throw AppError::validation('Il PNG Effimero può essere spawnato solo in una scena narrativa.', [], 'not_a_scene');
        }
        if (($event['status'] ?? '') !== 'open') {
            throw AppError::validation('La scena è chiusa. Non è possibile spawnare nuovi PNG.', [], 'scene_closed');
        }

        $name = trim((string) ($params['name'] ?? ''));
        if ($name === '') {
            throw AppError::validation('Il nome del PNG è obbligatorio.', [], 'npc_name_required');
        }
        if (mb_strlen($name) > 255) {
            $name = mb_substr($name, 0, 255);
        }

        $description = trim((string) ($params['description'] ?? ''));
        $image = trim((string) ($params['image'] ?? ''));
        $locationId = (int) ($params['location_id'] ?? $event['location_id'] ?? 0);
        $createdBy = (int) ($params['created_by'] ?? 0);

        $this->execPrepared(
            'INSERT INTO `narrative_ephemeral_npcs`
                (`event_id`, `name`, `description`, `image`, `location_id`, `created_by`)
                VALUES (?, ?, ?, ?, ?, ?)',
            [
                $eventId,
                $name,
                $description !== '' ? $description : null,
                $image !== '' ? $image : null,
                $locationId > 0 ? $locationId : null,
                $createdBy > 0 ? $createdBy : null,
            ],
        );

        $newId = (int) $this->db->lastInsertId();
        return $this->get($newId);
    }

    /**
     * Recupera un singolo PNG Effimero per ID.
     *
     * @return array<string,mixed>
     */
    public function get(int $npcId): array
    {
        $row = $this->firstPrepared(
            'SELECT * FROM `narrative_ephemeral_npcs` WHERE `id` = ? LIMIT 1',
            [$npcId],
        );
        if (empty($row)) {
            throw AppError::notFound('PNG Effimero non trovato.', [], 'ephemeral_npc_not_found');
        }
        return $this->rowToArray($row);
    }

    /**
     * Lista PNG Effimeri per scena.
     *
     * @return array<int,array<string,mixed>>
     */
    public function listForEvent(int $eventId): array
    {
        $rows = $this->fetchPrepared(
            'SELECT * FROM `narrative_ephemeral_npcs` WHERE `event_id` = ? ORDER BY `id` ASC',
            [$eventId],
        );
        return array_map([$this, 'rowToArray'], $rows ?: []);
    }

    /**
     * Lista PNG Effimeri per location (filtra quelli nella location, dalle scene aperte).
     *
     * @return array<int,array<string,mixed>>
     */
    public function listForLocation(int $locationId): array
    {
        $rows = $this->fetchPrepared(
            'SELECT en.*
             FROM `narrative_ephemeral_npcs` en
             JOIN `narrative_events` ne ON ne.id = en.event_id
             WHERE en.`location_id` = ?
               AND ne.`event_mode` = \'scene\'
               AND ne.`status` = \'open\'
             ORDER BY en.`id` ASC',
            [$locationId],
        );
        return array_map([$this, 'rowToArray'], $rows ?: []);
    }

    /**
     * Elimina un PNG Effimero.
     * Può eliminare: il creatore, o un actor con privilegio superiore (staff/superuser).
     */
    public function delete(int $npcId, int $actorCharacterId, bool $isPrivileged = false): void
    {
        $row = $this->firstPrepared(
            'SELECT `id`, `created_by` FROM `narrative_ephemeral_npcs` WHERE `id` = ? LIMIT 1',
            [$npcId],
        );
        if (empty($row)) {
            throw AppError::notFound('PNG Effimero non trovato.', [], 'ephemeral_npc_not_found');
        }
        $row = $this->rowToArray($row);
        $createdBy = (int) ($row['created_by'] ?? 0);

        if (!$isPrivileged && $createdBy !== $actorCharacterId) {
            throw AppError::unauthorized('Non hai i permessi per eliminare questo PNG.', [], 'ephemeral_npc_delete_forbidden');
        }

        $this->execPrepared(
            'DELETE FROM `narrative_ephemeral_npcs` WHERE `id` = ?',
            [$npcId],
        );
    }
}
