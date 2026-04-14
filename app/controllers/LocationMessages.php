<?php

declare(strict_types=1);

use App\Services\ChatCommandService;
use App\Services\ConflictService;
use App\Services\ConflictSettingsService;
use App\Services\CurrencyService;
use App\Services\LocationDropService;
use App\Services\LocationMessageService;
use App\Services\NarrativeCapabilityService;
use App\Services\NarrativeNpcService;
use Core\Filter;
use Core\Hooks;
use Core\Http\ApiResponse;
use Core\Http\AppError;
use Core\Http\InputValidator;
use Core\Http\RequestData;
use Core\Http\ResponseEmitter;
use Core\Logging\LegacyLoggerAdapter;
use Core\Logging\LoggerInterface;
use Core\RateLimiter;

class LocationMessages
{
    public const TYPE_CHAT = 1;
    public const TYPE_SYSTEM = 3;
    public const TYPE_WHISPER = 4;
    public const MAX_CHAT_LENGTH = 2000;
    public const MAX_WHISPER_LENGTH = 1000;
    private $commandService = null;
    private $conflictService = null;
    private $conflictSettingsService = null;
    /** @var LocationMessageService|null */
    private $locationMessageService = null;
    /** @var LocationDropService|null */
    private $locationDropService = null;
    /** @var CurrencyService|null */
    private $currencyService = null;
    /** @var NarrativeCapabilityService|null */
    private $narrativeCapabilityService = null;
    /** @var NarrativeNpcService|null */
    private $narrativeNpcService = null;
    /** @var LoggerInterface|null */
    private $logger = null;

    public function setLogger(LoggerInterface $logger = null)
    {
        $this->logger = $logger;
        return $this;
    }

    public function setLocationMessageService(LocationMessageService $locationMessageService = null)
    {
        $this->locationMessageService = $locationMessageService;
        return $this;
    }

    public function setConflictService(ConflictService $conflictService = null)
    {
        $this->conflictService = $conflictService;
        return $this;
    }

