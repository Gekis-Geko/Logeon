<?php

declare(strict_types=1);

/**
 * System Events runtime smoke (CLI).
 *
 * Usage:
 *   C:\xampp\php\php.exe scripts/php/smoke-system-events-runtime.php
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

use App\Services\SystemEventService;
use App\Services\FactionProviderRegistry;
use App\Contracts\FactionProviderInterface;
use Core\Database\DbAdapterFactory;
use Core\Database\MysqliDbAdapter;

function seSmokeAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function seSmokeStep(string $label): void
{
    fwrite(STDOUT, "[STEP] {$label}\n");
}

final class SystemEventsSmokeFactionProvider implements FactionProviderInterface
{
    public function existsById(int $id): bool
    {
        return $id > 0;
    }

    public function getNameById(int $id): ?string
    {
        return $id > 0 ? 'Smoke Faction' : null;
    }

    /**
     * @return array<int,array{id:int,label:string,secondary:string}>
     */
    public function search(string $needle, int $limit): array
    {
        return [];
    }

    public function getMembershipsForCharacter(int $characterId): array
    {
        return [];
    }

    public function getActiveCharacterIdsForFactions(array $factionIds): array
    {
        return [];
    }

    public function joinEventAsFaction(int $factionId, int $eventId, int $characterId): bool
    {
        return true;
    }

    public function leaveEventAsFaction(int $factionId, int $eventId, int $characterId): bool
    {
        return true;
    }

    public function inviteFactionToEvent(int $factionId, int $eventId, int $inviterCharacterId): bool
    {
        return true;
    }
}

