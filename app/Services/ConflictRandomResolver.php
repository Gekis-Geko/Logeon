<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\ConflictResolverInterface;
use App\Services\AttributeProviderRegistry;
use Core\Database\DbAdapterFactory;
use Core\Database\DbAdapterInterface;
use Core\Http\AppError;

class ConflictRandomResolver implements ConflictResolverInterface
{
    private const ALLOWED_DICE = [
        'd4' => 4,
        'd6' => 6,
        'd8' => 8,
        'd10' => 10,
        'd12' => 12,
        'd16' => 16,
        'd20' => 20,
        'd100' => 100,
    ];

    private const ALLOWED_ROLL_TYPES = [
        'single_roll',
        'single_roll_with_modifiers',
        'opposed_roll',
        'threshold_roll',
    ];

    /** @var DbAdapterInterface */
    private $db;
    /** @var ConflictSettingsService */
    private $settings;
    public function __construct(
        DbAdapterInterface $db = null,
        ConflictSettingsService $settings = null,
    ) {
        $this->db = $db ?: DbAdapterFactory::createFromConfig();
        $this->settings = $settings ?: new ConflictSettingsService($this->db);
    }

    private function execPrepared(string $sql, array $params = []): void
    {
        $this->db->executePrepared($sql, $params);
    }

