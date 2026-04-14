<?php

declare(strict_types=1);

use App\Services\MessageReportService;
use Core\Http\ApiResponse;
use Core\Http\AppError;
use Core\Http\InputValidator;
use Core\Http\RequestData;
use Core\Http\ResponseEmitter;
use Core\Logging\LegacyLoggerAdapter;

use Core\Logging\LoggerInterface;

class MessageReports
{
    /** @var LoggerInterface|null */
    private $logger = null;
    /** @var MessageReportService|null */
    private $service = null;

    public function setLogger(LoggerInterface $logger = null)
    {
        $this->logger = $logger;
        return $this;
    }

    private function logger(): LoggerInterface
    {
        if ($this->logger instanceof LoggerInterface) {
            return $this->logger;
        }
        $this->logger = new LegacyLoggerAdapter();
        return $this->logger;
    }

    protected function trace($message, $context = false): void
    {
        $this->logger()->trace($message, $context);
    }

    private function service(): MessageReportService
    {
        if ($this->service instanceof MessageReportService) {
            return $this->service;
        }
        $this->service = new MessageReportService();
        return $this->service;
    }

    private function requestDataObject()
    {
        $request = RequestData::fromGlobals();
        return InputValidator::postJsonObject($request, 'data', true);
    }

    private function emitJson(array $payload): void
    {
        ResponseEmitter::emit(ApiResponse::json($payload));
    }

    private function requireAuth(): array
    {
        $guard = \Core\AuthGuard::api();
        $userId = (int) $guard->requireUser();
        $characterId = (int) $guard->requireCharacter();
        return ['user_id' => $userId, 'character_id' => $characterId];
    }

    private function requireStaff(): void
    {
        if (!\Core\AppContext::authContext()->isStaff()) {
            throw AppError::unauthorized('Accesso negato');
        }
    }

    // ── Game: crea segnalazione ───────────────────────────────────────────

    public function create(): void
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);

        $actor = $this->requireAuth();
        $data = $this->requestDataObject();

        $messageId = isset($data->message_id) ? (int) $data->message_id : 0;
        $reasonCode = isset($data->reason_code) ? trim((string) $data->reason_code) : '';
        $reasonText = isset($data->reason_text) ? trim((string) $data->reason_text) : '';

        if ($messageId <= 0) {
            throw AppError::validation('message_id obbligatorio', [], 'message_not_found');
        }
        if ($reasonCode === '') {
            throw AppError::validation('reason_code obbligatorio', [], 'message_report_invalid_reason');
        }

        $result = $this->service()->createReport(
            $actor['user_id'],
            $actor['character_id'],
            $messageId,
            $reasonCode,
            $reasonText,
        );

        $this->emitJson(['status' => 'ok', 'dataset' => $result]);
    }

    // ── Admin: lista ──────────────────────────────────────────────────────

    public function adminList(): void
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireStaff();

        $data = $this->requestDataObject();

        $filters = [
            'status' => isset($data->status) ? (string) $data->status : '',
            'priority' => isset($data->priority) ? (string) $data->priority : '',
            'location_id' => isset($data->location_id) ? (int) $data->location_id : 0,
            'reported_character_id' => isset($data->reported_character_id) ? (int) $data->reported_character_id : 0,
            'reporter_character_id' => isset($data->reporter_character_id) ? (int) $data->reporter_character_id : 0,
        ];
        $limit = isset($data->results_page) ? (int) $data->results_page : 25;
        $page = isset($data->page) ? (int) $data->page : 1;
        $orderBy = isset($data->orderBy) ? (string) $data->orderBy : 'created_at|DESC';

        $result = $this->service()->adminList($filters, $limit, $page, $orderBy);

        $this->emitJson([
            'dataset' => $result['rows'],
            'properties' => [
                'query' => '',
                'page' => $result['page'],
                'results_page' => $result['limit'],
                'orderBy' => $orderBy,
                'tot' => $result['total'],
            ],
        ]);
    }

    // ── Admin: dettaglio ──────────────────────────────────────────────────

    public function adminGet(): void
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireStaff();

        $data = $this->requestDataObject();
        $reportId = isset($data->report_id) ? (int) $data->report_id : 0;
        if ($reportId <= 0) {
            throw AppError::validation('report_id obbligatorio', [], 'missing_id');
        }

        $report = $this->service()->adminGet($reportId);
        $this->emitJson(['dataset' => $report]);
    }

    // ── Admin: aggiorna stato ─────────────────────────────────────────────

    public function adminUpdateStatus(): void
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireStaff();

        $data = $this->requestDataObject();
        $reportId = isset($data->report_id) ? (int) $data->report_id : 0;
        $status = isset($data->status) ? trim((string) $data->status) : '';
        $reviewNote = isset($data->review_note) ? trim((string) $data->review_note) : '';
        $resolutionCode = isset($data->resolution_code) ? trim((string) $data->resolution_code) : '';

        if ($reportId <= 0 || $status === '') {
            throw AppError::validation('report_id e status sono obbligatori', [], 'missing_params');
        }

        $reviewerUserId = (int) \Core\AuthGuard::api()->requireUser();
        $report = $this->service()->adminUpdateStatus($reportId, $status, $reviewerUserId, $reviewNote, $resolutionCode);
        $this->emitJson(['status' => 'ok', 'dataset' => $report]);
    }

    // ── Admin: assegna ────────────────────────────────────────────────────

    public function adminAssign(): void
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireStaff();

        $data = $this->requestDataObject();
        $reportId = isset($data->report_id) ? (int) $data->report_id : 0;
        $assignedToUserId = isset($data->assigned_to_user_id) ? (int) $data->assigned_to_user_id : (int) \Core\AuthGuard::api()->requireUser();

        if ($reportId <= 0) {
            throw AppError::validation('report_id obbligatorio', [], 'missing_id');
        }

        $report = $this->service()->adminAssign($reportId, $assignedToUserId);
        $this->emitJson(['status' => 'ok', 'dataset' => $report]);
    }
}