    public function setConflictSettingsService(ConflictSettingsService $conflictSettingsService = null)
    {
        $this->conflictSettingsService = $conflictSettingsService;
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

    private function locationMessageService(): LocationMessageService
    {
        if ($this->locationMessageService instanceof LocationMessageService) {
            return $this->locationMessageService;
        }

        $this->locationMessageService = new LocationMessageService();
        return $this->locationMessageService;
    }

    private function failValidation($message, string $errorCode = 'validation_error')
    {
        throw AppError::validation((string) $message, [], $errorCode);
    }

    private function failUnauthorized($message = 'Operazione non autorizzata', string $errorCode = 'unauthorized')
    {
        throw AppError::unauthorized((string) $message, [], $errorCode);
    }

    private function failLocationInvalid(): void
    {
        $this->failValidation('Location non valida', 'location_invalid');
    }

    private function failLocationAccessDenied(): void
    {
        $this->failUnauthorized('Accesso non consentito', 'location_access_denied');
    }

    private function failCommandInvalid(): void
    {
        $this->failValidation('Comando non valido', 'command_invalid');
    }

    private function failDiceFormatInvalid(): void
    {
        $this->failValidation('Formato dado non valido', 'dice_format_invalid');
    }

    private function failCommandArgumentInvalid(): void
    {
        $this->failValidation('Inserisci un valore valido', 'command_argument_invalid');
    }

    private function failWhisperInvalid(): void
    {
        $this->failValidation('Sussurro non valido', 'whisper_invalid');
    }

    private function failCharacterNotFound(): void
    {
        $this->failValidation('Personaggio non trovato', 'character_not_found');
    }

    private function failRecipientInvalid(): void
    {
        $this->failValidation('Destinatario non valido', 'recipient_invalid');
    }

    private function failRecipientSelfNotAllowed(): void
    {
        $this->failValidation('Non puoi sussurrare a te stesso', 'recipient_self_not_allowed');
    }

    private function failRecipientNotInLocation(): void
    {
        $this->failValidation('Il destinatario non e presente in questa location', 'recipient_not_in_location');
    }

    private function failWhisperPolicyInvalid(): void
    {
        $this->failValidation('Policy sussurro non valida', 'whisper_policy_invalid');
    }

    private function failWhisperBlocked(): void
    {
        $this->failValidation('Sussurro bloccato dalle policy utente', 'whisper_blocked');
    }

    private function failItemNotFound(): void
    {
        $this->failValidation('Oggetto non trovato nel tuo inventario', 'item_not_found');
    }

    private function failItemNotDroppable(): void
    {
        $this->failValidation('Questo oggetto non puo essere lasciato a terra', 'item_not_droppable');
    }

    private function failInsufficientFunds(): void
    {
        $this->failValidation('Monete insufficienti in tasca', 'insufficient_funds');
    }

    private function failGiveSelfNotAllowed(): void
    {
        $this->failValidation('Non puoi dare monete a te stesso', 'recipient_self_not_allowed');
    }

    private function failLocationChatRateLimited(int $retryAfter): void
    {
        $this->failValidation(
            'Stai inviando messaggi troppo velocemente. Riprova tra ' . (int) $retryAfter . ' secondi',
            'location_chat_rate_limited',
        );
    }

    private function failLocationWhisperRateLimited(int $retryAfter): void
    {
        $this->failValidation(
            'Stai inviando sussurri troppo velocemente. Riprova tra ' . (int) $retryAfter . ' secondi',
            'location_whisper_rate_limited',
        );
    }

    private function normalizeLocationId($value): int
    {
        $locationId = (int) $value;
        if ($locationId <= 0) {
            $this->failLocationInvalid();
        }
        return $locationId;
    }

    private function normalizeRecipientId($value): int
    {
        $recipientId = (int) $value;
        if ($recipientId <= 0) {
            $this->failRecipientInvalid();
        }
        return $recipientId;
    }

    private function normalizeWhisperPolicy($value): string
    {
        $policy = $this->locationMessageService()->normalizeWhisperPolicy($value);
        if ($policy === '') {
            $this->failWhisperPolicyInvalid();
        }
        return $policy;
    }

    private function requestDataObject()
    {
        $request = RequestData::fromGlobals();
        return InputValidator::postJsonObject($request, 'data', true);
    }

    private function locationChatHistoryHours(): int
    {
        return $this->locationMessageService()->locationChatHistoryHours();
    }

    private function normalizeMessageText(
        $value,
        int $maxLength,
        string $emptyMessage,
        string $tooLongMessage,
        string $emptyCode = 'validation_error',
        string $tooLongCode = 'validation_error',
    ): string {
        return $this->locationMessageService()->normalizeMessageText(
            $value,
            $maxLength,
            $emptyMessage,
            $tooLongMessage,
            $emptyCode,
            $tooLongCode,
        );
    }

    private function requireCharacter()
    {
        return \Core\AuthGuard::api()->requireCharacter();
    }

    private function enforceWritePermission()
    {
        $userId = \Core\AuthGuard::api()->requireUser();
        \Core\AuthGuard::enforceNotRestricted($userId, 'Il tuo account e ristretto: non puoi usare la chat');
    }

    private function commandService()
    {
        if ($this->commandService === null) {
            $this->commandService = new ChatCommandService();
        }
        return $this->commandService;
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

    private function locationDropService(): LocationDropService
    {
        if ($this->locationDropService instanceof LocationDropService) {
            return $this->locationDropService;
        }

        $this->locationDropService = new LocationDropService();
        return $this->locationDropService;
    }

    private function currencyService(): CurrencyService
    {
        if ($this->currencyService instanceof CurrencyService) {
            return $this->currencyService;
        }

        $this->currencyService = new CurrencyService();
        return $this->currencyService;
    }

    private function narrativeCapabilityService(): NarrativeCapabilityService
    {
        if ($this->narrativeCapabilityService instanceof NarrativeCapabilityService) {
            return $this->narrativeCapabilityService;
        }

        $this->narrativeCapabilityService = new NarrativeCapabilityService();
        return $this->narrativeCapabilityService;
    }

    private function narrativeNpcService(): NarrativeNpcService
    {
        if ($this->narrativeNpcService instanceof NarrativeNpcService) {
            return $this->narrativeNpcService;
        }

        $this->narrativeNpcService = new NarrativeNpcService();
        return $this->narrativeNpcService;
    }

    private function enforceLocationChatRateLimit(int $characterId, int $locationId): void
    {
        $rule = RateLimiter::getRule('location_chat', 12, 15, 1, 100, 1, 300);
        $rate = RateLimiter::hit(
            'location.chat.send',
            $rule['limit'],
            $rule['window'],
            'character:' . $characterId . ':location:' . $locationId,
        );
        if (!empty($rate['allowed'])) {
            return;
        }

        $this->failLocationChatRateLimited((int) ($rate['retry_after'] ?? 0));
    }

    private function enforceLocationWhisperRateLimit(int $characterId, int $locationId): void
    {
        $rule = RateLimiter::getRule('location_whisper', 6, 20, 1, 60, 1, 300);
        $rate = RateLimiter::hit(
            'location.chat.whisper',
            $rule['limit'],
            $rule['window'],
            'character:' . $characterId . ':location:' . $locationId,
        );
        if (!empty($rate['allowed'])) {
            return;
        }

        $this->failLocationWhisperRateLimited((int) ($rate['retry_after'] ?? 0));
    }

    private function ensureAccess($location_id, $character_id = null)
    {
        if ($character_id === null) {
            $character_id = $this->requireCharacter();
        }
        $access = (new Locations())->canAccess($location_id, $character_id);
        if (empty($access['allowed'])) {
            $this->failLocationAccessDenied();
        }
        return $access;
    }

    private function normalizeTag($tag)
    {
        return $this->locationMessageService()->normalizeTag($tag);
    }

    private function runWhisperRetentionCleanup(int $locationId): void
    {
        if ($locationId <= 0) {
            return;
        }

        try {
            $this->locationMessageService()->purgeExpiredWhispers(
                $locationId,
                self::TYPE_WHISPER,
            );
        } catch (\Throwable $error) {
            // Cleanup best-effort: never block whisper/chat runtime.
            $this->trace('Whisper cleanup skipped: ' . $error->getMessage());
        }
    }

    private function buildMessageResponse($row)
    {
        return $this->locationMessageService()->buildMessageResponse($row);
    }

    private function emitJson(array $payload): void
    {
        ResponseEmitter::emit(ApiResponse::json($payload));
    }

    public function list($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $character_id = $this->requireCharacter();

        $post = $this->requestDataObject();
        $location_id = $this->normalizeLocationId(InputValidator::integer($post, 'location_id', 0));
        $this->ensureAccess($location_id, $character_id);

        $since_id = InputValidator::integer($post, 'since_id', 0);
        $limit = InputValidator::integer($post, 'limit', 50);
        if ($limit < 1 || $limit > 200) {
            $limit = 50;
        }
        $historyHours = $this->locationChatHistoryHours();

        $rows = $this->locationMessageService()->listLocationMessages(
            $location_id,
            $since_id,
            $limit,
            $historyHours,
            self::TYPE_WHISPER,
        );

        $dataset = [];
        $last_id = $since_id;
        if (!empty($rows)) {
            foreach ($rows as $row) {
                $row = $this->buildMessageResponse($row);
                $dataset[] = $row;
                if ($row->id > $last_id) {
                    $last_id = $row->id;
                }
            }
        }

        $response = [
            'dataset' => $dataset,
            'last_id' => $last_id,
        ];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function send($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $character_id = $this->requireCharacter();
        $this->enforceWritePermission();

        $post = $this->requestDataObject();
        $location_id = $this->normalizeLocationId(InputValidator::integer($post, 'location_id', 0));
        $raw = InputValidator::string($post, 'body', '');
        $raw = $this->normalizeMessageText(
            $raw,
            self::MAX_CHAT_LENGTH,
            'Messaggio vuoto',
            'Messaggio troppo lungo',
            'message_empty',
            'message_too_long',
        );
        $this->ensureAccess($location_id, $character_id);
        $this->enforceLocationChatRateLimit((int) $character_id, (int) $location_id);

        // '#' è alias di /fato
        if (strlen($raw) > 0 && $raw[0] === '#') {
            $raw = '/fato ' . ltrim(substr($raw, 1));
        }

        if (strpos($raw, '/') === 0) {
            return $this->handleCommand($raw, $location_id, $character_id, $echo);
        }

        $tagValue = property_exists($post, 'tag_position') ? $post->tag_position : null;
        $tag = $this->normalizeTag($tagValue);
        $meta = json_encode(['raw' => $raw], JSON_UNESCAPED_UNICODE);
        $row = $this->locationMessageService()->insertMessage(
            $location_id,
            $character_id,
            self::TYPE_CHAT,
            $raw,
            $meta,
            null,
            $tag,
        );
        $row = $this->buildMessageResponse($row);

        $response = [
            'dataset' => $row,
            'channel' => 'chat',
        ];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    private function handleCommand($raw, $location_id, $character_id, $echo = true)
    {
        $parsed = $this->commandService()->parse($raw);
        if (empty($parsed['is_command'])) {
            $this->failCommandInvalid();
        }
        $command = $parsed['command'];
        $args = $parsed['args'];
        $kind = $this->commandService()->resolveKind($command);

        switch ($kind) {
            case 'dice':
                return $this->sendDice($location_id, $args, $character_id, $echo);
            case 'skill':
                return $this->sendSkillCommand($location_id, $args, $character_id, $echo);
            case 'oggetto':
                return $this->sendSystemLabel($location_id, 'Oggetto', $args, $character_id, $echo);
            case 'conflict':
                return $this->sendConflictCommand($location_id, $args, $character_id, $echo);
            case 'whisper':
                return $this->sendWhisperFromCommand($location_id, $args, $character_id, $echo);
            case 'fato':
                return $this->sendFatoCommand($location_id, $args, $character_id, $echo);
            case 'png':
                return $this->sendPngCommand($location_id, $args, $character_id, $echo);
            case 'lascia':
                return $this->sendLasciaCommand($location_id, $args, $character_id, $echo);
            case 'dai':
                return $this->sendDaiCommand($location_id, $args, $character_id, $echo);
            default:
                $this->failCommandInvalid();
        }
    }

    private function parseConflictArgs(string $args): array
    {
        $raw = trim((string) $args);
        if ($raw === '') {
            $this->failCommandArgumentInvalid();
        }

        $targetId = 0;
        $summary = $raw;

        if (preg_match('/^[@#](\d+)\\s+(.+)$/', $raw, $match)) {
            $targetId = (int) ($match[1] ?? 0);
            $summary = trim((string) ($match[2] ?? ''));
        } elseif (preg_match('/^(.+?)\\s+[@#](\\d+)$/', $raw, $match)) {
            $summary = trim((string) ($match[1] ?? ''));
            $targetId = (int) ($match[2] ?? 0);
        } elseif (preg_match('/^[@#](\\d+)$/', $raw, $match)) {
            $targetId = (int) ($match[1] ?? 0);
            $summary = 'Proposta conflitto';
        }

        if ($summary === '') {
            $summary = 'Proposta conflitto';
        }

        return [
            'target_id' => $targetId,
            'summary' => $summary,
        ];
    }

    private function sendDice($location_id, $args, $character_id, $echo = true)
    {
        $result = $this->commandService()->rollDice($args);
        if (empty($result)) {
            $this->failDiceFormatInvalid();
        }
        $formattedShort = $result['formatted_short'] ?? $this->commandService()->formatDiceResult($result, [
            'include_expression' => false,
            'include_rolls' => true,
            'include_total' => true,
        ]);
        $body = '<div class="text-center"><p class="mb-1"><b>Dado</b> ' . Filter::html($result['expression']) . '</p><p class="lead mb-0"><b>Risultato: <em>' . Filter::html($formattedShort) . '</em></b></p></div>';
        $meta = json_encode([
            'command' => 'dice',
            'expr' => $result['expression'],
            'count' => $result['count'],
            'sides' => $result['sides'],
            'rolls' => $result['rolls'],
            'modifiers' => $result['modifiers'],
            'subtotal' => $result['subtotal'] ?? null,
            'modifier_total' => $result['modifier_total'] ?? null,
            'formatted' => $result['formatted'] ?? null,
            'formatted_short' => $formattedShort,
            'total' => $result['total'],
        ], JSON_UNESCAPED_UNICODE);

        return $this->insertSystemMessage($location_id, $body, $meta, $character_id, $echo);
    }

    private function sendSystemLabel($location_id, $label, $args, $character_id, $echo = true)
    {
        $name = trim($args);
        if ($name === '') {
            $this->failCommandArgumentInvalid();
        }
        $body = '<div class="text-center"><p class="mb-1"><b>' . Filter::html($label) . '</b></p><p class="mb-0">' . Filter::html($name) . '</p></div>';
        $meta = json_encode([
            'command' => strtolower($label),
            'label' => $name,
        ], JSON_UNESCAPED_UNICODE);

        return $this->insertSystemMessage($location_id, $body, $meta, $character_id, $echo);
    }

    private function sendConflictCommand($location_id, $args, $character_id, $echo = true)
    {
        $parsed = $this->parseConflictArgs((string) $args);
        $targetId = (int) ($parsed['target_id'] ?? 0);
        $summary = trim((string) ($parsed['summary'] ?? ''));
        if ($summary === '') {
            $summary = 'Proposta conflitto';
        }

        $result = $this->conflictService()->proposeConflict([
            'location_id' => (int) $location_id,
            'target_id' => $targetId,
            'summary' => $summary,
            'conflict_origin' => 'chat',
        ], (int) $character_id, \Core\AppContext::authContext()->isStaff());

        $conflict = is_array($result) ? ($result['conflict'] ?? null) : null;
        $conflictId = (int) ($conflict->id ?? 0);
        $status = strtolower(trim((string) ($conflict->status ?? 'proposal')));
        if ($status === '') {
            $status = 'proposal';
        }

        $statusLabelMap = [
            'proposal' => 'Proposta',
            'open' => 'Aperto',
            'active' => 'Attivo',
            'awaiting_resolution' => 'In attesa',
            'resolved' => 'Risolto',
            'closed' => 'Chiuso',
        ];
        $statusLabel = $statusLabelMap[$status] ?? ucfirst($status);

        $settings = $this->conflictSettingsService()->getSettings();
        $compactEvents = ((int) ($settings['conflict_chat_compact_events'] ?? 1) === 1);

        $header = 'Conflitto';
        if ($conflictId > 0) {
            $header .= ' #' . (int) $conflictId;
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

        $meta = json_encode([
            'event_type' => 'conflict_proposal_created',
            'command' => 'conflict',
            'conflict_id' => $conflictId,
            'status' => $status,
            'location_id' => (int) $location_id,
            'target_id' => $targetId > 0 ? $targetId : null,
            'summary' => $summary,
            'compact' => $compactEvents ? 1 : 0,
        ], JSON_UNESCAPED_UNICODE);

        return $this->insertSystemMessage($location_id, $body, $meta, $character_id, $echo);
    }

    private function sendSkillCommand($location_id, $args, $character_id, $echo = true)
    {
        // Delegate to any module that handles the /skill command.
        // Modules register via:
        //   Hooks::add('chat.command.skill', function($result, int $locationId, string $args, int $characterId): array {
        //       return ['body' => '...', 'meta' => '...'];
        //   });
        $hookResult = Hooks::filter('chat.command.skill', null, (int) $location_id, (string) $args, (int) $character_id);

        if (is_array($hookResult) && isset($hookResult['body'])) {
            return $this->insertSystemMessage(
                $location_id,
                (string) $hookResult['body'],
                isset($hookResult['meta']) ? (string) $hookResult['meta'] : null,
                $character_id,
                $echo,
            );
        }

        $meta = json_encode(['command' => 'skill', 'status' => 'unavailable'], JSON_UNESCAPED_UNICODE);
        $body = '<div class="text-center"><p class="mb-1"><b>Skill</b></p><p class="mb-0">Funzionalita non attiva.</p></div>';
        return $this->insertSystemMessage($location_id, $body, $meta, $character_id, $echo);
    }

    private function sendFatoCommand($location_id, $args, $character_id, $echo = true)
    {
        $isStaff = \Core\AppContext::authContext()->isStaff();

        if (!$isStaff) {
            // Delega narrativa: controlla se il personaggio ha la capability narrative.message.emit
            if (!$this->narrativeCapabilityService()->canActor((int) $character_id, 'narrative.message.emit')) {
                throw AppError::unauthorized('Comando riservato allo staff', [], 'command_forbidden');
            }
        }

        $text = trim((string) $args);
        if ($text === '') {
            $this->failCommandArgumentInvalid();
        }

        $bodyText = $this->boldCharacterNames($text, (int) $location_id);
        $time = date('H:i');

        $body = '<div class="fato-message">'
            . '<p class="mb-1 fst-italic">' . $bodyText . '</p>'
            . '<small class="text-muted">' . Filter::html($time) . '</small>'
            . '</div>';

        $meta = json_encode([
            'command' => 'fato',
            'raw' => $text,
            'delegated' => !$isStaff,
        ], JSON_UNESCAPED_UNICODE);

        if (!$isStaff) {
            \Core\AuditLogService::writeEvent(
                'narrative.message.emit',
                ['character_id' => (int) $character_id, 'location_id' => (int) $location_id, 'text' => $text],
                'game',
            );
        }

        return $this->insertSystemMessage($location_id, $body, $meta, $character_id, $echo);
    }

    private function sendPngCommand($location_id, $args, $character_id, $echo = true)
    {
        $isStaff = \Core\AppContext::authContext()->isStaff();

        if (!$isStaff) {
            if (!$this->narrativeCapabilityService()->canActor((int) $character_id, 'narrative.npc.spawn')) {
                throw AppError::unauthorized('Non hai i permessi per usare i PNG narrativi', [], 'command_forbidden');
            }
        }

        $parsed = $this->commandService()->parsePngArgs((string) $args);
        if ($parsed === null || trim($parsed['npc_name']) === '' || trim($parsed['body']) === '') {
            throw AppError::validation(
                'Formato non valido. Usa: /png @NomePNG messaggio',
                [],
                'command_invalid_args',
            );
        }

        $npc = $this->narrativeNpcService()->getByName($parsed['npc_name']);

        $npcName = Filter::html((string) ($npc['name'] ?? $parsed['npc_name']));
        $npcImage = !empty($npc['image']) ? (string) $npc['image'] : '';
        $bodyText = Filter::html(trim($parsed['body']));
        $time = date('H:i');

        $avatarHtml = $npcImage !== ''
            ? '<img src="' . Filter::html($npcImage) . '" alt="" class="rounded me-2" style="width:192px;height:192px;object-fit:cover;flex-shrink:0;">'
            : '<span class="rounded bg-secondary me-2 d-inline-flex align-items-center justify-content-center" style="width:36px;height:36px;flex-shrink:0;"><i class="bi bi-person-fill text-light"></i></span>';

        $body = '<div class="png-message d-flex align-items-start gap-2">'
            . $avatarHtml
            . '<div>'
            . '<div class="fw-semibold small mb-1">' . $npcName . ' <small class="text-muted fw-normal">[PNG]</small></div>'
            . '<p class="mb-1 fst-italic">' . $bodyText . '</p>'
            . '<small class="text-muted">' . Filter::html($time) . '</small>'
            . '</div>'
            . '</div>';

        $meta = json_encode([
            'command' => 'png',
            'npc_id' => (int) ($npc['id'] ?? 0),
            'npc_name' => $npc['name'] ?? $parsed['npc_name'],
            'delegated' => !$isStaff,
        ], JSON_UNESCAPED_UNICODE);

        if (!$isStaff) {
            \Core\AuditLogService::writeEvent(
                'narrative.npc.spawn',
                [
                    'character_id' => (int) $character_id,
                    'location_id' => (int) $location_id,
                    'npc_id' => (int) ($npc['id'] ?? 0),
                    'npc_name' => $npc['name'] ?? $parsed['npc_name'],
                ],
                'game',
            );
        }

        return $this->insertSystemMessage($location_id, $body, $meta, $character_id, $echo);
    }

    private function boldCharacterNames(string $text, int $locationId): string
    {
        $escaped = Filter::html($text);

        $rows = $this->locationMessageService()->getCharacterNamesInLocation($locationId);
        if (empty($rows)) {
            return $escaped;
        }

        $names = [];
        foreach ($rows as $row) {
            $name = trim((string) ($row->name ?? ''));
            $surname = trim((string) ($row->surname ?? ''));
            if ($name !== '' && $surname !== '') {
                $names[] = Filter::html($name . ' ' . $surname);
            }
            if ($name !== '') {
                $names[] = Filter::html($name);
            }
        }

        $names = array_unique($names);
        if (empty($names)) {
            return $escaped;
        }

        // Ordina dal nome più lungo al più corto per evitare match parziali
        usort($names, function ($a, $b) {
            return mb_strlen($b) - mb_strlen($a);
        });

        $alts = array_map(function ($n) {
            return preg_quote($n, '/');
        }, $names);
        $pattern = '/\b(' . implode('|', $alts) . ')\b/iu';

        return preg_replace($pattern, '<b>$1</b>', $escaped);
    }

    private function sendLasciaCommand($location_id, $args, $character_id, $echo = true)
    {
        $parsed = $this->commandService()->parseLasciaArgs((string) $args);
        if (empty($parsed)) {
            $this->failCommandArgumentInvalid();
        }

        $itemName = trim((string) ($parsed['item_name'] ?? ''));
        $quantity = max(1, (int) ($parsed['quantity'] ?? 1));

        if ($itemName === '') {
            $this->failCommandArgumentInvalid();
        }

        $found = $this->locationDropService()->findCharacterItemByName((int) $character_id, $itemName);
        if (empty($found)) {
            $this->failItemNotFound();
        }

        $type = $found['type'];
        $row = $found['row'];

        if ((int) ($row->droppable ?? 1) !== 1) {
            $this->failItemNotDroppable();
        }

        if ($type === 'stack') {
            $availableQty = (int) ($row->quantity ?? 1);
            $dropQty = min($quantity, $availableQty);
            $this->locationDropService()->dropCharacterItemStack((int) $location_id, (int) $character_id, $row, $dropQty);
            $qtyLabel = $dropQty > 1 ? ' (x' . $dropQty . ')' : '';
        } else {
            // instance (equippable, non-stacked) — always drop 1
            $this->locationDropService()->dropCharacterItemInstance((int) $location_id, (int) $character_id, $row);
            $qtyLabel = '';
        }

        $body = '<div class="text-center">'
            . '<p class="mb-1"><b>Oggetto a terra</b></p>'
            . '<p class="mb-0">' . Filter::html($itemName) . Filter::html($qtyLabel) . ' lasciato a terra.</p>'
            . '</div>';

        $meta = json_encode([
            'command' => 'lascia',
            'item_name' => $itemName,
            'quantity' => isset($dropQty) ? $dropQty : 1,
            'location_id' => (int) $location_id,
        ], JSON_UNESCAPED_UNICODE);

        return $this->insertSystemMessage($location_id, $body, $meta, $character_id, $echo);
    }

    private function sendDaiCommand($location_id, $args, $character_id, $echo = true)
    {
        $parsed = $this->commandService()->parseGiveCurrencyArgs((string) $args);
        if (empty($parsed)) {
            $this->failCommandArgumentInvalid();
        }

        $amount = (int) ($parsed['amount'] ?? 0);
        if ($amount <= 0) {
            $this->failCommandArgumentInvalid();
        }

        // Resolve target character
        $targetLabel = '';
        if (!empty($parsed['target_id'])) {
            $targetId = (int) $parsed['target_id'];
            $targetLabel = 'PG #' . $targetId;
        } else {
            $targetName = trim((string) ($parsed['target'] ?? ''));
            if ($targetName === '') {
                $this->failCommandArgumentInvalid();
            }
            $targetRows = $this->locationMessageService()->findCharactersByName($targetName, (int) $location_id);
            if (empty($targetRows) || count($targetRows) !== 1) {
                $this->failCharacterNotFound();
            }
            $targetId = (int) $targetRows[0]->id;
            $targetLabel = trim(($targetRows[0]->name ?? '') . ' ' . ($targetRows[0]->surname ?? ''));
        }

        if ($targetId <= 0) {
            $this->failCharacterNotFound();
        }

        if ($targetId === (int) $character_id) {
            $this->failGiveSelfNotAllowed();
        }

        $currency = $this->currencyService()->getDefaultCurrency();
        if (empty($currency)) {
            $this->failValidation('Valuta non disponibile', 'currency_not_found');
        }
        $currencyId = (int) $currency->id;
        $symbol = trim((string) ($currency->symbol ?? 'monete'));

        $debit = $this->currencyService()->debit(
            (int) $character_id,
            $currencyId,
            $amount,
            'chat_give',
            json_encode(['target_id' => $targetId, 'location_id' => (int) $location_id], JSON_UNESCAPED_UNICODE),
        );

        if (empty($debit['ok'])) {
            if (($debit['error'] ?? '') === 'insufficient_funds') {
                $this->failInsufficientFunds();
            }
            $this->failValidation('Trasferimento non riuscito', 'currency_transfer_failed');
        }

        $credit = $this->currencyService()->credit(
            $targetId,
            $currencyId,
            $amount,
            'chat_give',
            json_encode(['from_id' => (int) $character_id, 'location_id' => (int) $location_id], JSON_UNESCAPED_UNICODE),
        );

        if (empty($credit['ok'])) {
            // Refund sender if credit failed
            $this->currencyService()->credit(
                (int) $character_id,
                $currencyId,
                $amount,
                'chat_give_refund',
                json_encode(['refund' => true, 'target_id' => $targetId], JSON_UNESCAPED_UNICODE),
            );
            $this->failValidation('Trasferimento non riuscito', 'currency_transfer_failed');
        }

        $body = '<div class="text-center">'
            . '<p class="mb-1"><b>Scambio monete</b></p>'
            . '<p class="mb-0">' . Filter::html($amount . ' ' . $symbol) . ' dati a <b>' . Filter::html($targetLabel) . '</b>.</p>'
            . '</div>';

        $meta = json_encode([
            'command' => 'dai',
            'amount' => $amount,
            'currency_id' => $currencyId,
            'target_id' => $targetId,
            'location_id' => (int) $location_id,
        ], JSON_UNESCAPED_UNICODE);

        return $this->insertSystemMessage($location_id, $body, $meta, $character_id, $echo);
    }

    private function insertSystemMessage($location_id, $body, $meta, $character_id, $echo = true)
    {
        $row = $this->locationMessageService()->insertMessage(
            $location_id,
            $character_id,
            self::TYPE_SYSTEM,
            $body,
            $meta,
        );
        $row->body_rendered = $row->body;

        $response = [
            'dataset' => $row,
            'channel' => 'chat',
        ];
        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    private function sendWhisperFromCommand($location_id, $args, $character_id, $echo = true)
    {
        $parsed = $this->commandService()->parseWhisperArgs($args);
        if (empty($parsed)) {
            $this->failWhisperInvalid();
        }

        $message = $this->normalizeMessageText(
            $parsed['body'] ?? '',
            self::MAX_WHISPER_LENGTH,
            'Sussurro non valido',
            'Sussurro troppo lungo',
            'whisper_invalid',
            'whisper_too_long',
        );
        if ($message === '') {
            $this->failWhisperInvalid();
        }

        // Target by numeric ID (@12)
        if (!empty($parsed['target_id'])) {
            $targetId = (int) $parsed['target_id'];
        } else {
            $targetName = trim((string) ($parsed['target'] ?? ''));
            if ($targetName === '') {
                $this->failWhisperInvalid();
            }
            $targetRow = $this->locationMessageService()->findCharactersByName($targetName, (int) $location_id);
            if (empty($targetRow) || count($targetRow) !== 1) {
                $this->failCharacterNotFound();
            }
            $targetId = (int) $targetRow[0]->id;
        }

        return $this->sendWhisperInternal($location_id, $targetId, $message, $character_id, $echo);
    }

    private function sendWhisperInternal($location_id, $recipient_id, $message, $character_id, $echo = true)
    {
        if ($recipient_id <= 0) {
            $this->failRecipientInvalid();
        }
        if ($recipient_id == $character_id) {
            $this->failRecipientSelfNotAllowed();
        }
        $message = $this->normalizeMessageText(
            $message,
            self::MAX_WHISPER_LENGTH,
            'Sussurro vuoto',
            'Sussurro troppo lungo',
            'whisper_empty',
            'whisper_too_long',
        );

        $recipient = $this->locationMessageService()->findCharacterById($recipient_id);
        if (empty($recipient)) {
            $this->failRecipientInvalid();
        }

        if (!\Core\AppContext::authContext()->isStaff() && (int) ($recipient->last_location ?? 0) !== (int) $location_id) {
            $this->failRecipientNotInLocation();
        }
        if ($this->locationMessageService()->isWhisperBlocked((int) $character_id, (int) $recipient_id)) {
            $this->failWhisperBlocked();
        }
        $this->enforceLocationWhisperRateLimit((int) $character_id, (int) $location_id);
        $this->runWhisperRetentionCleanup((int) $location_id);

        $meta = json_encode(['raw' => $message], JSON_UNESCAPED_UNICODE);
        $row = $this->locationMessageService()->insertMessage(
            $location_id,
            $character_id,
            self::TYPE_WHISPER,
            $message,
            $meta,
            $recipient_id,
        );
        $row = $this->buildMessageResponse($row);
        $row->recipient_id = $recipient_id;

        $response = [
            'dataset' => $row,
            'channel' => 'whisper',
        ];
        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function whisperPolicy($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $me = $this->requireCharacter();

        $post = $this->requestDataObject();
        $location_id = $this->normalizeLocationId(InputValidator::integer($post, 'location_id', 0));
        $recipient_id = $this->normalizeRecipientId(InputValidator::integer($post, 'recipient_id', 0));
        $policy = $this->normalizeWhisperPolicy(InputValidator::string($post, 'policy', 'allow'));
        $this->ensureAccess($location_id, $me);

        if ($recipient_id === (int) $me) {
            $this->failRecipientSelfNotAllowed();
        }

        $recipient = $this->locationMessageService()->findCharacterById($recipient_id);
        if (empty($recipient)) {
            $this->failRecipientInvalid();
        }
        if (!\Core\AppContext::authContext()->isStaff() && (int) ($recipient->last_location ?? 0) !== (int) $location_id) {
            $this->failRecipientNotInLocation();
        }

        $result = $this->locationMessageService()->setWhisperPolicy(
            (int) $me,
            (int) $recipient_id,
            $policy,
        );

        if ($policy !== 'allow') {
            $this->locationMessageService()->markWhisperThreadRead(
                $location_id,
                $me,
                $recipient_id,
                self::TYPE_WHISPER,
            );
        }

        $totalUnread = $this->locationMessageService()->countWhisperUnread(
            $location_id,
            $me,
            self::TYPE_WHISPER,
        );

        $response = [
            'dataset' => [
                'character_id' => (int) ($result['character_id'] ?? $me),
                'recipient_id' => (int) ($result['target_character_id'] ?? $recipient_id),
                'policy' => (string) ($result['policy'] ?? 'allow'),
                'total_unread' => (int) $totalUnread,
            ],
        ];
        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function whispers($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $me = $this->requireCharacter();

        $post = $this->requestDataObject();
        $location_id = $this->normalizeLocationId(InputValidator::integer($post, 'location_id', 0));
        $recipient_id = $this->normalizeRecipientId(InputValidator::integer($post, 'recipient_id', 0));
        $this->ensureAccess($location_id, $me);
        $this->runWhisperRetentionCleanup((int) $location_id);
        $this->locationMessageService()->markWhisperThreadRead(
            $location_id,
            $me,
            $recipient_id,
            self::TYPE_WHISPER,
        );

        $rows = $this->locationMessageService()->listWhisperThread(
            $location_id,
            $me,
            $recipient_id,
            self::TYPE_WHISPER,
            100,
        );

        $dataset = [];
        if (!empty($rows)) {
            foreach ($rows as $row) {
                $dataset[] = $this->buildMessageResponse($row);
            }
        }

        $totalUnread = $this->locationMessageService()->countWhisperUnread(
            $location_id,
            $me,
            self::TYPE_WHISPER,
        );
        $threadUnread = $this->locationMessageService()->countWhisperUnread(
            $location_id,
            $me,
            self::TYPE_WHISPER,
            $recipient_id,
        );

        $response = [
            'dataset' => $dataset,
            'unread' => [
                'total_unread' => (int) $totalUnread,
                'thread_unread' => (int) $threadUnread,
            ],
        ];
        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function whispersThreads($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $me = $this->requireCharacter();

        $post = $this->requestDataObject();
        $location_id = $this->normalizeLocationId(InputValidator::integer($post, 'location_id', 0));
        $this->ensureAccess($location_id, $me);

        $limit = InputValidator::integer($post, 'limit', 200);
        if ($limit < 1 || $limit > 300) {
            $limit = 200;
        }

        $rows = $this->locationMessageService()->listWhisperThreads(
            $location_id,
            $me,
            self::TYPE_WHISPER,
            $limit,
        );

        $dataset = [];
        if (!empty($rows)) {
            foreach ($rows as $row) {
                if ((empty($row->last_message_body) || !is_string($row->last_message_body)) && !empty($row->last_meta_json)) {
                    $meta = json_decode($row->last_meta_json);
                    if (!empty($meta) && isset($meta->raw) && is_string($meta->raw)) {
                        $row->last_message_body = (string) $meta->raw;
                    }
                }

                $row->recipient_id = (int) ($row->recipient_id ?? 0);
                $row->unread_count = (int) ($row->unread_count ?? 0);
                $row->policy = $this->locationMessageService()->normalizeWhisperPolicy($row->policy ?? 'allow');
                if ($row->policy === '') {
                    $row->policy = 'allow';
                }
                $dataset[] = $row;
            }
        }

        $totalUnread = $this->locationMessageService()->countWhisperUnread(
            $location_id,
            $me,
            self::TYPE_WHISPER,
        );

        $response = [
            'dataset' => $dataset,
            'unread' => [
                'total_unread' => (int) $totalUnread,
            ],
        ];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function whispersUnread($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $me = $this->requireCharacter();

        $post = $this->requestDataObject();
        $location_id = $this->normalizeLocationId(InputValidator::integer($post, 'location_id', 0));
        $this->ensureAccess($location_id, $me);

        $recipient_id = InputValidator::integer($post, 'recipient_id', 0);
        if ($recipient_id <= 0) {
            $recipient_id = null;
        }

        $totalUnread = $this->locationMessageService()->countWhisperUnread(
            $location_id,
            $me,
            self::TYPE_WHISPER,
        );

        $threadUnread = 0;
        if ($recipient_id !== null) {
            $threadUnread = $this->locationMessageService()->countWhisperUnread(
                $location_id,
                $me,
                self::TYPE_WHISPER,
                $recipient_id,
            );
        }

        $response = [
            'dataset' => [
                'total_unread' => (int) $totalUnread,
                'thread_unread' => (int) $threadUnread,
                'recipient_id' => ($recipient_id !== null ? (int) $recipient_id : null),
            ],
        ];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function whisper($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $character_id = $this->requireCharacter();
        $this->enforceWritePermission();

        $post = $this->requestDataObject();
        $location_id = $this->normalizeLocationId(InputValidator::integer($post, 'location_id', 0));
        $recipient_id = $this->normalizeRecipientId(InputValidator::integer($post, 'recipient_id', 0));
        $message = InputValidator::string($post, 'body', '');
        $message = $this->normalizeMessageText(
            $message,
            self::MAX_WHISPER_LENGTH,
            'Sussurro vuoto',
            'Sussurro troppo lungo',
            'whisper_empty',
            'whisper_too_long',
        );
        $this->ensureAccess($location_id, $character_id);

        return $this->sendWhisperInternal($location_id, $recipient_id, $message, $character_id, $echo);
    }
}
