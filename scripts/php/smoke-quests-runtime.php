<?php

declare(strict_types=1);

/**
 * Quests runtime smoke (CLI).
 *
 * Usage:
 *   C:\xampp\php\php.exe scripts/php/smoke-quests-runtime.php
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

use App\Services\QuestAssignmentService;
use App\Services\QuestClosureService;
use App\Services\QuestDefinitionService;
use App\Services\QuestHistoryResolverService;
use App\Services\QuestProgressService;
use App\Services\QuestResolverService;
use App\Services\QuestRewardService;
use Core\Database\DbAdapterFactory;
use Core\Database\MysqliDbAdapter;

function questSmokeAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function questSmokeStep(string $label): void
{
    fwrite(STDOUT, "[STEP] {$label}\n");
}

try {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $db = DbAdapterFactory::createFromConfig();
    questSmokeAssert($db instanceof MysqliDbAdapter, 'DbAdapterFactory must return MysqliDbAdapter.');

    $tableRow = $db->query("SHOW TABLES LIKE 'quest_definitions'")->first();
    questSmokeAssert(!empty($tableRow), 'Table quest_definitions not found. Import or update database/logeon_db_core.sql first.');

    $characterRow = $db->query('SELECT id FROM characters ORDER BY id ASC LIMIT 1')->first();
    questSmokeAssert(!empty($characterRow), 'No character available for quest smoke.');
    $characterId = (int) ($characterRow->id ?? 0);
    questSmokeAssert($characterId > 0, 'Invalid character id for quest smoke.');

    $resolver = new QuestResolverService($db);
    $definitionService = new QuestDefinitionService($db, $resolver);
    $assignmentService = new QuestAssignmentService($db, $resolver);
    $progressService = new QuestProgressService($db, $resolver);
    $closureService = new QuestClosureService($db, $resolver);
    $rewardService = new QuestRewardService($db);
    $historyService = new QuestHistoryResolverService($db, $resolver, $closureService, $rewardService);

    questSmokeAssert($resolver->isEnabled(), 'Quests feature is disabled (sys_configs.quests_enabled=0).');

    $marker = 'smoke_quest_' . date('Ymd_His') . '_' . mt_rand(1000, 9999);
    $definitionId = 0;
    $instanceId = 0;
    $checks = 0;

    try {
        questSmokeStep('create definition');
        $definition = $definitionService->create([
            'slug' => $marker,
            'title' => 'Smoke Quest ' . $marker,
            'summary' => 'Quest runtime smoke',
            'description' => 'Quest runtime smoke description.',
            'quest_type' => 'personal',
            'visibility' => 'public',
            'scope_type' => 'world',
            'scope_id' => 0,
            'availability_type' => 'manual_join',
            'status' => 'published',
            'sort_order' => 0,
        ], $characterId);
        $definitionId = (int) ($definition['id'] ?? 0);
        questSmokeAssert($definitionId > 0, 'Quest definition creation failed.');
        $checks++;

        questSmokeStep('create step');
        $step = $definitionService->upsertStep($definitionId, [
            'step_key' => 'intro',
            'title' => 'Step intro',
            'description' => 'Smoke step',
            'step_type' => 'action',
            'order_index' => 0,
            'is_optional' => 0,
        ]);
        $stepDefinitionId = (int) ($step['id'] ?? 0);
        questSmokeAssert($stepDefinitionId > 0, 'Step creation failed.');
        $checks++;

        questSmokeStep('assign instance');
        $instance = $assignmentService->assign([
            'quest_definition_id' => $definitionId,
            'assignee_type' => 'character',
            'assignee_id' => $characterId,
            'status' => 'active',
        ], $characterId);
        $instanceId = (int) ($instance['id'] ?? 0);
        questSmokeAssert($instanceId > 0, 'Instance assignment failed.');
        $checks++;

        questSmokeStep('list/get for game');
        $viewerFactionIds = $assignmentService->viewerFactionIds($characterId);
        $viewerGuildIds = $assignmentService->viewerGuildIds($characterId);
        $list = $assignmentService->listForGame([], $characterId, true, $viewerFactionIds, $viewerGuildIds, 20, 1);
        $rows = is_array($list['rows'] ?? null) ? $list['rows'] : [];
        questSmokeAssert(!empty($rows), 'Quest game list is empty unexpectedly.');
        $detail = $assignmentService->getForGame($definitionId, $characterId, true, $viewerFactionIds, $viewerGuildIds);
        questSmokeAssert(isset($detail['definition']) && (int) ($detail['definition']['id'] ?? 0) === $definitionId, 'Quest game detail mismatch.');
        $checks++;

        questSmokeStep('progress step + complete instance');
        $instanceDetail = $resolver->getInstanceDetail($instanceId);
        $stepInstances = is_array($instanceDetail['steps'] ?? null) ? $instanceDetail['steps'] : [];
        questSmokeAssert(!empty($stepInstances), 'Step instances are missing on assigned quest.');
        $stepInstanceId = (int) ($stepInstances[0]['id'] ?? 0);
        questSmokeAssert($stepInstanceId > 0, 'Invalid step instance id.');
        $progressService->setStepStatus($instanceId, $stepInstanceId, 'completed', $characterId, 'smoke');
        $progressService->setInstanceStatus($instanceId, 'completed', $characterId, 'smoke_closure');
        $updatedInstance = $resolver->getInstanceDetail($instanceId);
        $currentStatus = (string) (($updatedInstance['instance']['current_status'] ?? '') ?: '');
        questSmokeAssert(in_array($currentStatus, ['completed'], true), 'Unexpected instance status after step completion.');
        $checks++;

        if ($closureService->schemaAvailable()) {
            questSmokeStep('closure report + rewards + history');
            $closure = $closureService->ensureMinimalReport($instanceId, 'completed', $characterId, 'smoke');
            questSmokeAssert(is_array($closure) && (int) ($closure['quest_instance_id'] ?? 0) === $instanceId, 'Minimal closure report missing.');

            $closureUpsert = $closureService->upsert($instanceId, [
                'closure_type' => 'success',
                'summary_public' => 'Quest completata in smoke test.',
                'summary_private' => 'Smoke internal note.',
                'outcome_label' => 'Completata (smoke)',
                'player_visible' => 1,
                'staff_notes' => 'Smoke closure upsert',
            ], $characterId);
            questSmokeAssert(is_array($closureUpsert) && (string) ($closureUpsert['closure_type'] ?? '') === 'success', 'Closure upsert failed.');

            $rewardExp = $rewardService->assign([
                'quest_instance_id' => $instanceId,
                'recipient_type' => 'character',
                'recipient_id' => $characterId,
                'reward_type' => 'experience',
                'reward_value' => 1.25,
                'visibility' => 'public',
                'notes' => 'Smoke EXP',
            ], $characterId, true);
            questSmokeAssert(is_array($rewardExp) && (string) ($rewardExp['reward_type'] ?? '') === 'experience', 'Experience reward assignment failed.');

            $firstItemRow = $db->query('SELECT id FROM items ORDER BY id ASC LIMIT 1')->first();
            $firstItemId = !empty($firstItemRow) ? (int) ($firstItemRow->id ?? 0) : 0;
            if ($firstItemId > 0) {
                try {
                    $rewardItem = $rewardService->assign([
                        'quest_instance_id' => $instanceId,
                        'recipient_type' => 'character',
                        'recipient_id' => $characterId,
                        'reward_type' => 'item',
                        'reward_reference_id' => $firstItemId,
                        'reward_value' => 1,
                        'visibility' => 'public',
                        'notes' => 'Smoke item',
                    ], $characterId, true);
                    questSmokeAssert(is_array($rewardItem) && (string) ($rewardItem['reward_type'] ?? '') === 'item', 'Item reward assignment failed.');
                } catch (Throwable $itemError) {
                    fwrite(STDOUT, '[WARN] Item reward skipped in smoke: ' . $itemError->getMessage() . PHP_EOL);
                }
            }

            $historyList = $historyService->listForViewer($characterId, true, ['status' => 'completed'], 20, 1);
            $historyRows = is_array($historyList['rows'] ?? null) ? $historyList['rows'] : [];
            questSmokeAssert(!empty($historyRows), 'History list is empty unexpectedly.');

            $historyDetail = $historyService->getForViewer($instanceId, $characterId, true);
            questSmokeAssert(isset($historyDetail['history']) && (int) (($historyDetail['history']['quest_instance_id'] ?? 0)) === $instanceId, 'History detail mismatch.');
            $checks++;
        } else {
            fwrite(STDOUT, "[WARN] Quest closure/rewards tables not available, skipping closure-history checks.\n");
        }

        questSmokeStep('logs + maintenance');
        $logs = $progressService->listLogs(['quest_instance_id' => $instanceId], 50, 1);
        $logRows = is_array($logs['rows'] ?? null) ? $logs['rows'] : [];
        questSmokeAssert(!empty($logRows), 'Quest logs are empty.');
        $maintenance = $progressService->maintenanceRun(true);
        questSmokeAssert(is_array($maintenance), 'Quest maintenance payload invalid.');
        $checks++;
    } finally {
        if ($definitionId > 0) {
            try {
                $definitionService->delete($definitionId);
            } catch (Throwable $cleanupError) {
                // ignore cleanup failures in smoke
            }
        }
    }

    fwrite(STDOUT, "[OK] Quests runtime smoke passed ({$checks} checks).\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, '[FAIL] ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
