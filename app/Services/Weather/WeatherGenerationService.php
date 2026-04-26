<?php

declare(strict_types=1);

namespace App\Services\Weather;

class WeatherGenerationService
{
    /**
     * @var array<int,array<string,string>>
     */
    private const CONDITIONS = [
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

    /**
     * @var array<int,array<string,string>>
     */
    private const MOON_PHASES = [
        ['phase' => 'new', 'title' => 'Nuova'],
        ['phase' => 'waxing', 'title' => 'Crescente'],
        ['phase' => 'first-quarter', 'title' => 'Primo quarto'],
        ['phase' => 'waxing-gibbous', 'title' => 'Gibbosa crescente'],
        ['phase' => 'full', 'title' => 'Piena'],
        ['phase' => 'waning-gibbous', 'title' => 'Gibbosa calante'],
        ['phase' => 'last-quarter', 'title' => 'Ultimo quarto'],
        ['phase' => 'waning', 'title' => 'Calante'],
    ];

    /**
     * @return array{
     *     condition: array<string,string>,
     *     temperatures: array{degrees:int,minus:int},
     *     moon_phase_data: array<string,string>
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
            'moon_phase_data' => self::MOON_PHASES[$moonPhaseIdx],
        ];
    }

    /**
     * @return array<int,array<string,string>>
     */
    public function getConditions(): array
    {
        return self::CONDITIONS;
    }

    /**
     * @return array<int,array<string,string>>
     */
    public function getMoonPhases(): array
    {
        return self::MOON_PHASES;
    }

    /**
     * @return array<string,string>|null
     */
    public function getConditionByKey(string $key): ?array
    {
        foreach (self::CONDITIONS as $row) {
            if ($row['key'] === $key) {
                return $row;
            }
        }
        return null;
    }

    /**
     * @return array<string,string>|null
     */
    public function getMoonPhaseByPhase(string $phase): ?array
    {
        foreach (self::MOON_PHASES as $row) {
            if ($row['phase'] === $phase) {
                return $row;
            }
        }
        return null;
    }

    /**
     * @return array{degrees:int,minus:int}
     */
    private function computeTemperatures(int $month, int $hour, int $baseTemp, int $random): array
    {
        $offsets = [1 => -4, 2 => 1, 3 => 8, 4 => 14, 5 => 20, 6 => 28, 7 => 30, 8 => 27, 9 => 21, 10 => 15, 11 => 5, 12 => 0];
        $minus = $baseTemp + ($offsets[$month] ?? 0);

        if ($hour < 14) {
            $degrees = $minus + ((int) floor($hour / 3) * $random);
        } else {
            $degrees = $minus + (4 * $random) - ((int) floor($hour / 3) * $random) + (3 * $random);
        }

        return ['degrees' => (int) $degrees, 'minus' => (int) $minus];
    }

    /**
     * @param array{degrees:int,minus:int} $temperatures
     * @return array<string,string>
     */
    private function computeCondition(int $seed, array $temperatures): array
    {
        switch ($seed) {
            case 0:
            case 1:
            case 6:
            case 10:
            case 11:
                return self::CONDITIONS[0];
            case 2:
            case 5:
            case 7:
                return self::CONDITIONS[1];
            case 3:
            case 9:
                return self::CONDITIONS[2];
            case 4:
            case 8:
                return ($temperatures['minus'] < 2) ? self::CONDITIONS[4] : self::CONDITIONS[3];
        }
        return self::CONDITIONS[0];
    }

    private function computeMoonPhaseIndex(int $year, int $month, int $day): int
    {
        if ($month < 4) {
            $year -= 1;
            $month += 12;
        }

        $daysY = 365.25 * $year;
        $daysM = 30.42 * $month;
        $fullmoon = ($daysY + $daysM + $day - 694039.09) / 29.53;
        $phase = (int) $fullmoon;
        $fullmoon -= $phase;
        $phase = (int) round($fullmoon * 8 + 0.5);

        if ($phase === 8) {
            $phase = 0;
        }

        return $phase;
    }
}

