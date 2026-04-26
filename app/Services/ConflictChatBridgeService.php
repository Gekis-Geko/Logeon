<?php

declare(strict_types=1);

namespace App\Services;

use Core\Filter;

class ConflictChatBridgeService
{
    /** @var ConflictService|null */
    private $conflictService = null;

    /** @var ConflictSettingsService|null */
    private $conflictSettingsService = null;

    public function setConflictService(ConflictService $service = null)
    {
        $this->conflictService = $service;
        return $this;
    }

    public function setConflictSettingsService(ConflictSettingsService $service = null)
    {
        $this->conflictSettingsService = $service;
        return $this;
    }

    private function conflictService(): ConflictService
    {
        if ($this->conflictService instanceof ConflictService) {
            return $this->conflictService;
        }
        $this->conflictService = new ConflictService();
        return $this->conflictService;
    }

    private function conflictSettingsService(): ConflictSettingsService
    {
        if ($this->conflictSettingsService instanceof ConflictSettingsService) {
            return $this->conflictSettingsService;
        }
        $this->conflictSettingsService = new ConflictSettingsService();
        return $this->conflictSettingsService;
    }

    /**
     * Builds the chat system message payload for a /conflict command.
     *
     * @param int    $locationId
     * @param int    $targetId     0 = no specific target
     * @param string $summary
     * @param int    $characterId
     * @param bool   $isStaff
     * @return array{body: string, meta: string}
     */
    public function buildProposalMessage(
        int $locationId,
        int $targetId,
        string $summary,
        int $characterId,
        bool $isStaff
    ): array {
        $result = $this->conflictService()->proposeConflict([
            'location_id' => $locationId,
            'target_id'   => $targetId,
            'summary'     => $summary,
            'conflict_origin' => 'chat',
        ], $characterId, $isStaff);

        $conflict   = $result['conflict'] ?? null;
        $conflictId = (int) ($conflict->id ?? 0);
        $status     = strtolower(trim((string) ($conflict->status ?? 'proposal')));
        if ($status === '') {
            $status = 'proposal';
        }

        $statusLabelMap = [
            'proposal'           => 'Proposta',
            'open'               => 'Aperto',
            'active'             => 'Attivo',
            'awaiting_resolution' => 'In attesa',
            'resolved'           => 'Risolto',
            'closed'             => 'Chiuso',
        ];
        $statusLabel = $statusLabelMap[$status] ?? ucfirst($status);

        $settings      = $this->conflictSettingsService()->getSettings();
        $compactEvents = ((int) ($settings['conflict_chat_compact_events'] ?? 1) === 1);

        $header = 'Conflitto';
        if ($conflictId > 0) {
            $header .= ' #' . $conflictId;
        }

        $summaryPreview = trim($summary);
        $limit = 120;
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($summaryPreview) > $limit) {
                $summaryPreview = mb_substr($summaryPreview, 0, $limit - 1) . '...';
            }
        } elseif (strlen($summaryPreview) > $limit) {
            $summaryPreview = substr($summaryPreview, 0, $limit - 1) . '...';
        }

        if ($compactEvents) {
            $body = '<div class="text-center">'
                . '<p class="mb-1"><b>' . Filter::html($header) . '</b> '
                . '<span class="badge text-bg-warning">' . Filter::html($statusLabel) . '</span></p>'
                . ($summaryPreview !== '' ? ('<p class="small text-muted mb-0">' . Filter::html($summaryPreview) . '</p>') : '')
                . '</div>';
        } else {
            $body = '<div class="text-center">'
                . '<p class="mb-1"><b>' . Filter::html($header) . '</b> '
                . '<span class="badge text-bg-warning">' . Filter::html($statusLabel) . '</span></p>'
                . '<p class="mb-0">' . Filter::html($summary) . '</p>'
                . '</div>';
        }

        $meta = (string) json_encode([
            'event_type'  => 'conflict_proposal_created',
            'command'     => 'conflict',
            'conflict_id' => $conflictId,
            'status'      => $status,
            'location_id' => $locationId,
            'target_id'   => $targetId > 0 ? $targetId : null,
            'summary'     => $summary,
            'compact'     => $compactEvents ? 1 : 0,
        ], JSON_UNESCAPED_UNICODE);

        return ['body' => $body, 'meta' => $meta];
    }
}
