<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database\DbAdapterInterface;
use Core\Hooks;

class QuestTriggerService
{
    /** @var QuestResolverService */
    private $resolver;
    /** @var bool */
    private static $bootstrapped = false;

    public function __construct(DbAdapterInterface $db = null, QuestResolverService $resolver = null)
    {
        $this->resolver = $resolver ?: new QuestResolverService($db);
    }

    public static function bootstrap(): void
    {
        if (self::$bootstrapped) {
            return;
        }
        self::$bootstrapped = true;

        Hooks::add('narrative.event.created', function ($eventId, $eventType, $entityRefs = []) {
            (new QuestTriggerService())->handle('narrative.event.created', [
                'event_id' => (int) $eventId,
                'event_type' => (string) $eventType,
                'entity_refs' => is_array($entityRefs) ? $entityRefs : [],
            ]);
        });

        Hooks::add('system_event.status_changed', function ($systemEventId, $oldStatus, $newStatus) {
            (new QuestTriggerService())->handle('system_event.status_changed', [
                'system_event_id' => (int) $systemEventId,
                'old_status' => (string) $oldStatus,
                'new_status' => (string) $newStatus,
            ]);
        });

        Hooks::add('faction.membership.changed', function (...$args) {
            $arg0 = isset($args[0]) ? (int) $args[0] : 0;
            $arg1 = isset($args[1]) ? (int) $args[1] : 0;
            $action = isset($args[2]) ? (string) $args[2] : '';
            $role = isset($args[3]) ? (string) $args[3] : '';

            // Compat signatures:
            // 1) (faction_id, character_id, action, role)
            // 2) (character_id, faction_id, action, role)
            // Default runtime usa (faction_id, character_id, ...).
            $factionId = $arg0;
            $characterId = $arg1;
            if ($arg0 > 0 && $arg1 > 0 && $arg1 < $arg0) {
                $factionId = $arg1;
                $characterId = $arg0;
            }

            (new QuestTriggerService())->handle('faction.membership.changed', [
                'character_id' => (int) $characterId,
                'faction_id' => (int) $factionId,
                'action' => $action,
                'role' => $role,
            ]);
        });

        Hooks::add('lifecycle.phase.entered', function (...$args) {
            // Compat signatures:
            // 1) (character_id, to_phase_id, phase_code, trigger_event_id)
            // 2) (character_id, old_phase_id, new_phase_id, reason, details)
            $characterId = isset($args[0]) ? (int) $args[0] : 0;

            $oldPhaseId = 0;
            $newPhaseId = 0;
            $reason = '';
            $details = [];

            if (isset($args[2]) && !is_numeric($args[2])) {
                $newPhaseId = isset($args[1]) ? (int) $args[1] : 0;
                $reason = (string) ($args[2] ?? '');
                $details = ['trigger_event_id' => isset($args[3]) ? (int) $args[3] : 0];
            } else {
                $oldPhaseId = isset($args[1]) ? (int) $args[1] : 0;
                $newPhaseId = isset($args[2]) ? (int) $args[2] : 0;
                $reason = isset($args[3]) ? (string) $args[3] : '';
                $details = isset($args[4]) && is_array($args[4]) ? $args[4] : [];
            }

            (new QuestTriggerService())->handle('lifecycle.phase.entered', [
                'character_id' => $characterId,
                'old_phase_id' => $oldPhaseId,
                'new_phase_id' => $newPhaseId,
                'reason' => $reason,
                'details' => $details,
            ]);
        });

        Hooks::add('presence.position_changed', function ($characterId, $mapId, $locationId, $previousMapId = null, $previousLocationId = null) {
            (new QuestTriggerService())->handle('presence.position_changed', [
                'character_id' => (int) $characterId,
                'map_id' => $mapId !== null ? (int) $mapId : null,
                'location_id' => $locationId !== null ? (int) $locationId : null,
                'previous_map_id' => $previousMapId !== null ? (int) $previousMapId : null,
                'previous_location_id' => $previousLocationId !== null ? (int) $previousLocationId : null,
            ]);
        });
    }

    public function handle(string $triggerType, array $context = []): array
    {
        try {
            return $this->resolver->processTrigger($triggerType, $context);
        } catch (\Throwable $error) {
            return [
                'trigger_type' => $triggerType,
                'status' => 'error',
                'message' => $error->getMessage(),
            ];
        }
    }
}
