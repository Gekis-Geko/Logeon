<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database\DbAdapterFactory;
use Core\Database\DbAdapterInterface;
use Core\Hooks;
use Core\Http\AppError;

class NarrativeDomainService
{
    /** @var DbAdapterInterface */
    private $db;

    /** @var NarrativeEventService|null */
    private $narrativeEventService = null;
    /** @var object|null */
    private $narrativeStateApplicationService = null;
    /** @var LifecycleService|null */
    private $lifecycleService = null;

    /** @var array<string,bool> */
    private $tableExistsCache = [];

    public function __construct(
        DbAdapterInterface $db = null,
        NarrativeEventService $narrativeEventService = null,
        $narrativeStateApplicationService = null,
        LifecycleService $lifecycleService = null,
    ) {
        $this->db = $db ?: DbAdapterFactory::createFromConfig();
        $this->narrativeEventService = $narrativeEventService;
        $this->narrativeStateApplicationService = $narrativeStateApplicationService;
        $this->lifecycleService = $lifecycleService;
    }

    private function firstPrepared(string $sql, array $params = [])
    {
        return $this->db->fetchOnePrepared($sql, $params);
    }

    private function execPrepared(string $sql, array $params = []): void
    {
        $this->db->executePrepared($sql, $params);
    }

    private function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    private function hasTable(string $table): bool
    {
        if (array_key_exists($table, $this->tableExistsCache)) {
            return $this->tableExistsCache[$table];
        }

        try {
            $row = $this->firstPrepared(
                'SELECT COUNT(*) AS c
                 FROM information_schema.tables
                 WHERE table_schema = DATABASE()
                   AND table_name = ?
                 LIMIT 1',
                [$table],
            );
            $this->tableExistsCache[$table] = !empty($row) && (int) ($row->c ?? 0) > 0;
        } catch (\Throwable $error) {
            $this->tableExistsCache[$table] = false;
        }

        return $this->tableExistsCache[$table];
    }

    private function narrativeEventService(): NarrativeEventService
    {
        if ($this->narrativeEventService instanceof NarrativeEventService) {
            return $this->narrativeEventService;
        }

        $this->narrativeEventService = new NarrativeEventService($this->db);
        return $this->narrativeEventService;
    }

    private function narrativeStateApplicationService()
    {
        if (is_object($this->narrativeStateApplicationService)
            && method_exists($this->narrativeStateApplicationService, 'applyState')
        ) {
            return $this->narrativeStateApplicationService;
        }

        $service = Hooks::filter('narrative.domain.state_runtime', null, $this->db);
        if ((!is_object($service) || !method_exists($service, 'applyState'))
            && class_exists('\\App\\Services\\NarrativeStateApplicationService')
        ) {
            $service = new \App\Services\NarrativeStateApplicationService($this->db);
        }

        if (!is_object($service) || !method_exists($service, 'applyState')) {
            $this->narrativeStateApplicationService = null;
            return null;
        }

        $this->narrativeStateApplicationService = $service;
        return $this->narrativeStateApplicationService;
    }

    private function lifecycleService(): LifecycleService
    {
        if ($this->lifecycleService instanceof LifecycleService) {
            return $this->lifecycleService;
        }

        $this->lifecycleService = new LifecycleService($this->db);
        return $this->lifecycleService;
    }

