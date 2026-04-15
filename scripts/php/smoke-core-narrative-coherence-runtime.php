<?php

declare(strict_types=1);

/**
 * Core narrative coherence runtime smoke (CLI).
 *
 * Coverage:
 * 1) NarrativeEvents visibility filter for viewer/staff.
 * 2) Lifecycle transition auto-link to narrative event when triggered_by_event_id is missing.
 * 3) Faction membership narrative publication (add/update/remove).
 *
 * Usage:
 *   C:\xampp\php\php.exe scripts/php/smoke-core-narrative-coherence-runtime.php
 */

$root = dirname(__DIR__, 2);

$bootstrap = [
    $root . '/configs/config.php',
    $root . '/configs/db.php',
    $root . '/configs/app.php',
    $root . '/vendor/autoload.php',
];

foreach ($bootstrap as $file) {
    if (!is_file($file)) {
        fwrite(STDERR, "[FAIL] Missing bootstrap file: {$file}\n");
        exit(1);
    }
    require_once $file;
}

$customBootstrap = $root . '/custom/bootstrap.php';
if (is_file($customBootstrap)) {
    require_once $customBootstrap;
}

use App\Services\FactionService;
use App\Services\LifecycleService;
use App\Services\NarrativeEventService;
use Core\Database\DbAdapterFactory;
use Core\Database\MysqliDbAdapter;
use Core\Http\AppError;

function coherenceSmokeAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function coherenceSmokeStep(string $label): void
{
    fwrite(STDOUT, "[STEP] {$label}\n");
}

function coherenceSmokeTableExists(MysqliDbAdapter $db, string $table): bool
{
    $safe = $db->safe($table);
    $row = $db->query('SHOW TABLES LIKE ' . $safe)->first();
    return !empty($row);
}

