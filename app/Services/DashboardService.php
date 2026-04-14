<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database\DbAdapterFactory;
use Core\Database\DbAdapterInterface;

class DashboardService
{
    /** @var DbAdapterInterface */
    private $db;
    /** @var array<string,bool> */
    private $tableExistsCache = [];
    /** @var array<string,bool> */
    private $columnExistsCache = [];

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

    private function tableExists($table)
    {
        $table = (string) $table;
        if ($table === '') {
            return false;
        }

        if (array_key_exists($table, $this->tableExistsCache)) {
            return $this->tableExistsCache[$table];
        }

        $row = $this->firstPrepared(
            'SELECT 1 AS ok
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
             LIMIT 1',
            [$table],
        );

        $exists = !empty($row);
        $this->tableExistsCache[$table] = $exists;
        return $exists;
    }

    private function columnExists($table, $column)
    {
        $table = (string) $table;
        $column = (string) $column;
        $cacheKey = $table . '.' . $column;

        if (array_key_exists($cacheKey, $this->columnExistsCache)) {
            return $this->columnExistsCache[$cacheKey];
        }

        if (!$this->tableExists($table)) {
            $this->columnExistsCache[$cacheKey] = false;
            return false;
        }

        $row = $this->firstPrepared(
            'SELECT 1 AS ok
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?
             LIMIT 1',
            [$table, $column],
        );

        $exists = !empty($row);
        $this->columnExistsCache[$cacheKey] = $exists;
        return $exists;
    }

    private function countWhere($table, $where = '1')
    {
        if (!$this->tableExists($table)) {
            return 0;
        }

        $row = $this->firstPrepared(
            'SELECT COUNT(*) AS total
             FROM `' . $table . '`
             WHERE ' . $where,
        );

        return !empty($row) ? (int) $row->total : 0;
    }

    private function countSinceDays($table, $dateColumn, $days)
    {
        $days = max(1, (int) $days);
        if (!$this->columnExists($table, $dateColumn)) {
            return 0;
        }

        return $this->countWhere(
            $table,
            '`' . $dateColumn . '` >= DATE_SUB(NOW(), INTERVAL ' . $days . ' DAY)',
        );
    }

    private function countBetweenDaysOffsets($table, $dateColumn, $startDays, $endDays)
    {
        $startDays = max(0, (int) $startDays);
        $endDays = max(0, (int) $endDays);

        if ($startDays <= $endDays) {
            return 0;
        }
        if (!$this->columnExists($table, $dateColumn)) {
            return 0;
        }

        return $this->countWhere(
            $table,
            '`' . $dateColumn . '` >= DATE_SUB(NOW(), INTERVAL ' . $startDays . ' DAY)
             AND `' . $dateColumn . '` < DATE_SUB(NOW(), INTERVAL ' . $endDays . ' DAY)',
        );
    }

    private function countSinceHours($table, $dateColumn, $hours)
    {
        $hours = max(1, (int) $hours);
        if (!$this->columnExists($table, $dateColumn)) {
            return 0;
        }

        return $this->countWhere(
            $table,
            '`' . $dateColumn . '` >= DATE_SUB(NOW(), INTERVAL ' . $hours . ' HOUR)',
        );
    }

    private function buildDateWindow($days)
    {
        $days = max(1, (int) $days);
        $today = new \DateTimeImmutable('today');
        $start = $today->sub(new \DateInterval('P' . ($days - 1) . 'D'));

        $dates = [];
        $labels = [];
        for ($i = 0; $i < $days; $i++) {
            $date = $start->add(new \DateInterval('P' . $i . 'D'));
            $dates[] = $date->format('Y-m-d');
            $labels[] = $date->format('d/m');
        }

        return [
            'dates' => $dates,
            'labels' => $labels,
        ];
    }

