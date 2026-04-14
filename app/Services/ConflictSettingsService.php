<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database\DbAdapterFactory;
use Core\Database\DbAdapterInterface;
use Core\Http\AppError;

class ConflictSettingsService
{
    public const MODE_NARRATIVE = 'narrative';
    public const MODE_RANDOM = 'random';

    public const KEY_MODE = 'conflict_resolution_mode';
    public const KEY_MARGIN_NARROW_MAX = 'conflict_margin_narrow_max';
    public const KEY_MARGIN_CLEAR_MAX = 'conflict_margin_clear_max';
    public const KEY_CRITICAL_FAILURE = 'conflict_critical_failure_value';
    public const KEY_CRITICAL_SUCCESS = 'conflict_critical_success_value';
    public const KEY_OVERLAP_POLICY = 'conflict_overlap_policy';
    public const KEY_INACTIVITY_WARNING_HOURS = 'conflict_inactivity_warning_hours';
    public const KEY_INACTIVITY_ARCHIVE_DAYS = 'conflict_inactivity_archive_days';
    public const KEY_CHAT_COMPACT_EVENTS = 'conflict_chat_compact_events';

    /** @var DbAdapterInterface */
    private $db;

    public function __construct(DbAdapterInterface $db = null)
    {
        $this->db = $db ?: DbAdapterFactory::createFromConfig();
    }

    /**
     * @param array<int,mixed> $params
     * @return array<int,mixed>
     */
    private function fetchPrepared(string $sql, array $params = []): array
    {
        return $this->db->fetchAllPrepared($sql, $params);
    }

    /**
     * @param array<int,mixed> $params
     */
    private function execPrepared(string $sql, array $params = []): void
    {
        $this->db->executePrepared($sql, $params);
    }

    /**
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        return [
            'conflict_resolution_mode' => self::MODE_NARRATIVE,
            'conflict_margin_narrow_max' => 2,
            'conflict_margin_clear_max' => 5,
            'conflict_critical_failure_value' => 1,
            // 0 means "max die value"
            'conflict_critical_success_value' => 0,
            'conflict_overlap_policy' => 'warn_only',
            'conflict_inactivity_warning_hours' => 72,
            'conflict_inactivity_archive_days' => 7,
            'conflict_chat_compact_events' => 1,
        ];
    }

    public function mode(): string
    {
        $settings = $this->getSettings();
        return (string) ($settings['conflict_resolution_mode'] ?? self::MODE_NARRATIVE);
    }

    /**
     * @return array<string, mixed>
     */
    public function getSettings(): array
    {
        $defaults = $this->defaults();
        $keys = [
            self::KEY_MODE,
            self::KEY_MARGIN_NARROW_MAX,
            self::KEY_MARGIN_CLEAR_MAX,
            self::KEY_CRITICAL_FAILURE,
            self::KEY_CRITICAL_SUCCESS,
            self::KEY_OVERLAP_POLICY,
            self::KEY_INACTIVITY_WARNING_HOURS,
            self::KEY_INACTIVITY_ARCHIVE_DAYS,
            self::KEY_CHAT_COMPACT_EVENTS,
        ];
        $placeholders = implode(', ', array_fill(0, count($keys), '?'));
        $rows = $this->fetchPrepared(
            'SELECT `key`, `value`
             FROM sys_configs
             WHERE `key` IN (' . $placeholders . ')',
            $keys,
        );

        foreach ($rows ?: [] as $row) {
            if (empty($row) || !isset($row->key)) {
                continue;
            }
            $key = (string) $row->key;
            if (!array_key_exists($key, $defaults)) {
                continue;
            }
            $defaults[$key] = (string) ($row->value ?? '');
        }

        return $this->normalizeSettings($defaults);
    }

