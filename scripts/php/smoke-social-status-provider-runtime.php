<?php

declare(strict_types=1);

/**
 * Social status provider runtime smoke (CLI).
 *
 * Usage:
 *   C:\xampp\php\php.exe scripts/php/smoke-social-status-provider-runtime.php
 */

$root = dirname(__DIR__, 2);

require_once $root . '/configs/db.php';
require_once $root . '/vendor/autoload.php';

use App\Contracts\SocialStatusProviderInterface;
use App\Services\CharacterStateService;
use App\Services\GuildService;
use App\Services\JobAdminService;
use App\Services\JobService;
use App\Services\LocationService;
use App\Services\ShopService;
use App\Services\SocialStatusProviderRegistry;
use Core\Database\DbAdapterInterface;
use Core\Hooks;

function socialStatusProviderSmokeAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

final class SocialStatusProviderSmokeOverride implements SocialStatusProviderInterface
{
    public function syncForCharacter(int $characterId, float $fame, ?int $currentStatusId): ?object
    {
        if ($characterId <= 0) {
            return null;
        }

        return (object) [
            'id' => 77,
            'name' => 'Override Status',
            'shop_discount' => 15,
        ];
    }

    public function meetsRequirement(int $characterId, ?int $requiredStatusId): bool
    {
        if ($requiredStatusId === null || $requiredStatusId <= 0) {
            return true;
        }

        return $characterId === 1001;
    }

    public function listAll(): array
    {
        return [
            (object) ['id' => 11, 'name' => 'Citizen'],
            (object) ['id' => 22, 'name' => 'Noble'],
        ];
    }

    public function getShopDiscount(int $characterId): float
    {
        return $characterId > 0 ? 15.0 : 0.0;
    }

    public function getById(int $id): ?object
    {
        $statuses = [
            11 => (object) ['id' => 11, 'name' => 'Citizen', 'min' => 0, 'shop_discount' => 0],
            22 => (object) ['id' => 22, 'name' => 'Noble', 'min' => 500, 'shop_discount' => 15],
            77 => (object) ['id' => 77, 'name' => 'Override Status', 'min' => 0, 'shop_discount' => 15],
        ];

        return $statuses[$id] ?? null;
    }
}

final class SocialStatusProviderSmokeDbAdapter implements DbAdapterInterface
{
    public function lastInsertId(): int
    {
        return 0;
    }

    public function query(string $sql)
    {
        return true;
    }

    public function queryPrepared(string $sql, array $params = [])
    {
        return true;
    }

    public function executePrepared(string $sql, array $params = []): bool
    {
        return true;
    }

    public function fetchOnePrepared(string $sql, array $params = [])
    {
        if (stripos($sql, 'COUNT(*) AS total') !== false) {
            return (object) ['total' => 1];
        }

        return null;
    }

    public function fetchAllPrepared(string $sql, array $params = []): array
    {
        if (stripos($sql, 'FROM jobs j') !== false) {
            return [
                (object) [
                    'id' => 9001,
                    'name' => 'Smoke Job',
                    'description' => null,
                    'icon' => '/assets/imgs/defaults-images/default-icon.png',
                    'location_id' => null,
                    'min_socialstatus_id' => 22,
                    'base_pay' => 10,
                    'daily_tasks' => 2,
                    'is_active' => 1,
                    'date_created' => '2026-01-01 00:00:00',
                    'location_name' => null,
                ],
            ];
        }

        return [];
    }

    public function safe(mixed $value, bool $quotes = true): string|array
    {
        if (is_array($value)) {
            return $value;
        }

        return (string) $value;
    }

    public function ifNotNull(mixed $value, mixed $altvalue = false): mixed
    {
        return $value !== null ? $value : $altvalue;
    }

    public function crypt(mixed $value): string
    {
        return (string) $value;
    }

    public function decrypt(mixed $value, mixed $alias = false): string
    {
        return (string) $value;
    }
}