try {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $db = DbAdapterFactory::createFromConfig();
    seSmokeAssert($db instanceof MysqliDbAdapter, 'DbAdapterFactory must return MysqliDbAdapter.');

    $tableRow = $db->query("SHOW TABLES LIKE 'system_events'")->first();
    seSmokeAssert(!empty($tableRow), 'Table system_events not found. Import or update database/logeon_db_core.sql first.');

    $userRow = $db->query('SELECT id FROM users ORDER BY id ASC LIMIT 1')->first();
    $characterRow = $db->query('SELECT id FROM characters ORDER BY id ASC LIMIT 1')->first();
    $currencyRow = $db->query('SELECT id FROM currencies ORDER BY id ASC LIMIT 1')->first();
    seSmokeAssert(!empty($userRow) && !empty($characterRow), 'No user/character available for system-events smoke.');
    seSmokeAssert(!empty($currencyRow), 'No currency available for system-events smoke.');

    $characterId = (int) ($characterRow->id ?? 0);
    $currencyId = (int) ($currencyRow->id ?? 0);
    seSmokeAssert($characterId > 0 && $currencyId > 0, 'Invalid character/currency id for system-events smoke.');

    $service = new SystemEventService($db);
    seSmokeAssert($service->isEnabled(), 'System events feature is disabled (sys_configs.system_events_enabled=0).');
    FactionProviderRegistry::resetRuntimeState();

    $marker = 'smoke_se_' . date('Ymd_His') . '_' . mt_rand(1000, 9999);
    $createdIds = [];
    $checks = 0;

    try {
        seSmokeStep('create scheduled event');
        $event = $service->create([
            'title' => 'Smoke Event ' . $marker,
            'description' => 'Runtime smoke event.',
            'type' => 'general',
            'status' => 'scheduled',
            'visibility' => 'public',
            'scope_type' => 'global',
            'scope_id' => 0,
            'participant_mode' => 'character',
            'starts_at' => date('Y-m-d H:i:s', time() - 120),
            'ends_at' => date('Y-m-d H:i:s', time() + 900),
            'recurrence' => 'none',
        ], $characterId);
        $eventId = (int) ($event['id'] ?? 0);
        seSmokeAssert($eventId > 0, 'Event creation failed.');
        $createdIds[] = $eventId;
        $checks++;

        seSmokeStep('admin list and lazy maintenance');
        $adminList = $service->listForAdmin(['search' => $marker], 20, 1, 'id|DESC');
        $rows = is_array($adminList['rows'] ?? null) ? $adminList['rows'] : [];
        seSmokeAssert(!empty($rows), 'Admin list did not return created event.');
        $maintenance = $service->maintenanceRun(true);
        seSmokeAssert(isset($maintenance['activated_ids']), 'Maintenance payload malformed.');
        $checks++;

        seSmokeStep('join participation character');
        $join = $service->joinParticipation($eventId, ['character_id' => $characterId], $characterId, false);
        seSmokeAssert((string) ($join['status'] ?? '') === 'joined', 'Join participation failed.');
        $checks++;

        seSmokeStep('upsert effect currency reward');
        $effect = $service->upsertEffect($eventId, [
            'effect_type' => 'currency_reward',
            'currency_id' => $currencyId,
            'amount' => 1,
            'is_enabled' => 1,
        ], $characterId);
        seSmokeAssert((int) ($effect['id'] ?? 0) > 0, 'Effect upsert failed.');
        $checks++;

        seSmokeStep('set completed and verify reward log');
        $service->setStatus($eventId, 'completed', $characterId, true);
        $log = $service->rewardLog($eventId, 50, 1);
        $rewardRows = is_array($log['rows'] ?? null) ? $log['rows'] : [];
        seSmokeAssert(!empty($rewardRows), 'Reward log is empty after completion.');
        $checks++;

        seSmokeStep('leave participation');
        $leave = $service->leaveParticipation($eventId, ['character_id' => $characterId], $characterId, false);
        seSmokeAssert(in_array((string) ($leave['status'] ?? ''), ['left', 'removed'], true), 'Leave participation failed.');
        $checks++;

        seSmokeStep('faction participation provider fallback no-op');
        $factionEvent = $service->create([
            'title' => 'Smoke Faction Event ' . $marker,
            'description' => 'Runtime smoke faction event.',
            'type' => 'general',
            'status' => 'scheduled',
            'visibility' => 'public',
            'scope_type' => 'global',
            'scope_id' => 0,
            'participant_mode' => 'faction',
            'starts_at' => date('Y-m-d H:i:s', time() - 120),
            'ends_at' => date('Y-m-d H:i:s', time() + 900),
            'recurrence' => 'none',
        ], $characterId);
        $factionEventId = (int) ($factionEvent['id'] ?? 0);
        seSmokeAssert($factionEventId > 0, 'Faction event creation failed.');
        $createdIds[] = $factionEventId;

        $factionId = 1;
        $factionRow = $db->query('SELECT id FROM factions ORDER BY id ASC LIMIT 1')->first();
        if (!empty($factionRow)) {
            $candidateFactionId = (int) ($factionRow->id ?? 0);
            if ($candidateFactionId > 0) {
                $factionId = $candidateFactionId;
            }
        }

        FactionProviderRegistry::resetRuntimeState();
        $factionJoinNoOp = $service->joinParticipation($factionEventId, ['faction_id' => $factionId], $characterId, false);
        seSmokeAssert((int) ($factionJoinNoOp['noop'] ?? 0) === 1, 'Faction join should be no-op with fallback provider.');
        $factionRowsAfterNoOp = $service->listParticipations($factionEventId);
        seSmokeAssert(count($factionRowsAfterNoOp) === 0, 'Fallback no-op must not create faction participation.');
        $checks++;

        seSmokeStep('faction participation provider override');
        FactionProviderRegistry::setProvider(new SystemEventsSmokeFactionProvider());
        $factionJoin = $service->joinParticipation($factionEventId, ['faction_id' => $factionId], $characterId, false);
        seSmokeAssert((string) ($factionJoin['status'] ?? '') === 'joined', 'Faction join with provider override failed.');
        $factionLeave = $service->leaveParticipation($factionEventId, ['faction_id' => $factionId], $characterId, false);
        seSmokeAssert(in_array((string) ($factionLeave['status'] ?? ''), ['left', 'removed'], true), 'Faction leave with provider override failed.');
        FactionProviderRegistry::resetRuntimeState();
        $checks++;

        seSmokeStep('create recurring event and verify generated occurrence');
        $recurring = $service->create([
            'title' => 'Smoke Recurring ' . $marker,
            'description' => 'Recurring smoke event.',
            'type' => 'general',
            'status' => 'active',
            'visibility' => 'public',
            'scope_type' => 'global',
            'scope_id' => 0,
            'participant_mode' => 'character',
            'starts_at' => date('Y-m-d H:i:s', time() - 7200),
            'ends_at' => date('Y-m-d H:i:s', time() - 3600),
            'recurrence' => 'daily',
        ], $characterId);
        $recurringId = (int) ($recurring['id'] ?? 0);
        seSmokeAssert($recurringId > 0, 'Recurring event creation failed.');
        $createdIds[] = $recurringId;

        $service->setStatus($recurringId, 'completed', $characterId, true);
        $needle = '%"generated_from_event_id":' . (int) $recurringId . '%';
        $generated = $db->query(
            'SELECT id
             FROM system_events
             WHERE meta_json LIKE ' . $db->safe($needle) . '
             ORDER BY id DESC
             LIMIT 1',
        )->first();
        $generatedId = (int) ($generated->id ?? 0);
        seSmokeAssert($generatedId > 0, 'Recurring completion did not generate next occurrence.');
        $createdIds[] = $generatedId;
        $checks++;

        seSmokeStep('game list/get visibility');
        $viewerFactions = $service->viewerFactionIds($characterId);
        $gameList = $service->listForGame(['status' => 'completed'], $characterId, false, $viewerFactions, 20, 1);
        $gameRows = is_array($gameList['rows'] ?? null) ? $gameList['rows'] : [];
        seSmokeAssert(!empty($gameRows), 'Game list returned empty dataset unexpectedly.');
        $gameDetail = $service->getForGame($eventId, $characterId, false, $viewerFactions);
        seSmokeAssert((int) ($gameDetail['id'] ?? 0) === $eventId, 'Game detail failed for completed event.');
        $checks++;
    } finally {
        FactionProviderRegistry::resetRuntimeState();
        foreach ($createdIds as $id) {
            try {
                $service->delete((int) $id);
            } catch (Throwable $cleanupError) {
                // Ignore cleanup failures in smoke.
            }
        }
    }

    fwrite(STDOUT, "[OK] System Events runtime smoke passed ({$checks} checks).\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, '[FAIL] ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