try {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    if (!isset($_SERVER['REQUEST_TIME']) || (int) $_SERVER['REQUEST_TIME'] <= 0) {
        $_SERVER['REQUEST_TIME'] = time();
    }

    $db = DbAdapterFactory::createFromConfig();
    coherenceSmokeAssert($db instanceof MysqliDbAdapter, 'DbAdapterFactory must return MysqliDbAdapter.');

    $requiredTables = [
        'narrative_events',
        'lifecycle_phase_definitions',
        'character_lifecycle_transitions',
        'factions',
        'faction_memberships',
    ];
    foreach ($requiredTables as $table) {
        coherenceSmokeAssert(
            coherenceSmokeTableExists($db, $table),
            'Missing required table for narrative coherence smoke: ' . $table,
        );
    }

    $characterRows = $db->query('SELECT id FROM characters ORDER BY id ASC LIMIT 2')->fetch();
    coherenceSmokeAssert(!empty($characterRows), 'No characters available for smoke.');
    $ownerCharacterId = (int) ($characterRows[0]->id ?? 0);
    coherenceSmokeAssert($ownerCharacterId > 0, 'Invalid owner character id.');
    $viewerCharacterId = isset($characterRows[1]) ? (int) ($characterRows[1]->id ?? 0) : $ownerCharacterId;

    $marker = 'smoke_narrative_coherence_' . date('Ymd_His') . '_' . mt_rand(1000, 9999);
    $checks = 0;

    $eventService = new NarrativeEventService($db);
    $lifecycleService = new LifecycleService($db);
    $factionService = new FactionService($db);

    $createdEventIds = [];
    $createdTransitionIds = [];
    $createdFactionId = 0;

    // 1) Visibility filter: own private visibility + deny for other non-staff.
    coherenceSmokeStep('narrative visibility');

    $publicEvent = $eventService->createEvent([
        'title' => 'Smoke public event ' . $marker,
        'event_type' => 'smoke_public',
        'scope' => 'local',
        'description' => 'Public smoke event',
        'entity_refs' => [
            ['entity_type' => 'character', 'entity_id' => $ownerCharacterId, 'role' => 'subject'],
        ],
        'visibility' => 'public',
        'source_system' => 'smoke_narrative_coherence',
        'source_ref_id' => $ownerCharacterId,
        'meta_json' => ['marker' => $marker],
        'created_by' => $ownerCharacterId,
    ]);
    $privateEvent = $eventService->createEvent([
        'title' => 'Smoke private event ' . $marker,
        'event_type' => 'smoke_private',
        'scope' => 'local',
        'description' => 'Private smoke event',
        'entity_refs' => [
            ['entity_type' => 'character', 'entity_id' => $ownerCharacterId, 'role' => 'subject'],
        ],
        'visibility' => 'private',
        'source_system' => 'smoke_narrative_coherence',
        'source_ref_id' => $ownerCharacterId,
        'meta_json' => ['marker' => $marker],
        'created_by' => $ownerCharacterId,
    ]);

    $publicEventId = (int) ($publicEvent['id'] ?? 0);
    $privateEventId = (int) ($privateEvent['id'] ?? 0);
    coherenceSmokeAssert($publicEventId > 0 && $privateEventId > 0, 'Failed to create smoke events.');
    $createdEventIds[] = $publicEventId;
    $createdEventIds[] = $privateEventId;

    $ownerList = $eventService->listForViewer([], $ownerCharacterId, false, 50, 1);
    $ownerRows = is_array($ownerList['rows'] ?? null) ? $ownerList['rows'] : [];
    $ownerIds = array_map(static function ($row): int {
        return (int) ($row['id'] ?? 0);
    }, $ownerRows);
    coherenceSmokeAssert(in_array($privateEventId, $ownerIds, true), 'Owner cannot see own private narrative event.');

    if ($viewerCharacterId > 0 && $viewerCharacterId !== $ownerCharacterId) {
        $viewerList = $eventService->listForViewer([], $viewerCharacterId, false, 50, 1);
        $viewerRows = is_array($viewerList['rows'] ?? null) ? $viewerList['rows'] : [];
        $viewerIds = array_map(static function ($row): int {
            return (int) ($row['id'] ?? 0);
        }, $viewerRows);
        coherenceSmokeAssert(!in_array($privateEventId, $viewerIds, true), 'Non-owner can see private narrative event.');

        $denied = false;
        try {
            $eventService->getEventForViewer($privateEventId, $viewerCharacterId, false);
        } catch (AppError $error) {
            $denied = ($error->errorCode() === 'event_not_found');
        }
        coherenceSmokeAssert($denied, 'getEventForViewer must deny private event to non-owner non-staff.');
    }
    $checks++;

    // 2) Lifecycle transition auto-creates narrative event if not linked.
    coherenceSmokeStep('lifecycle auto narrative event');

    $candidateRow = $db->query(
        'SELECT c.id
         FROM characters c
         LEFT JOIN (
             SELECT t1.character_id, t1.to_phase_id
             FROM character_lifecycle_transitions t1
             INNER JOIN (
                 SELECT character_id, MAX(id) AS max_id
                 FROM character_lifecycle_transitions
                 GROUP BY character_id
             ) mx ON mx.character_id = t1.character_id AND mx.max_id = t1.id
         ) cur ON cur.character_id = c.id
         LEFT JOIN lifecycle_phase_definitions pcur ON pcur.id = cur.to_phase_id
         WHERE pcur.id IS NULL OR pcur.is_terminal = 0
         ORDER BY c.id ASC
         LIMIT 1',
    )->first();
    coherenceSmokeAssert(!empty($candidateRow), 'No non-terminal character available for lifecycle smoke.');
    $lifecycleCharacterId = (int) ($candidateRow->id ?? 0);
    coherenceSmokeAssert($lifecycleCharacterId > 0, 'Invalid lifecycle smoke character id.');

    $phaseRow = $db->query(
        'SELECT id, code, name
         FROM lifecycle_phase_definitions
         WHERE is_active = 1 AND is_terminal = 0
         ORDER BY sort_order ASC, id ASC
         LIMIT 1',
    )->first();
    coherenceSmokeAssert(!empty($phaseRow), 'No active non-terminal lifecycle phase available.');
    $phaseId = (int) ($phaseRow->id ?? 0);
    coherenceSmokeAssert($phaseId > 0, 'Invalid lifecycle phase id for smoke.');

    $transition = $lifecycleService->applyTransition([
        'character_id' => $lifecycleCharacterId,
        'to_phase_id' => $phaseId,
        'triggered_by' => 'admin',
        'triggered_by_event_id' => 0,
        'notes' => 'smoke_lifecycle_' . $marker,
        'applied_by' => $ownerCharacterId,
    ]);
    $transitionId = (int) ($transition['transition_id'] ?? 0);
    coherenceSmokeAssert($transitionId > 0, 'Lifecycle transition was not created.');
    $createdTransitionIds[] = $transitionId;

    $transitionRow = $db->query(
        'SELECT triggered_by_event_id
         FROM character_lifecycle_transitions
         WHERE id = ' . $db->safe($transitionId) . '
         LIMIT 1',
    )->first();
    $triggeredByEventId = (int) ($transitionRow->triggered_by_event_id ?? 0);
    coherenceSmokeAssert($triggeredByEventId > 0, 'Lifecycle transition did not auto-link to narrative event.');
    $createdEventIds[] = $triggeredByEventId;

    $linkedEvent = $eventService->getEvent($triggeredByEventId);
    coherenceSmokeAssert(
        (string) ($linkedEvent['event_type'] ?? '') === 'lifecycle_transition',
        'Lifecycle auto-created narrative event has unexpected event_type.',
    );
    $checks++;

    // 3) Faction membership publication: add/update/remove.
    coherenceSmokeStep('faction membership narrative');

    $factionCode = 'smoke_faction_' . $marker;
    $faction = $factionService->adminCreate((object) [
        'code' => $factionCode,
        'name' => 'Smoke Faction ' . $marker,
        'description' => 'Smoke faction for narrative coherence',
        'type' => 'political',
        'scope' => 'local',
        'power_level' => 4,
        'is_public' => 1,
        'is_active' => 1,
        'actor_character_id' => $ownerCharacterId,
    ]);
    $createdFactionId = (int) ($faction['id'] ?? 0);
    coherenceSmokeAssert($createdFactionId > 0, 'Faction create failed in smoke.');

    $targetCharacterId = $viewerCharacterId > 0 ? $viewerCharacterId : $ownerCharacterId;
    $factionService->adminMemberAdd($createdFactionId, $targetCharacterId, 'member', '', $ownerCharacterId);

    $membershipRow = $db->query(
        'SELECT id
         FROM faction_memberships
         WHERE faction_id = ' . $db->safe($createdFactionId) . '
           AND character_id = ' . $db->safe($targetCharacterId) . '
         LIMIT 1',
    )->first();
    $membershipId = (int) ($membershipRow->id ?? 0);
    coherenceSmokeAssert($membershipId > 0, 'Faction membership create failed in smoke.');

    $factionService->adminMemberUpdate($membershipId, (object) ['role' => 'advisor'], $ownerCharacterId);
    $factionService->adminMemberRemove($createdFactionId, $targetCharacterId, $ownerCharacterId);

    $eventsRow = $db->query(
        'SELECT event_type, COUNT(*) AS n
         FROM narrative_events
         WHERE source_system = \'faction\'
           AND source_ref_id = ' . $db->safe($createdFactionId) . '
           AND event_type IN (\'faction_member_added\',\'faction_member_updated\',\'faction_member_removed\')
         GROUP BY event_type',
    )->fetch();
    $eventCounts = [];
    foreach ($eventsRow as $row) {
        $eventCounts[(string) ($row->event_type ?? '')] = (int) ($row->n ?? 0);
    }
    coherenceSmokeAssert(($eventCounts['faction_member_added'] ?? 0) > 0, 'Missing faction_member_added narrative event.');
    coherenceSmokeAssert(($eventCounts['faction_member_updated'] ?? 0) > 0, 'Missing faction_member_updated narrative event.');
    coherenceSmokeAssert(($eventCounts['faction_member_removed'] ?? 0) > 0, 'Missing faction_member_removed narrative event.');
    $checks++;

    // Cleanup faction (will also create faction_deleted event), then remove all faction narrative events.
    $factionService->adminDelete($createdFactionId, $ownerCharacterId);
    $db->query(
        'DELETE FROM narrative_events
         WHERE source_system = \'faction\' AND source_ref_id = ' . $db->safe($createdFactionId),
    );
    $createdFactionId = 0;

    // Cleanup smoke transitions/events.
    foreach ($createdTransitionIds as $transitionId) {
        if ((int) $transitionId <= 0) {
            continue;
        }
        $db->query('DELETE FROM character_lifecycle_transitions WHERE id = ' . $db->safe((int) $transitionId));
    }
    foreach ($createdEventIds as $eventId) {
        if ((int) $eventId <= 0) {
            continue;
        }
        $db->query('DELETE FROM narrative_events WHERE id = ' . $db->safe((int) $eventId));
    }

    fwrite(STDOUT, "[OK] Core narrative coherence smoke passed ({$checks} checks).\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[FAIL] ' . $error->getMessage() . "\n");
    exit(1);
}