    private function seriesDaily($table, $dateColumn, $days, $extraWhere = '1')
    {
        $days = max(1, (int) $days);
        $window = $this->buildDateWindow($days);
        $series = array_fill(0, count($window['dates']), 0);

        if (!$this->columnExists($table, $dateColumn)) {
            return $series;
        }

        $fromDays = max(0, $days - 1);
        $rows = $this->fetchPrepared(
            'SELECT DATE(`' . $dateColumn . '`) AS day_key, COUNT(*) AS total
             FROM `' . $table . '`
             WHERE `' . $dateColumn . '` >= DATE_SUB(CURDATE(), INTERVAL ' . $fromDays . ' DAY)
               AND ' . $extraWhere . '
             GROUP BY DATE(`' . $dateColumn . '`)',
        );

        $map = [];
        if (!empty($rows)) {
            foreach ($rows as $row) {
                if (!empty($row->day_key)) {
                    $map[$row->day_key] = (int) $row->total;
                }
            }
        }

        foreach ($window['dates'] as $index => $dayKey) {
            if (isset($map[$dayKey])) {
                $series[$index] = $map[$dayKey];
            }
        }

        return $series;
    }

    private function availabilityDistribution()
    {
        $distribution = [
            'busy' => 0,
            'free' => 0,
            'away' => 0,
            'other' => 0,
        ];

        if (!$this->columnExists('characters', 'availability')) {
            return $distribution;
        }

        $rows = $this->fetchPrepared(
            'SELECT availability, COUNT(*) AS total
             FROM characters
             GROUP BY availability',
        );

        if (empty($rows)) {
            return $distribution;
        }

        foreach ($rows as $row) {
            $key = 'other';
            $availability = (int) $row->availability;
            if ($availability === 0) {
                $key = 'busy';
            } elseif ($availability === 1) {
                $key = 'free';
            } elseif ($availability === 2) {
                $key = 'away';
            }
            $distribution[$key] += (int) $row->total;
        }

        return $distribution;
    }

    private function topLocations($limit = 5, $days = 7)
    {
        $limit = max(1, (int) $limit);
        $days = max(1, (int) $days);
        $dataset = [];

        if (!$this->columnExists('locations_messages', 'location_id') || !$this->columnExists('locations_messages', 'date_created')) {
            return $dataset;
        }

        $hasLocations = $this->tableExists('locations');
        if ($hasLocations) {
            $rows = $this->fetchPrepared(
                'SELECT COALESCE(l.name, CONCAT(\'Location #\', m.location_id)) AS name, COUNT(*) AS total
                 FROM locations_messages m
                 LEFT JOIN locations l ON l.id = m.location_id
                 WHERE m.date_created >= DATE_SUB(NOW(), INTERVAL ' . $days . ' DAY)
                 GROUP BY m.location_id, l.name
                 ORDER BY total DESC
                 LIMIT ' . $limit,
            );
        } else {
            $rows = $this->fetchPrepared(
                'SELECT CONCAT(\'Location #\', m.location_id) AS name, COUNT(*) AS total
                 FROM locations_messages m
                 WHERE m.date_created >= DATE_SUB(NOW(), INTERVAL ' . $days . ' DAY)
                 GROUP BY m.location_id
                 ORDER BY total DESC
                 LIMIT ' . $limit,
            );
        }

        if (empty($rows)) {
            return $dataset;
        }

        foreach ($rows as $row) {
            $dataset[] = [
                'name' => (string) $row->name,
                'total' => (int) $row->total,
            ];
        }

        return $dataset;
    }

    private function normalizePeriod($value)
    {
        $code = strtolower(trim((string) $value));
        if ($code !== '7d' && $code !== '90d') {
            $code = '30d';
        }

        $days = 30;
        if ($code === '7d') {
            $days = 7;
        } elseif ($code === '90d') {
            $days = 90;
        }

        return [
            'code' => $code,
            'days' => $days,
        ];
    }

    private function buildPeriodComparison($current, $previous)
    {
        $current = (int) $current;
        $previous = (int) $previous;
        $delta = $current - $previous;
        $trend = 'flat';
        if ($delta > 0) {
            $trend = 'up';
        } elseif ($delta < 0) {
            $trend = 'down';
        }

        $deltaPercent = null;
        if ($previous > 0) {
            $deltaPercent = round(($delta / $previous) * 100, 2);
        }

        return [
            'current' => $current,
            'previous' => $previous,
            'delta' => $delta,
            'delta_percent' => $deltaPercent,
            'trend' => $trend,
            'is_new' => ($previous === 0 && $current > 0),
        ];
    }

