<?php

declare(strict_types=1);

namespace App\Services;

/**
 * WeatherGenerationService
 *
 * Pure, stateless seeded weather generation.
 * Extracted from the Weathers controller to become the canonical
 * computation layer consumed by WeatherResolverService.
 *
 * No database access. No side-effects. Deterministic by timestamp.
 */
class WeatherGenerationService
{
    // -------------------------------------------------------------------------
    // Static data catalogues
    // -------------------------------------------------------------------------

    public static $conditions = [
        [
            'key' => 'clear',
            'title' => 'Sereno',
            'img' => '',
            'body' => '<div class="sun"><div class="rays"></div></div>',
        ],
        [
            'key' => 'variable',
            'title' => 'Variabile',
            'img' => '',
            'body' => '<div class="cloud"></div><div class="sun"><div class="rays"></div></div>',
        ],
        [
            'key' => 'cloudy',
            'title' => 'Nuvoloso',
            'img' => '',
            'body' => '<div class="cloud"></div><div class="cloud"></div>',
        ],
        [
            'key' => 'rain',
            'title' => 'Pioggia',
            'img' => '',
            'body' => '<div class="cloud"></div><div class="rain"></div>',
        ],
        [
            'key' => 'snow',
            'title' => 'Neve',
            'img' => '',
            'body' => '<div class="cloud"></div><div class="snow"><div class="flake"></div><div class="flake"></div></div>',
        ],
    ];

    public static $moonPhases = [
        ['phase' => 'new',            'title' => 'Nuova'],
        ['phase' => 'waxing',         'title' => 'Crescente'],
        ['phase' => 'first-quarter',  'title' => 'Primo quarto'],
        ['phase' => 'waxing-gibbous', 'title' => 'Gibbosa crescente'],
        ['phase' => 'full',           'title' => 'Piena'],
        ['phase' => 'waning-gibbous', 'title' => 'Gibbosa calante'],
        ['phase' => 'last-quarter',   'title' => 'Ultimo quarto'],
        ['phase' => 'waning',         'title' => 'Calante'],
    ];

    // -------------------------------------------------------------------------
    // Main generation entry-point
    // -------------------------------------------------------------------------

    /**
     * Generate a complete auto weather state.
     *
     * @param int      $baseTemperature Configurable base temp (default 12 °C)
     * @param int|null $timestamp       Unix timestamp to compute for (default: now)
     * @return array {
     *   condition        => array  (full condition row)
     *   temperatures     => array  {degrees, minus}
     *   moon_phase_data  => array  (full moon phase row)
     * }
     */
    public function generate(int $baseTemperature = 12, ?int $timestamp = null): array
    {
        $ts = $timestamp ?? time();
        $year = (int) date('Y', $ts);
        $month = (int) date('n', $ts);
        $day = (int) date('j', $ts);
        $hour = (int) date('H', $ts);
        $minute = (int) date('i', $ts);

        $random = (($day + (int) floor($hour / 6)) % 2) + 1;
        $seed = ($day + $month + (int) floor($hour / 2) + (int) floor($minute / 10)) % 12;

        $temperatures = $this->computeTemperatures($month, $hour, $baseTemperature, $random);
        $condition = $this->computeCondition($seed, $temperatures);
        $moonPhaseIdx = $this->computeMoonPhaseIndex($year, $month, $day);

        return [
            'condition' => $condition,
            'temperatures' => $temperatures,
            'moon_phase_data' => static::$moonPhases[$moonPhaseIdx],
        ];
    }

    // -------------------------------------------------------------------------
    // Catalogue lookups
    // -------------------------------------------------------------------------

    public function getConditions(): array
    {
        return static::$conditions;
    }

    public function getMoonPhases(): array
    {
        return static::$moonPhases;
    }

    public function getConditionByKey(string $key): ?array
    {
        foreach (static::$conditions as $row) {
            if ($row['key'] === $key) {
                return $row;
            }
        }
        return null;
    }

    public function getMoonPhaseByPhase(string $phase): ?array
    {
        foreach (static::$moonPhases as $row) {
            if ($row['phase'] === $phase) {
                return $row;
            }
        }
        return null;
    }

    // -------------------------------------------------------------------------
    // Private computation methods (mirror of original controller logic)
    // -------------------------------------------------------------------------

    private function computeTemperatures(int $month, int $hour, int $baseTemp, int $random): array
    {
        $offsets = [1 => -4, 2 => 1, 3 => 8, 4 => 14, 5 => 20,
                    6 => 28, 7 => 30, 8 => 27, 9 => 21, 10 => 15, 11 => 5, 12 => 0];

        $minus = $baseTemp + ($offsets[$month] ?? 0);

        if ($hour < 14) {
            $degrees = $minus + ((int) floor($hour / 3) * $random);
        } else {
            $degrees = $minus + (4 * $random) - ((int) floor($hour / 3) * $random) + (3 * $random);
        }

        return ['degrees' => (int) $degrees, 'minus' => (int) $minus];
    }

    private function computeCondition(int $seed, array $temperatures): array
    {
        switch ($seed) {
            case 0: case 1: case 6: case 10: case 11:
                return static::$conditions[0]; // clear
            case 2: case 5: case 7:
                return static::$conditions[1]; // variable
            case 3: case 9:
                return static::$conditions[2]; // cloudy
            case 4: case 8:
                // below ~2°C → snow, otherwise rain
                return ($temperatures['minus'] < 2)
                    ? static::$conditions[4]   // snow
                    : static::$conditions[3];  // rain
        }
        return static::$conditions[0]; // fallback: clear
    }

    private function computeMoonPhaseIndex(int $year, int $month, int $day): int
    {
        // Use the same astronomical algorithm as the original controller
        if ($month < 4) {
            $year -= 1;
            $month += 12;
        }

        $days_y = 365.25 * $year;
        $days_m = 30.42 * $month;
        $fullmoon = ($days_y + $days_m + $day - 694039.09) / 29.53;
        $phase = (int) $fullmoon;
        $fullmoon = $fullmoon - $phase;
        $phase = (int) round($fullmoon * 8 + 0.5);

        if ($phase === 8) {
            $phase = 0;
        }

        return $phase;
    }
}
