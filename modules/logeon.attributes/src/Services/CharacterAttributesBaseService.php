<?php

declare(strict_types=1);

namespace Modules\Logeon\Attributes\Services;

use Core\Database\DbAdapterFactory;
use Core\Database\DbAdapterInterface;
use Core\Http\AppError;

abstract class CharacterAttributesBaseService
{
    /** @var DbAdapterInterface */
    protected $db;

    public function __construct(DbAdapterInterface $db = null)
    {
        $this->db = $db ?: DbAdapterFactory::createFromConfig();
    }

    protected function begin(): void
    {
        $this->db->query('START TRANSACTION');
    }

    protected function commit(): void
    {
        $this->db->query('COMMIT');
    }

    protected function rollback(): void
    {
        try {
            $this->db->query('ROLLBACK');
        } catch (\Throwable $e) {
            // Best effort rollback.
        }
    }

    protected function normalizeBool($value, int $default = 0): int
    {
        if ($value === null || $value === '') {
            return $default === 1 ? 1 : 0;
        }

        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        $raw = strtolower(trim((string) $value));
        if (in_array($raw, ['1', 'true', 'yes', 'on'], true)) {
            return 1;
        }
        if (in_array($raw, ['0', 'false', 'no', 'off'], true)) {
            return 0;
        }

        return ((int) $value === 1) ? 1 : 0;
    }

    protected function normalizeString($value, bool $allowEmpty = false): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = trim((string) $value);
        if ($text === '' && !$allowEmpty) {
            return null;
        }

        return $text;
    }

    protected function normalizePage($value): int
    {
        $page = (int) $value;
        return ($page > 0) ? $page : 1;
    }

    protected function normalizeResults($value, int $default = 20, int $max = 200): int
    {
        $results = (int) $value;
        if ($results <= 0) {
            $results = $default;
        }
        if ($results > $max) {
            $results = $max;
        }
        return $results;
    }

    protected function normalizeDecimalValue($value, string $field, string $errorCode, bool $allowNull = true): ?float
    {
        if ($value === null || $value === '') {
            if ($allowNull) {
                return null;
            }
            throw AppError::validation('Valore non valido per ' . $field, [], $errorCode);
        }

        $text = trim((string) $value);
        $text = str_replace(',', '.', $text);
        if ($text === '' && $allowNull) {
            return null;
        }
        if (!is_numeric($text)) {
            throw AppError::validation('Valore non valido per ' . $field, [], $errorCode);
        }

        return round((float) $text, 2);
    }

    protected function clampAndRound(float $value, ?float $minValue, ?float $maxValue, string $roundMode = 'none'): float
    {
        $next = $value;

        $mode = strtolower(trim($roundMode));
        if ($mode === 'floor') {
            $next = floor($next);
        } elseif ($mode === 'ceil') {
            $next = ceil($next);
        } elseif ($mode === 'round') {
            $next = round($next);
        } else {
            $next = round($next, 2);
        }

        if ($minValue !== null && $next < $minValue) {
            $next = $minValue;
        }
        if ($maxValue !== null && $next > $maxValue) {
            $next = $maxValue;
        }

        return round((float) $next, 2);
    }

    protected function normalizeOrderBy($orderBy, array $allowed, string $defaultField, string $defaultDirection = 'ASC'): array
    {
        $defaultDirection = strtoupper($defaultDirection) === 'DESC' ? 'DESC' : 'ASC';
        $raw = trim((string) $orderBy);
        if ($raw === '') {
            $raw = $defaultField . '|' . $defaultDirection;
        }

        $parts = explode('|', $raw);
        $field = trim((string) $parts[0]);
        $direction = strtoupper(trim((string) ($parts[1] ?? $defaultDirection)));
        if ($direction !== 'DESC') {
            $direction = 'ASC';
        }

        if (!array_key_exists($field, $allowed)) {
            $field = $defaultField;
        }

        return [
            'field' => $field,
            'direction' => $direction,
            'raw' => $field . '|' . $direction,
            'sql' => $allowed[$field] . ' ' . $direction,
        ];
    }

    protected function tableExists(string $table): bool
    {
        $table = trim($table);
        if ($table === '' || preg_match('/^[A-Za-z0-9_]+$/', $table) !== 1) {
            return false;
        }

        try {
            $row = $this->db->fetchOnePrepared(
                'SELECT COUNT(*) AS n
                 FROM information_schema.tables
                 WHERE table_schema = DATABASE()
                   AND table_name = ?',
                [$table],
            );
            return ((int) ($row->n ?? 0)) > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    protected function rowCountValue(int $fallback = 0): int
    {
        $row = $this->db->fetchOnePrepared('SELECT ROW_COUNT() AS count');
        if (empty($row) || !isset($row->count)) {
            return $fallback;
        }
        return (int) $row->count;
    }
}


