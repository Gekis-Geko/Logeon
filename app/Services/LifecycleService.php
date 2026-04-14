<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database\DbAdapterFactory;
use Core\Database\DbAdapterInterface;
use Core\Http\AppError;

class LifecycleService
{
    /** @var DbAdapterInterface */
    private $db;
    /** @var NotificationService */
    private $notifService;
    /** @var NarrativeDomainService|null */
    private $narrativeDomainService = null;

    private static $validTriggeredBy = ['admin', 'system', 'event', 'combat', 'conflict'];

    public function __construct(DbAdapterInterface $db = null)
    {
        $this->db = $db ?: DbAdapterFactory::createFromConfig();
        $this->notifService = new NotificationService($this->db);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

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

    private function decodePhase(array $row): array
    {
        if (isset($row['meta_json']) && is_string($row['meta_json'])) {
            $decoded = json_decode($row['meta_json'], true);
            $row['meta_json'] = is_array($decoded) ? $decoded : (object) [];
        }
        return $row;
    }

    private function narrativeDomainService(): NarrativeDomainService
    {
        if ($this->narrativeDomainService instanceof NarrativeDomainService) {
            return $this->narrativeDomainService;
        }

        $this->narrativeDomainService = new NarrativeDomainService($this->db);
        return $this->narrativeDomainService;
    }

    private function tryCreateNarrativeEventForTransition(
        int $characterId,
        int $fromPhaseId,
        int $toPhaseId,
        array $targetPhase,
        string $triggeredBy,
        string $notes,
        int $appliedBy,
    ): int {
        try {
            $title = 'Transizione ciclo vitale: ' . (string) ($targetPhase['name'] ?? ('Fase #' . $toPhaseId));
            $description = $notes !== ''
                ? ('Cambio fase registrato. Note: ' . $notes)
                : 'Cambio fase registrato dal sistema lifecycle.';

            $result = $this->narrativeDomainService()->processAction([
                'source_system' => 'lifecycle',
                'source_ref_id' => $characterId,
                'event_type' => 'lifecycle_transition',
                'title' => $title,
                'description' => $description,
                'scope' => 'local',
                'visibility' => 'public',
                'entity_refs' => [
                    ['entity_type' => 'character', 'entity_id' => $characterId, 'role' => 'subject'],
                ],
                'meta_json' => [
                    'triggered_by' => $triggeredBy,
                    'from_phase_id' => $fromPhaseId > 0 ? $fromPhaseId : null,
                    'to_phase_id' => $toPhaseId,
                    'auto_created' => 1,
                ],
                'actor_character_id' => $appliedBy > 0 ? $appliedBy : 0,
            ]);

            return (int) ($result['event_id'] ?? 0);
        } catch (\Throwable $error) {
            // Non bloccare la transizione lifecycle se il dominio narrativo non è disponibile.
            return 0;
        }
    }

    // -------------------------------------------------------------------------
    // Phase definitions — admin CRUD
    // -------------------------------------------------------------------------

    public function adminPhaseList(bool $includeInactive = true): array
    {
        $sql = 'SELECT * FROM `lifecycle_phase_definitions`';
        if (!$includeInactive) {
            $sql .= ' WHERE `is_active` = 1';
        }
        $sql .= ' ORDER BY `sort_order` ASC, `id` ASC';
        $rows = $this->fetchPrepared($sql);
        return array_map(function ($r) {
            return $this->decodePhase($this->rowToArray($r));
        }, $rows);
    }

    public function getPhaseDefinition(int $id): array
    {
        $row = $this->firstPrepared(
            'SELECT * FROM `lifecycle_phase_definitions` WHERE `id` = ? LIMIT 1',
            [(int) $id],
        );
        if (empty($row)) {
            throw AppError::notFound('Fase non trovata', [], 'phase_not_found');
        }
        return $this->decodePhase($this->rowToArray($row));
    }

    public function getPhaseDefinitionByCode(string $code): array
    {
        $row = $this->firstPrepared(
            'SELECT * FROM `lifecycle_phase_definitions` WHERE `code` = ? LIMIT 1',
            [$code],
        );
        if (empty($row)) {
            throw AppError::notFound('Fase non trovata', [], 'phase_not_found');
        }
        return $this->decodePhase($this->rowToArray($row));
    }

    public function adminPhaseCreate(object $data): array
    {
        $code = trim((string) ($data->code ?? ''));
        $name = trim((string) ($data->name ?? ''));
        $description = trim((string) ($data->description ?? ''));
        $category = trim((string) ($data->category ?? ''));
        $sortOrder = (int) ($data->sort_order ?? 0);
        $isInitial = (int) ($data->is_initial ?? 0) === 1 ? 1 : 0;
        $isTerminal = (int) ($data->is_terminal ?? 0) === 1 ? 1 : 0;
        $isActive = (int) ($data->is_active ?? 1) === 1 ? 1 : 0;
        $visPlayers = (int) ($data->visible_to_players ?? 1) === 1 ? 1 : 0;
        $colorHex = trim((string) ($data->color_hex ?? ''));
        $icon = trim((string) ($data->icon ?? ''));

        if ($code === '') {
            throw AppError::validation('Il codice è obbligatorio', [], 'phase_code_required');
        }
        if ($name === '') {
            throw AppError::validation('Il nome è obbligatorio', [], 'phase_name_required');
        }

        $this->execPrepared(
            'INSERT INTO `lifecycle_phase_definitions`
            (`code`,`name`,`description`,`category`,`sort_order`,`is_initial`,`is_terminal`,`is_active`,`visible_to_players`,`color_hex`,`icon`)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $code,
                $name,
                $description !== '' ? $description : null,
                $category !== '' ? $category : null,
                (int) $sortOrder,
                (int) $isInitial,
                (int) $isTerminal,
                (int) $isActive,
                (int) $visPlayers,
                $colorHex !== '' ? $colorHex : null,
                $icon !== '' ? $icon : null,
            ],
        );

        $newId = (int) $this->db->lastInsertId();

        \Core\Hooks::fire('lifecycle.phase_definition.changed', $newId, (bool) $isActive);

        return $this->getPhaseDefinition($newId);
    }