    private function toJson($value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            return '{}';
        }
        return $json;
    }

    private function normalizeScope($value): string
    {
        $scope = strtolower(trim((string) $value));
        if (!in_array($scope, ['local', 'regional', 'global'], true)) {
            $scope = 'local';
        }
        return $scope;
    }

    private function normalizeVisibility($value): string
    {
        $visibility = strtolower(trim((string) $value));
        if (!in_array($visibility, ['public', 'private', 'staff_only', 'hidden'], true)) {
            $visibility = 'public';
        }
        return $visibility;
    }

    private function normalizeRows($value): array
    {
        if ($value === null) {
            return [];
        }
        if (is_object($value)) {
            $value = (array) $value;
        }
        if (!is_array($value)) {
            return [];
        }

        $rows = [];
        foreach ($value as $row) {
            if (is_object($row)) {
                $row = (array) $row;
            }
            if (!is_array($row)) {
                continue;
            }
            $rows[] = $row;
        }
        return $rows;
    }

    private function normalizeActionKey($value): string
    {
        return trim((string) $value);
    }

    private function errorCodeFromThrowable(\Throwable $error): string
    {
        if ($error instanceof AppError) {
            $code = trim((string) $error->errorCode());
            if ($code !== '') {
                return $code;
            }
        }
        return 'narrative_domain_error';
    }

    private function findDomainActionByKey(string $actionKey)
    {
        if ($actionKey === '' || !$this->hasTable('narrative_domain_actions')) {
            return null;
        }

        return $this->firstPrepared(
            'SELECT *
             FROM narrative_domain_actions
             WHERE action_key = ?
             LIMIT 1',
            [$actionKey],
        );
    }

    private function persistDomainAction(
        string $actionKey,
        string $sourceSystem,
        int $sourceRefId,
        int $eventId,
        string $status,
        array $inconsistencies,
        array $meta,
    ): void {
        if (!$this->hasTable('narrative_domain_actions')) {
            return;
        }

        $status = strtolower(trim((string) $status));
        if (!in_array($status, ['ok', 'partial', 'error', 'duplicate'], true)) {
            $status = 'ok';
        }

        $sourceRef = $sourceRefId > 0 ? $sourceRefId : null;
        $eventRef = $eventId > 0 ? $eventId : null;
        $inconsistenciesJson = $this->toJson($inconsistencies);
        $metaJson = $this->toJson($meta);

        if ($actionKey !== '') {
            $this->execPrepared(
                'INSERT INTO narrative_domain_actions
                    (action_key, source_system, source_ref_id, event_id, status, inconsistencies_json, meta_json, date_created)
                 VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE
                    source_system = VALUES(source_system),
                    source_ref_id = VALUES(source_ref_id),
                    event_id = VALUES(event_id),
                    status = VALUES(status),
                    inconsistencies_json = VALUES(inconsistencies_json),
                    meta_json = VALUES(meta_json),
                    date_updated = NOW()',
                [$actionKey, $sourceSystem, $sourceRef, $eventRef, $status, $inconsistenciesJson, $metaJson],
            );
            return;
        }

        $this->execPrepared(
            'INSERT INTO narrative_domain_actions
                (action_key, source_system, source_ref_id, event_id, status, inconsistencies_json, meta_json, date_created)
             VALUES (NULL, ?, ?, ?, ?, ?, ?, NOW())',
            [$sourceSystem, $sourceRef, $eventRef, $status, $inconsistenciesJson, $metaJson],
        );
    }

    private function buildDuplicateResult(string $actionKey, $existingRow): array
    {
        $eventId = (int) ($existingRow->event_id ?? 0);
        $event = null;
        if ($eventId > 0) {
            try {
                $event = $this->narrativeEventService()->getEvent($eventId);
            } catch (\Throwable $error) {
                $event = null;
            }
        }

        return [
            'status' => 'duplicate',
            'action_key' => $actionKey,
            'event_id' => $eventId > 0 ? $eventId : null,
            'event' => $event,
            'applied_states' => [],
            'lifecycle_transitions' => [],
            'inconsistencies' => [],
        ];
    }

    /**
     * Canonical pipeline: Action -> Event -> State -> Lifecycle.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function processAction(array $payload): array
    {
        if (!$this->hasTable('narrative_events')) {
            throw AppError::validation(
                'Sistema eventi narrativi non disponibile',
                [],
                'narrative_event_system_unavailable',
            );
        }

        $actionKey = $this->normalizeActionKey($payload['action_key'] ?? '');
        $sourceSystem = trim((string) ($payload['source_system'] ?? 'system'));
        if ($sourceSystem === '') {
            $sourceSystem = 'system';
        }
        $sourceRefId = (int) ($payload['source_ref_id'] ?? 0);

        if ($actionKey !== '') {
            $existing = $this->findDomainActionByKey($actionKey);
            if (!empty($existing)) {
                return $this->buildDuplicateResult($actionKey, $existing);
            }
        }

        $actorCharacterId = (int) ($payload['actor_character_id'] ?? 0);
        $eventType = trim((string) ($payload['event_type'] ?? 'manual'));
        if ($eventType === '') {
            $eventType = 'manual';
        }

        $title = trim((string) ($payload['title'] ?? ''));
        if ($title === '') {
            $title = 'Evento narrativo';
        }

        $eventParams = [
            'title' => $title,
            'event_type' => $eventType,
            'scope' => $this->normalizeScope($payload['scope'] ?? 'local'),
            'description' => trim((string) ($payload['description'] ?? '')),
            'entity_refs' => $this->normalizeRows($payload['entity_refs'] ?? []),
            'location_id' => (int) ($payload['location_id'] ?? 0),
            'visibility' => $this->normalizeVisibility($payload['visibility'] ?? 'public'),
            'tags' => trim((string) ($payload['tags'] ?? '')),
            'source_system' => $sourceSystem,
            'source_ref_id' => $sourceRefId,
            'meta_json' => (array) ($payload['meta_json'] ?? []),
            'created_by' => $actorCharacterId > 0 ? $actorCharacterId : 0,
        ];

        $event = $this->narrativeEventService()->createEvent($eventParams);
        $eventId = (int) ($event['id'] ?? 0);

        $appliedStates = [];
        $lifecycleTransitions = [];
        $inconsistencies = [];

        $stateRows = $this->normalizeRows($payload['states'] ?? []);
        if (!empty($stateRows)) {
            if (!$this->hasTable('narrative_states') || !$this->hasTable('applied_narrative_states')) {
                $inconsistencies[] = [
                    'stage' => 'state_apply',
                    'error_code' => 'narrative_state_system_unavailable',
                    'message' => 'Tabelle stati narrativi non disponibili.',
                    'count' => count($stateRows),
                ];
            } else {
                $stateRuntime = $this->narrativeStateApplicationService();
                if (!is_object($stateRuntime) || !method_exists($stateRuntime, 'applyState')) {
                    $inconsistencies[] = [
                        'stage' => 'state_apply',
                        'error_code' => 'narrative_state_runtime_unavailable',
                        'message' => 'Runtime stati narrativi non disponibile.',
                        'count' => count($stateRows),
                    ];
                } else {
                    foreach ($stateRows as $index => $statePayload) {
                        try {
                            $statePayload['source_event_id'] = $eventId;
                            if (!isset($statePayload['applier_character_id']) || (int) $statePayload['applier_character_id'] <= 0) {
                                $statePayload['applier_character_id'] = $actorCharacterId;
                            }
                            $result = $stateRuntime->applyState($statePayload);
                            $appliedStates[] = $result;
                        } catch (\Throwable $error) {
                            $inconsistencies[] = [
                                'stage' => 'state_apply',
                                'index' => $index,
                                'error_code' => $this->errorCodeFromThrowable($error),
                                'message' => $error->getMessage(),
                            ];
                        }
                    }
                }
            }
        }

        $transitionRows = $this->normalizeRows($payload['lifecycle_transitions'] ?? []);
        if (!empty($transitionRows)) {
            if (
                !$this->hasTable('lifecycle_phase_definitions')
                || !$this->hasTable('character_lifecycle_transitions')
            ) {
                $inconsistencies[] = [
                    'stage' => 'lifecycle_apply',
                    'error_code' => 'lifecycle_system_unavailable',
                    'message' => 'Tabelle lifecycle non disponibili.',
                    'count' => count($transitionRows),
                ];
            } else {
                foreach ($transitionRows as $index => $transitionPayload) {
                    try {
                        if (!isset($transitionPayload['triggered_by']) || trim((string) $transitionPayload['triggered_by']) === '') {
                            $transitionPayload['triggered_by'] = 'event';
                        }
                        $transitionPayload['triggered_by_event_id'] = $eventId;
                        if (!isset($transitionPayload['applied_by']) || (int) $transitionPayload['applied_by'] <= 0) {
                            $transitionPayload['applied_by'] = $actorCharacterId;
                        }
                        $result = $this->lifecycleService()->applyTransition($transitionPayload);
                        $lifecycleTransitions[] = $result;
                    } catch (\Throwable $error) {
                        $inconsistencies[] = [
                            'stage' => 'lifecycle_apply',
                            'index' => $index,
                            'error_code' => $this->errorCodeFromThrowable($error),
                            'message' => $error->getMessage(),
                        ];
                    }
                }
            }
        }

        $status = empty($inconsistencies) ? 'ok' : 'partial';
        $meta = [
            'event_type' => $eventType,
            'processed_at' => $this->now(),
            'states_count' => count($stateRows),
            'states_applied' => count($appliedStates),
            'lifecycle_count' => count($transitionRows),
            'lifecycle_applied' => count($lifecycleTransitions),
        ];

        $this->persistDomainAction(
            $actionKey,
            $sourceSystem,
            $sourceRefId,
            $eventId,
            $status,
            $inconsistencies,
            $meta,
        );

        return [
            'status' => $status,
            'action_key' => $actionKey !== '' ? $actionKey : null,
            'event_id' => $eventId > 0 ? $eventId : null,
            'event' => $event,
            'applied_states' => $appliedStates,
            'lifecycle_transitions' => $lifecycleTransitions,
            'inconsistencies' => $inconsistencies,
        ];
    }
}