try {
    $moduleBootstrapPath = $root . '/modules/logeon.social-status/bootstrap.php';
    socialStatusProviderSmokeAssert(
        is_file($moduleBootstrapPath),
        'Bootstrap modulo Social Status non trovato.',
    );
    $moduleBootstrap = require $moduleBootstrapPath;

    fwrite(STDOUT, "[STEP] fallback provider resolution (module OFF)\n");
    SocialStatusProviderRegistry::resetRuntimeState();
    SocialStatusProviderRegistry::setProvider(null);
    $shopService = new ShopService(new SocialStatusProviderSmokeDbAdapter());
    $fallback = SocialStatusProviderRegistry::provider();
    socialStatusProviderSmokeAssert(
        $fallback instanceof SocialStatusProviderInterface,
        'Fallback provider Stati sociali non risolto.',
    );
    socialStatusProviderSmokeAssert(
        SocialStatusProviderRegistry::syncForCharacter(1001, 200.0, null) === null,
        'Fallback syncForCharacter dovrebbe restituire null.',
    );
    socialStatusProviderSmokeAssert(
        SocialStatusProviderRegistry::meetsRequirement(1001, 999) === true,
        'Fallback meetsRequirement dovrebbe restituire true.',
    );
    socialStatusProviderSmokeAssert(
        SocialStatusProviderRegistry::listAll() === [],
        'Fallback listAll dovrebbe restituire array vuoto.',
    );
    socialStatusProviderSmokeAssert(
        SocialStatusProviderRegistry::getShopDiscount(1001) === 0.0,
        'Fallback getShopDiscount dovrebbe restituire 0.0.',
    );
    socialStatusProviderSmokeAssert(
        $shopService->getSocialDiscount(1001) === 0,
        'ShopService fallback dovrebbe restituire sconto 0 (provider OFF).',
    );
    $characterStateService = new CharacterStateService();
    socialStatusProviderSmokeAssert(
        $characterStateService->syncSocialStatus(1001, 200.0, null) === null,
        'CharacterStateService::syncSocialStatus fallback dovrebbe restituire null.',
    );
    $locationService = new LocationService();
    $guildService = new GuildService(new SocialStatusProviderSmokeDbAdapter());
    $jobService = new JobService();
    $jobAdminService = new JobAdminService(new SocialStatusProviderSmokeDbAdapter());
    $location = (object) [
        'id' => 501,
        'owner_id' => 999,
        'is_private' => 0,
        'is_house' => 0,
        'access_policy' => '',
        'guests' => null,
        'max_guests' => 0,
        'min_fame' => '',
        'min_socialstatus_id' => 22,
        'required_status_name' => 'Noble',
    ];
    $fallbackCharacter = (object) [
        'id' => 1002,
        'fame' => 0,
        'socialstatus_id' => 0,
    ];
    $fallbackAccess = $locationService->evaluateAccess($location, $fallbackCharacter, [], []);
    socialStatusProviderSmokeAssert(
        ($fallbackAccess['allowed'] ?? false) === true,
        'LocationService fallback dovrebbe consentire accesso su requisito status (provider OFF).',
    );
    $job = (object) ['min_socialstatus_id' => 22];
    $jobCharacterDenied = (object) ['id' => 1002, 'fame' => 0, 'socialstatus_id' => 0];
    $fallbackJobCheck = $jobService->checkJobRequirements($job, $jobCharacterDenied);
    socialStatusProviderSmokeAssert(
        ($fallbackJobCheck['allowed'] ?? false) === true,
        'JobService fallback dovrebbe consentire requisito status (provider OFF).',
    );
    $fallbackGuildStatuses = $guildService->listRequirementSocialStatuses();
    socialStatusProviderSmokeAssert(
        $fallbackGuildStatuses === [],
        'GuildService fallback dovrebbe restituire lista stati vuota (provider OFF).',
    );
    $guildRequirements = [
        (object) [
            'type' => 'min_socialstatus_id',
            'value' => 22,
            'label' => 'Stato sociale richiesto',
        ],
    ];
    $fallbackGuildCheck = $guildService->checkRequirements($guildRequirements, $jobCharacterDenied);
    socialStatusProviderSmokeAssert(
        ($fallbackGuildCheck['allowed'] ?? false) === true,
        'GuildService fallback dovrebbe consentire requisito status (provider OFF).',
    );
    $adminListPayload = (object) [
        'query' => (object) [],
        'page' => 1,
        'results' => 20,
        'orderBy' => 'j.id|ASC',
    ];
    $fallbackAdminList = $jobAdminService->list($adminListPayload);
    socialStatusProviderSmokeAssert(
        ($fallbackAdminList['dataset'][0]->social_status_name ?? null) === null,
        'JobAdminService fallback dovrebbe avere social_status_name nullo (o dataset vuoto).',
    );

    fwrite(STDOUT, "[STEP] module bootstrap registers social_status.provider hook\n");
    if (is_callable($moduleBootstrap)) {
        call_user_func($moduleBootstrap, null, ['id' => 'logeon.social-status']);
    }
    SocialStatusProviderRegistry::resetRuntimeState();
    $moduleProvider = SocialStatusProviderRegistry::provider();
    socialStatusProviderSmokeAssert(
        $moduleProvider instanceof \Modules\Logeon\SocialStatus\SocialStatusModuleProvider,
        'Provider modulo Social Status non risolto via bootstrap.',
    );

    fwrite(STDOUT, "[STEP] hook override provider resolution (module ON)\n");
    Hooks::add('social_status.provider', function ($currentProvider) {
        return new SocialStatusProviderSmokeOverride();
    });
    SocialStatusProviderRegistry::resetRuntimeState();
    $override = SocialStatusProviderRegistry::provider();
    socialStatusProviderSmokeAssert(
        $override instanceof SocialStatusProviderInterface,
        'Provider override Stati sociali non risolto.',
    );

    $status = SocialStatusProviderRegistry::syncForCharacter(1001, 250.0, 10);
    socialStatusProviderSmokeAssert(
        (int) ($status->id ?? 0) === 77,
        'Override syncForCharacter non applicato.',
    );
    socialStatusProviderSmokeAssert(
        SocialStatusProviderRegistry::meetsRequirement(1001, 22) === true,
        'Override meetsRequirement true atteso non applicato.',
    );
    socialStatusProviderSmokeAssert(
        SocialStatusProviderRegistry::meetsRequirement(1002, 22) === false,
        'Override meetsRequirement false atteso non applicato.',
    );

    $list = SocialStatusProviderRegistry::listAll();
    socialStatusProviderSmokeAssert(
        (int) ($list[1]->id ?? 0) === 22,
        'Override listAll non applicato.',
    );
    socialStatusProviderSmokeAssert(
        SocialStatusProviderRegistry::getShopDiscount(1001) === 15.0,
        'Override getShopDiscount non applicato.',
    );
    socialStatusProviderSmokeAssert(
        $shopService->getSocialDiscount(1001) === 15,
        'ShopService dovrebbe risolvere lo sconto via provider (override ON).',
    );
    $synced = $characterStateService->syncSocialStatus(1001, 250.0, null);
    socialStatusProviderSmokeAssert(
        (int) ($synced->id ?? 0) === 77,
        'CharacterStateService::syncSocialStatus non instradato al provider override.',
    );
    $overrideDeniedAccess = $locationService->evaluateAccess($location, $fallbackCharacter, [], []);
    socialStatusProviderSmokeAssert(
        ($overrideDeniedAccess['allowed'] ?? true) === false
            && (string) ($overrideDeniedAccess['reason_code'] ?? '') === 'social_status',
        'LocationService dovrebbe negare accesso quando provider override non soddisfa requisito status.',
    );
    $overrideAllowedCharacter = (object) [
        'id' => 1001,
        'fame' => 0,
        'socialstatus_id' => 0,
    ];
    $overrideAllowedAccess = $locationService->evaluateAccess($location, $overrideAllowedCharacter, [], []);
    socialStatusProviderSmokeAssert(
        ($overrideAllowedAccess['allowed'] ?? false) === true,
        'LocationService dovrebbe consentire accesso quando provider override soddisfa requisito status.',
    );
    $overrideDeniedJobCheck = $jobService->checkJobRequirements($job, $jobCharacterDenied);
    socialStatusProviderSmokeAssert(
        ($overrideDeniedJobCheck['allowed'] ?? true) === false
            && (string) ($overrideDeniedJobCheck['reason'] ?? '') === 'Stato sociale insufficiente',
        'JobService dovrebbe negare requisito status quando provider override non soddisfa.',
    );
    $jobCharacterAllowed = (object) ['id' => 1001, 'fame' => 0, 'socialstatus_id' => 0];
    $overrideAllowedJobCheck = $jobService->checkJobRequirements($job, $jobCharacterAllowed);
    socialStatusProviderSmokeAssert(
        ($overrideAllowedJobCheck['allowed'] ?? false) === true,
        'JobService dovrebbe consentire requisito status quando provider override soddisfa.',
    );
    $overrideGuildStatuses = $guildService->listRequirementSocialStatuses();
    socialStatusProviderSmokeAssert(
        (int) ($overrideGuildStatuses[1]->id ?? 0) === 22,
        'GuildService dovrebbe risolvere lista stati requisito via provider listAll().',
    );
    $overrideDeniedGuildCheck = $guildService->checkRequirements($guildRequirements, $jobCharacterDenied);
    socialStatusProviderSmokeAssert(
        ($overrideDeniedGuildCheck['allowed'] ?? true) === false,
        'GuildService dovrebbe negare requisito status quando provider override non soddisfa.',
    );
    $overrideAllowedGuildCheck = $guildService->checkRequirements($guildRequirements, $jobCharacterAllowed);
    socialStatusProviderSmokeAssert(
        ($overrideAllowedGuildCheck['allowed'] ?? false) === true,
        'GuildService dovrebbe consentire requisito status quando provider override soddisfa.',
    );
    $overrideAdminList = $jobAdminService->list($adminListPayload);
    socialStatusProviderSmokeAssert(
        (string) ($overrideAdminList['dataset'][0]->social_status_name ?? '') === 'Noble',
        'JobAdminService dovrebbe valorizzare social_status_name via provider listAll().',
    );

    fwrite(STDOUT, "[OK] Social status provider runtime smoke passed.\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, '[FAIL] Social status provider runtime smoke failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}