    private function recentLogs($limit = 8)
    {
        $limit = max(1, (int) $limit);
        $dataset = [];

        if (!$this->tableExists('sys_logs')) {
            return $dataset;
        }

        $rows = $this->fetchPrepared(
            'SELECT id, area, action, date_created
             FROM sys_logs
             ORDER BY id DESC
             LIMIT ' . $limit,
        );

        if (empty($rows)) {
            return $dataset;
        }

        foreach ($rows as $row) {
            $dataset[] = [
                'id' => (int) $row->id,
                'area' => (string) ($row->area ?? ''),
                'action' => (string) ($row->action ?? ''),
                'date_created' => (string) ($row->date_created ?? ''),
            ];
        }

        return $dataset;
    }

    public function buildSummary($periodInput): array
    {
        $period = $this->normalizePeriod($periodInput);
        $days = (int) $period['days'];
        $window = $this->buildDateWindow($days);

        $kpi = [
            'users_total' => $this->countWhere('users'),
            'characters_total' => $this->countWhere('characters'),
            'users_new_period' => $this->countSinceDays('users', 'date_created', $days),
            'characters_new_period' => $this->countSinceDays('characters', 'date_created', $days),
            'location_messages_period' => $this->countSinceDays('locations_messages', 'date_created', $days),
            'dm_messages_period' => $this->countSinceDays('messages', 'date_created', $days),
            'forum_threads_period' => $this->countSinceDays('forum_threads', 'date_created', $days),
            'characters_active_24h' => $this->countSinceHours('characters', 'date_last_seed', 24),
            'location_messages_24h' => $this->countSinceHours('locations_messages', 'date_created', 24),
            'dm_messages_24h' => $this->countSinceHours('messages', 'date_created', 24),
            'forum_threads_24h' => $this->countSinceHours('forum_threads', 'date_created', 24),
            'shop_purchases_24h' => $this->countSinceHours('shop_purchases', 'date_created', 24),
            'shop_sales_24h' => $this->countSinceHours('shop_sales', 'date_created', 24),
        ];

        $previousUsers = $this->countBetweenDaysOffsets('users', 'date_created', $days * 2, $days);
        $previousCharacters = $this->countBetweenDaysOffsets('characters', 'date_created', $days * 2, $days);
        $previousLocationMessages = $this->countBetweenDaysOffsets('locations_messages', 'date_created', $days * 2, $days);
        $previousDmMessages = $this->countBetweenDaysOffsets('messages', 'date_created', $days * 2, $days);
        $previousForumThreads = $this->countBetweenDaysOffsets('forum_threads', 'date_created', $days * 2, $days);

        $timeseries = [
            'labels' => $window['labels'],
            'location_messages_daily' => $this->seriesDaily('locations_messages', 'date_created', $days),
            'dm_messages_daily' => $this->seriesDaily('messages', 'date_created', $days),
            'forum_threads_daily' => $this->seriesDaily('forum_threads', 'date_created', $days),
            'users_daily' => $this->seriesDaily('users', 'date_created', $days),
            'characters_daily' => $this->seriesDaily('characters', 'date_created', $days),
        ];

        $distributions = [
            'availability' => $this->availabilityDistribution(),
            'top_locations' => $this->topLocations(5, $days),
        ];

        return [
            'success' => true,
            'dataset' => [
                'meta' => [
                    'period' => $period['code'],
                    'days' => $days,
                ],
                'kpi' => $kpi,
                'compare' => [
                    'users_new_period' => $this->buildPeriodComparison($kpi['users_new_period'], $previousUsers),
                    'characters_new_period' => $this->buildPeriodComparison($kpi['characters_new_period'], $previousCharacters),
                    'location_messages_period' => $this->buildPeriodComparison($kpi['location_messages_period'], $previousLocationMessages),
                    'dm_messages_period' => $this->buildPeriodComparison($kpi['dm_messages_period'], $previousDmMessages),
                    'forum_threads_period' => $this->buildPeriodComparison($kpi['forum_threads_period'], $previousForumThreads),
                ],
                'timeseries' => $timeseries,
                'distributions' => $distributions,
                'recent' => [
                    'logs' => $this->recentLogs(8),
                ],
            ],
            'message' => '',
            'error_code' => '',
        ];
    }
}