    public function adminPhaseUpdate(object $data): array
    {
        $id = (int) ($data->id ?? 0);
        if ($id <= 0) {
            throw AppError::validation('ID fase obbligatorio', [], 'phase_id_required');
        }

        $this->getPhaseDefinition($id); // ensure exists

        $fields = [];
        $params = [];

        if (isset($data->name)) {
            $fields[] = '`name` = ?';
            $params[] = trim((string) $data->name);
        }
        if (isset($data->description)) {
            $fields[] = '`description` = ?';
            $params[] = $data->description !== '' ? trim((string) $data->description) : null;
        }
        if (isset($data->category)) {
            $fields[] = '`category` = ?';
            $params[] = $data->category !== '' ? trim((string) $data->category) : null;
        }
        if (isset($data->sort_order)) {
            $fields[] = '`sort_order` = ?';
            $params[] = (int) $data->sort_order;
        }
        if (isset($data->is_initial)) {
            $fields[] = '`is_initial` = ?';
            $params[] = ((int) $data->is_initial === 1 ? 1 : 0);
        }
        if (isset($data->is_terminal)) {
            $fields[] = '`is_terminal` = ?';
            $params[] = ((int) $data->is_terminal === 1 ? 1 : 0);
        }
        if (isset($data->is_active)) {
            $fields[] = '`is_active` = ?';
            $params[] = ((int) $data->is_active === 1 ? 1 : 0);
        }
        if (isset($data->visible_to_players)) {
            $fields[] = '`visible_to_players` = ?';
            $params[] = ((int) $data->visible_to_players === 1 ? 1 : 0);
        }
        if (isset($data->color_hex)) {
            $fields[] = '`color_hex` = ?';
            $params[] = $data->color_hex !== '' ? trim((string) $data->color_hex) : null;
        }
        if (isset($data->icon)) {
            $fields[] = '`icon` = ?';
            $params[] = $data->icon !== '' ? trim((string) $data->icon) : null;
        }

        if (!empty($fields)) {
            $params[] = (int) $id;
            $this->execPrepared(
                'UPDATE `lifecycle_phase_definitions` SET ' . implode(', ', $fields) . ' WHERE `id` = ?',
                $params,
            );
        }

        $updated = $this->getPhaseDefinition($id);
        \Core\Hooks::fire('lifecycle.phase_definition.changed', $id, (bool) ($updated['is_active'] ?? false));

        return $updated;
    }