    public function mode(): string
    {
        return ConflictSettingsService::MODE_RANDOM;
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function resolveConflict(array $context): array
    {
        $payload = is_array($context['roll_payload'] ?? null) ? $context['roll_payload'] : [];
        if (empty($payload)) {
            return [
                'mode' => $this->mode(),
                'message' => 'Nessun tiro eseguito',
            ];
        }

        $result = $this->performRoll($payload);
        return [
            'mode' => $this->mode(),
            'roll' => $result,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function performRoll(array $payload): array
    {
        $conflictId = (int) ($payload['conflict_id'] ?? 0);
        if ($conflictId <= 0) {
            throw AppError::validation('Conflitto non valido', [], 'conflict_not_found');
        }

        $actorId = (int) ($payload['actor_id'] ?? 0);
        if ($actorId <= 0) {
            throw AppError::validation('Attore non valido', [], 'conflict_actor_required');
        }

        $rollType = $this->normalizeRollType($payload['roll_type'] ?? 'single_roll');
        $dieUsed = $this->normalizeDie($payload['die_used'] ?? 'd20');
        $dieMax = self::ALLOWED_DICE[$dieUsed];

        $actorResult = $this->buildRollForActor(
            $actorId,
            $dieUsed,
            $dieMax,
            $payload['actor_modifier'] ?? 0,
            $payload['actor_attribute_slug'] ?? '',
            $payload['actor_temporary_bonus'] ?? 0,
        );

        if ($rollType === 'opposed_roll') {
            $targetId = (int) ($payload['target_id'] ?? 0);
            if ($targetId <= 0) {
                throw AppError::validation('Bersaglio richiesto', [], 'conflict_target_required');
            }

            $targetResult = $this->buildRollForActor(
                $targetId,
                $dieUsed,
                $dieMax,
                $payload['target_modifier'] ?? 0,
                $payload['target_attribute_slug'] ?? '',
                $payload['target_temporary_bonus'] ?? 0,
            );

            $signedMargin = round($actorResult['final_result'] - $targetResult['final_result'], 2);
            $winnerId = 0;
            if ($signedMargin > 0) {
                $winnerId = $actorId;
            } elseif ($signedMargin < 0) {
                $winnerId = $targetId;
            }

            $marginInfo = $this->evaluateMargin((float) abs($signedMargin));

            $this->logRollRow($conflictId, $actorId, $rollType, $dieUsed, $actorResult, $signedMargin);
            $this->logRollRow($conflictId, $targetId, $rollType, $dieUsed, $targetResult, -$signedMargin);

            return [
                'conflict_id' => $conflictId,
                'mode' => $this->mode(),
                'roll_type' => $rollType,
                'die_used' => $dieUsed,
                'actor' => $actorResult,
                'target' => $targetResult,
                'winner_id' => $winnerId,
                'margin' => abs($signedMargin),
                'margin_label' => $marginInfo['label'],
                'margin_text' => $marginInfo['text'],
            ];
        }

        $margin = null;
        $marginInfo = ['label' => null, 'text' => null];
        $threshold = null;
        $success = null;
        if ($rollType === 'threshold_roll') {
            $threshold = $this->normalizeThreshold($payload['threshold'] ?? null, $dieMax);
            $margin = round($actorResult['final_result'] - $threshold, 2);
            $success = ($actorResult['final_result'] >= $threshold);
            $marginInfo = $this->evaluateMargin((float) abs($margin));
        }

        $this->logRollRow($conflictId, $actorId, $rollType, $dieUsed, $actorResult, $margin);

        return [
            'conflict_id' => $conflictId,
            'mode' => $this->mode(),
            'roll_type' => $rollType,
            'die_used' => $dieUsed,
            'actor' => $actorResult,
            'threshold' => $threshold,
            'success' => $success,
            'margin' => $margin,
            'margin_label' => $marginInfo['label'],
            'margin_text' => $marginInfo['text'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function evaluateMargin(float $margin): array
    {
        $settings = $this->settings->getSettings();
        $narrowMax = (float) ($settings['conflict_margin_narrow_max'] ?? 2);
        $clearMax = (float) ($settings['conflict_margin_clear_max'] ?? 5);
        $value = abs($margin);

        if ($value <= $narrowMax) {
            return ['label' => 'narrow_success', 'text' => 'Successo stretto'];
        }
        if ($value <= $clearMax) {
            return ['label' => 'clear_success', 'text' => 'Successo chiaro'];
        }
        return ['label' => 'overwhelming_success', 'text' => 'Successo travolgente'];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function closeConflict(array $payload): array
    {
        return [
            'mode' => $this->mode(),
            'closed_by' => (int) ($payload['closed_by'] ?? 0),
            'closed_at' => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildRollForActor(
        int $actorId,
        string $dieUsed,
        int $dieMax,
        $manualModifier,
        $attributeSlug,
        $temporaryBonus,
    ): array {
        $baseRoll = random_int(1, $dieMax);
        $manual = $this->normalizeNumber($manualModifier);
        $temporary = $this->normalizeNumber($temporaryBonus);
        $attribute = $this->resolveAttributeModifier($actorId, (string) $attributeSlug);
        $totalModifier = round($manual + $temporary + $attribute, 2);
        $finalResult = round($baseRoll + $totalModifier, 2);

        return [
            'actor_id' => $actorId,
            'die_used' => $dieUsed,
            'base_roll' => $baseRoll,
            'manual_modifier' => $manual,
            'temporary_bonus' => $temporary,
            'attribute_modifier' => $attribute,
            'modifiers' => $totalModifier,
            'final_result' => $finalResult,
            'critical_flag' => $this->resolveCriticalFlag($baseRoll, $dieMax),
        ];
    }

    private function normalizeRollType($value): string
    {
        $text = strtolower(trim((string) $value));
        if (!in_array($text, self::ALLOWED_ROLL_TYPES, true)) {
            return 'single_roll';
        }
        return $text;
    }

    private function normalizeDie($value): string
    {
        $text = strtolower(trim((string) $value));
        if (!array_key_exists($text, self::ALLOWED_DICE)) {
            return 'd20';
        }
        return $text;
    }

    private function normalizeThreshold($value, int $default): float
    {
        $number = $this->normalizeNumber($value);
        if ($number <= 0) {
            return (float) $default;
        }
        return round($number, 2);
    }

    private function normalizeNumber($value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }
        $text = str_replace(',', '.', trim((string) $value));
        if ($text === '' || !is_numeric($text)) {
            return 0.0;
        }
        return round((float) $text, 2);
    }

    private function resolveCriticalFlag(int $baseRoll, int $dieMax): string
    {
        $settings = $this->settings->getSettings();
        $failureThreshold = (int) ($settings['conflict_critical_failure_value'] ?? 1);
        if ($failureThreshold < 1) {
            $failureThreshold = 1;
        }
        $successThreshold = (int) ($settings['conflict_critical_success_value'] ?? 0);
        if ($successThreshold <= 0 || $successThreshold > $dieMax) {
            $successThreshold = $dieMax;
        }

        if ($baseRoll <= $failureThreshold) {
            return 'failure';
        }
        if ($baseRoll >= $successThreshold) {
            return 'success';
        }

        return 'none';
    }

    private function resolveAttributeModifier(int $characterId, string $attributeSlug): float
    {
        $attributeSlug = trim($attributeSlug);
        if ($characterId <= 0 || $attributeSlug === '') {
            return 0.0;
        }

        return AttributeProviderRegistry::getAttributeModifier($characterId, $attributeSlug);
    }

    /**
     * @param array<string, mixed> $rollResult
     */
    private function logRollRow(
        int $conflictId,
        int $actorId,
        string $rollType,
        string $dieUsed,
        array $rollResult,
        ?float $margin,
    ): void {
        $metaJson = json_encode([
            'manual_modifier' => $rollResult['manual_modifier'] ?? 0,
            'temporary_bonus' => $rollResult['temporary_bonus'] ?? 0,
            'attribute_modifier' => $rollResult['attribute_modifier'] ?? 0,
        ], JSON_UNESCAPED_UNICODE);
        if ($metaJson === false) {
            $metaJson = null;
        }

        $this->execPrepared(
            'INSERT INTO conflict_roll_log
                (conflict_id, actor_id, roll_type, die_used, base_roll, modifiers, final_result, critical_flag, margin, meta_json, `timestamp`)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())',
            [
                $conflictId,
                $actorId,
                $rollType,
                $dieUsed,
                (int) ($rollResult['base_roll'] ?? 0),
                (float) ($rollResult['modifiers'] ?? 0),
                (float) ($rollResult['final_result'] ?? 0),
                (string) ($rollResult['critical_flag'] ?? 'none'),
                $margin,
                $metaJson,
            ],
        );
    }
}