    /**
     * @param array<string, mixed>|object $payload
     * @return array<string, mixed>
     */
    public function updateSettings($payload): array
    {
        $input = is_object($payload) ? (array) $payload : (is_array($payload) ? $payload : []);
        $current = $this->getSettings();

        $next = [
            'conflict_resolution_mode' => array_key_exists('conflict_resolution_mode', $input)
                ? $input['conflict_resolution_mode']
                : $current['conflict_resolution_mode'],
            'conflict_margin_narrow_max' => array_key_exists('conflict_margin_narrow_max', $input)
                ? $input['conflict_margin_narrow_max']
                : $current['conflict_margin_narrow_max'],
            'conflict_margin_clear_max' => array_key_exists('conflict_margin_clear_max', $input)
                ? $input['conflict_margin_clear_max']
                : $current['conflict_margin_clear_max'],
            'conflict_critical_failure_value' => array_key_exists('conflict_critical_failure_value', $input)
                ? $input['conflict_critical_failure_value']
                : $current['conflict_critical_failure_value'],
            'conflict_critical_success_value' => array_key_exists('conflict_critical_success_value', $input)
                ? $input['conflict_critical_success_value']
                : $current['conflict_critical_success_value'],
            'conflict_overlap_policy' => array_key_exists('conflict_overlap_policy', $input)
                ? $input['conflict_overlap_policy']
                : $current['conflict_overlap_policy'],
            'conflict_inactivity_warning_hours' => array_key_exists('conflict_inactivity_warning_hours', $input)
                ? $input['conflict_inactivity_warning_hours']
                : $current['conflict_inactivity_warning_hours'],
            'conflict_inactivity_archive_days' => array_key_exists('conflict_inactivity_archive_days', $input)
                ? $input['conflict_inactivity_archive_days']
                : $current['conflict_inactivity_archive_days'],
            'conflict_chat_compact_events' => array_key_exists('conflict_chat_compact_events', $input)
                ? $input['conflict_chat_compact_events']
                : $current['conflict_chat_compact_events'],
        ];

        $normalized = $this->normalizeSettings($next);

        $this->persistConfig(self::KEY_MODE, (string) $normalized['conflict_resolution_mode'], 'text');
        $this->persistConfig(self::KEY_MARGIN_NARROW_MAX, (string) $normalized['conflict_margin_narrow_max'], 'number');
        $this->persistConfig(self::KEY_MARGIN_CLEAR_MAX, (string) $normalized['conflict_margin_clear_max'], 'number');
        $this->persistConfig(self::KEY_CRITICAL_FAILURE, (string) $normalized['conflict_critical_failure_value'], 'number');
        $this->persistConfig(self::KEY_CRITICAL_SUCCESS, (string) $normalized['conflict_critical_success_value'], 'number');
        $this->persistConfig(self::KEY_OVERLAP_POLICY, (string) $normalized['conflict_overlap_policy'], 'text');
        $this->persistConfig(self::KEY_INACTIVITY_WARNING_HOURS, (string) $normalized['conflict_inactivity_warning_hours'], 'number');
        $this->persistConfig(self::KEY_INACTIVITY_ARCHIVE_DAYS, (string) $normalized['conflict_inactivity_archive_days'], 'number');
        $this->persistConfig(self::KEY_CHAT_COMPACT_EVENTS, (string) $normalized['conflict_chat_compact_events'], 'number');

        return $normalized;
    }

    /**
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    private function normalizeSettings(array $settings): array
    {
        $mode = strtolower(trim((string) ($settings['conflict_resolution_mode'] ?? self::MODE_NARRATIVE)));
        if ($mode !== self::MODE_RANDOM) {
            $mode = self::MODE_NARRATIVE;
        }

        $narrowMax = (int) ($settings['conflict_margin_narrow_max'] ?? 2);
        if ($narrowMax < 1) {
            $narrowMax = 2;
        }

        $clearMax = (int) ($settings['conflict_margin_clear_max'] ?? 5);
        if ($clearMax <= $narrowMax) {
            $clearMax = $narrowMax + 3;
        }

        $criticalFailure = (int) ($settings['conflict_critical_failure_value'] ?? 1);
        if ($criticalFailure < 1) {
            $criticalFailure = 1;
        }

        $criticalSuccess = (int) ($settings['conflict_critical_success_value'] ?? 0);
        if ($criticalSuccess < 0) {
            $criticalSuccess = 0;
        }
        if ($criticalSuccess > 0 && $criticalSuccess <= $criticalFailure) {
            throw AppError::validation(
                'Soglia successo critico non valida',
                [],
                'conflict_critical_threshold_invalid',
            );
        }

        $overlapPolicy = strtolower(trim((string) ($settings['conflict_overlap_policy'] ?? 'warn_only')));
        if (!in_array($overlapPolicy, ['warn_only'], true)) {
            $overlapPolicy = 'warn_only';
        }

        $warningHours = (int) ($settings['conflict_inactivity_warning_hours'] ?? 72);
        if ($warningHours < 1) {
            $warningHours = 1;
        }
        if ($warningHours > 720) {
            $warningHours = 720;
        }

        $archiveDays = (int) ($settings['conflict_inactivity_archive_days'] ?? 7);
        if ($archiveDays < 1) {
            $archiveDays = 1;
        }
        if ($archiveDays > 365) {
            $archiveDays = 365;
        }

        $chatCompactEvents = (int) ($settings['conflict_chat_compact_events'] ?? 1);
        $chatCompactEvents = ($chatCompactEvents === 1) ? 1 : 0;

        return [
            'conflict_resolution_mode' => $mode,
            'conflict_margin_narrow_max' => $narrowMax,
            'conflict_margin_clear_max' => $clearMax,
            'conflict_critical_failure_value' => $criticalFailure,
            'conflict_critical_success_value' => $criticalSuccess,
            'conflict_overlap_policy' => $overlapPolicy,
            'conflict_inactivity_warning_hours' => $warningHours,
            'conflict_inactivity_archive_days' => $archiveDays,
            'conflict_chat_compact_events' => $chatCompactEvents,
        ];
    }

    private function persistConfig(string $key, string $value, string $type): void
    {
        $this->execPrepared(
            'INSERT INTO sys_configs (`key`, `value`, `type`)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE
                `value` = VALUES(`value`),
                `type` = VALUES(`type`)',
            [$key, $value, $type],
        );
    }
}