    public function adminPhaseDelete(int $id): void
    {
        $this->getPhaseDefinition($id); // ensure exists

        // Check if any characters are currently in this phase
        $inUseRow = $this->firstPrepared(
            'SELECT COUNT(*) AS n
             FROM `character_lifecycle_transitions` t1
             WHERE t1.to_phase_id = ?
               AND NOT EXISTS (
                   SELECT 1 FROM `character_lifecycle_transitions` t2
                   WHERE t2.character_id = t1.character_id
                     AND t2.id > t1.id
               )',
            [(int) $id],
        );
        $count = (int) ($inUseRow->n ?? 0);
        if ($count > 0) {
            throw AppError::validation(
                'La fase è assegnata a ' . $count . ' personagg' . ($count === 1 ? 'io' : 'i') . ' e non può essere eliminata.',
                [],
                'phase_in_use',
            );
        }

        $this->execPrepared('DELETE FROM `lifecycle_phase_definitions` WHERE `id` = ?', [(int) $id]);
    }

    // -------------------------------------------------------------------------
    // Character phase queries
    // -------------------------------------------------------------------------

    /**
     * Returns the current phase of a character (latest transition).
     * Returns null if the character has no lifecycle transitions.
     */
    public function getCurrentPhase(int $characterId): ?array
    {
        $row = $this->firstPrepared(
            'SELECT t.*, p.code AS phase_code, p.name AS phase_name, p.color_hex, p.icon, p.visible_to_players
             FROM `character_lifecycle_transitions` t
             INNER JOIN `lifecycle_phase_definitions` p ON p.id = t.to_phase_id
             WHERE t.character_id = ?
             ORDER BY t.id DESC
             LIMIT 1',
            [(int) $characterId],
        );

        if (empty($row)) {
            return null;
        }

        return $this->rowToArray($row);
    }

    /**
     * Full transition history for a character.
     */
    public function getHistory(int $characterId, int $limit = 50): array
    {
        $rows = $this->fetchPrepared(
            'SELECT t.*,
                    p_to.code AS to_phase_code, p_to.name AS to_phase_name, p_to.color_hex AS to_phase_color,
                    p_from.code AS from_phase_code, p_from.name AS from_phase_name
             FROM `character_lifecycle_transitions` t
             INNER JOIN `lifecycle_phase_definitions` p_to ON p_to.id = t.to_phase_id
             LEFT JOIN  `lifecycle_phase_definitions` p_from ON p_from.id = t.from_phase_id
             WHERE t.character_id = ?
             ORDER BY t.id DESC
             LIMIT ?',
            [(int) $characterId, max(1, min(200, $limit))],
        );

        return array_map(function ($r) {
            return $this->rowToArray($r);
        }, $rows);
    }

    // -------------------------------------------------------------------------
    // Transitions
    // -------------------------------------------------------------------------

