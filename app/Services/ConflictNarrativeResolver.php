<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\ConflictResolverInterface;
use Core\Database\DbAdapterFactory;
use Core\Database\DbAdapterInterface;
use Core\Http\AppError;

class ConflictNarrativeResolver implements ConflictResolverInterface
{
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

    public function mode(): string
    {
        return ConflictSettingsService::MODE_NARRATIVE;
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function resolveConflict(array $context): array
    {
        return [
            'mode' => $this->mode(),
            'outcome_summary' => trim((string) ($context['outcome_summary'] ?? '')),
            'verdict_text' => trim((string) ($context['verdict_text'] ?? '')),
            'resolution_authority' => trim((string) ($context['resolution_authority'] ?? '')),
            'structured' => false,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function performRoll(array $payload): array
    {
        throw AppError::validation(
            'La risoluzione random non e attiva in modalita narrativa',
            [],
            'conflict_roll_not_available',
        );
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
}