    /**
     * Apply a lifecycle transition to a character.
     * Creates an immutable log entry and fires hooks.
     */
    public function applyTransition(array $params): array
    {
        $characterId = (int) ($params['character_id'] ?? 0);
        $toPhaseId = (int) ($params['to_phase_id'] ?? 0);
        $toPhaseCode = trim((string) ($params['to_phase_code'] ?? ''));
        $triggeredBy = $params['triggered_by'] ?? 'admin';
        $trigEventId = (int) ($params['triggered_by_event_id'] ?? 0);
        $skipNarrativeEvent = (int) ($params['skip_narrative_event'] ?? 0) === 1;
        $notes = trim((string) ($params['notes'] ?? ''));
        $appliedBy = (int) ($params['applied_by'] ?? 0);

        if ($characterId <= 0) {
            throw AppError::validation('ID personaggio obbligatorio', [], 'character_id_required');
        }

        // Resolve target phase
        if ($toPhaseId <= 0 && $toPhaseCode !== '') {
            $phaseDef = $this->getPhaseDefinitionByCode($toPhaseCode);
            $toPhaseId = (int) $phaseDef['id'];
        }
        if ($toPhaseId <= 0) {
            throw AppError::validation('Fase destinazione obbligatoria', [], 'phase_id_required');
        }

        $phaseDef = $this->getPhaseDefinition($toPhaseId);
        if (!(bool) ($phaseDef['is_active'] ?? false)) {
            throw AppError::validation('La fase selezionata non è attiva', [], 'phase_inactive');
        }

        if (!in_array($triggeredBy, self::$validTriggeredBy, true)) {
            $triggeredBy = 'admin';
        }

        // Determine current phase (from_phase_id)
        $current = $this->getCurrentPhase($characterId);
        $fromPhaseId = $current ? (int) ($current['to_phase_id'] ?? 0) : 0;

        // Check terminal state
        if ($current) {
            $currentDef = $this->getPhaseDefinition($fromPhaseId);
            if ((bool) ($currentDef['is_terminal'] ?? false)) {
                throw AppError::validation(
                    'Il personaggio si trova in una fase terminale e non può effettuare ulteriori transizioni.',
                    [],
                    'phase_terminal',
                );
            }
        }

        if ($trigEventId <= 0 && !$skipNarrativeEvent) {
            $createdEventId = $this->tryCreateNarrativeEventForTransition(
                $characterId,
                $fromPhaseId,
                $toPhaseId,
                $phaseDef,
                (string) $triggeredBy,
                $notes,
                $appliedBy,
            );
            if ($createdEventId > 0) {
                $trigEventId = $createdEventId;
            }
        }

        $metaJson = isset($params['meta_json'])
            ? json_encode((array) $params['meta_json'], JSON_UNESCAPED_UNICODE)
            : null;

        $this->execPrepared(
            'INSERT INTO `character_lifecycle_transitions`
            (`character_id`,`from_phase_id`,`to_phase_id`,`triggered_by`,`triggered_by_event_id`,`notes`,`applied_by`,`meta_json`)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                (int) $characterId,
                $fromPhaseId > 0 ? (int) $fromPhaseId : null,
                (int) $toPhaseId,
                $triggeredBy,
                $trigEventId > 0 ? (int) $trigEventId : null,
                $notes !== '' ? $notes : null,
                $appliedBy > 0 ? (int) $appliedBy : null,
                $metaJson !== null ? $metaJson : null,
            ],
        );

        $newId = (int) $this->db->lastInsertId();

        \Core\Hooks::fire('lifecycle.transition.recorded', $newId, $characterId, $fromPhaseId > 0 ? $fromPhaseId : null, $toPhaseId);
        \Core\Hooks::fire('lifecycle.phase.entered', $characterId, $toPhaseId, (string) ($phaseDef['code'] ?? ''), $trigEventId > 0 ? $trigEventId : null);

        // Notify character owner of the phase change
        $ownerRow = $this->firstPrepared(
            'SELECT user_id FROM `characters` WHERE `id` = ? LIMIT 1',
            [(int) $characterId],
        );
        $ownerUserId = !empty($ownerRow) ? (int) ($ownerRow->user_id ?? 0) : 0;
        if ($ownerUserId > 0) {
            $phaseName = $phaseDef['name'] ?? '';
            $this->notifService->mergeOrCreateSystemUpdate(
                $ownerUserId,
                $characterId,
                'lifecycle_phase_' . $characterId,
                'Fase personaggio aggiornata: ' . $phaseName,
                [
                    'source_type' => 'lifecycle_transition',
                    'source_id' => $newId,
                    'action_url' => '/game/profile',
                ],
            );
        }

        return [
            'transition_id' => $newId,
            'character_id' => $characterId,
            'from_phase_id' => $fromPhaseId > 0 ? $fromPhaseId : null,
            'to_phase_id' => $toPhaseId,
            'phase_code' => $phaseDef['code'] ?? '',
            'phase_name' => $phaseDef['name'] ?? '',
        ];
    }
}
