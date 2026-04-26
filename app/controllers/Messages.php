<?php

declare(strict_types=1);

use App\Services\MessagesService;
use App\Services\NotificationService;
use Core\Http\ApiResponse;
use Core\Http\AppError;
use Core\Http\InputValidator;
use Core\Http\RequestData;
use Core\Http\ResponseEmitter;

use Core\Logging\LoggerInterface;
use Core\RateLimiter;

class Messages
{
    public const MAX_BODY_LENGTH = 2000;
    public const MAX_SUBJECT_LENGTH = 120;
    /** @var LoggerInterface|null */
    private $logger = null;
    /** @var MessagesService|null */
    private $messagesService = null;

    public function setLogger(LoggerInterface $logger = null)
    {
        $this->logger = $logger;
        return $this;
    }

    public function setMessagesService(MessagesService $messagesService = null)
    {
        $this->messagesService = $messagesService;
        return $this;
    }

    private function logger(): LoggerInterface
    {
        if ($this->logger instanceof LoggerInterface) {
            return $this->logger;
        }

        $this->logger = \Core\AppContext::logger();
        return $this->logger;
    }

    protected function trace($message, $context = false): void
    {
        $this->logger()->trace($message, $context);
    }

    private function messagesService(): MessagesService
    {
        if ($this->messagesService instanceof MessagesService) {
            return $this->messagesService;
        }

        $this->messagesService = new MessagesService();
        return $this->messagesService;
    }

    private function failValidation($message, string $errorCode = 'validation_error')
    {
        throw AppError::validation((string) $message, [], $errorCode);
    }

    private function failDmRateLimited(int $retryAfter): void
    {
        $this->failValidation(
            'Stai inviando troppi messaggi privati. Riprova tra ' . (int) $retryAfter . ' secondi',
            'dm_rate_limited',
        );
    }

    private function failThreadInvalid(): void
    {
        $this->failValidation('Thread non valido', 'thread_invalid');
    }

    private function failThreadForbidden(): void
    {
        $this->failValidation('Thread non autorizzato', 'thread_forbidden');
    }

    private function failMessageTypeInvalid(): void
    {
        $this->failValidation('Tipo non valido', 'message_type_invalid');
    }

    private function failCharacterInvalid(): void
    {
        $this->failValidation('Personaggio non valido', 'character_invalid');
    }

    private function enforceWritePermission()
    {
        $userId = \Core\AuthGuard::api()->requireUser();
        \Core\AuthGuard::enforceNotRestricted($userId, 'Il tuo account e ristretto: non puoi inviare messaggi privati');
    }

    private function requestDataObject()
    {
        $request = RequestData::fromGlobals();
        return InputValidator::postJsonObject($request, 'data', true);
    }

    private function enforceDmRateLimit(int $characterId): void
    {
        $rule = RateLimiter::getRule('dm_send', 8, 30, 1, 60, 1, 600);
        $rate = RateLimiter::hit('dm.send', $rule['limit'], $rule['window'], 'character:' . (int) $characterId);
        if (!empty($rate['allowed'])) {
            return;
        }
        $this->failDmRateLimited((int) $rate['retry_after']);
    }

    private function normalizeMessageType($value): string
    {
        $type = trim((string) $value);
        if ($type === '') {
            $type = 'on';
        }
        if ($type !== 'on' && $type !== 'off') {
            $this->failMessageTypeInvalid();
        }

        return $type;
    }

    private function emitJson(array $payload): void
    {
        ResponseEmitter::emit(ApiResponse::json($payload));
    }

    public function threads()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $me = \Core\AuthGuard::api()->requireCharacter();
        \Core\AuthGuard::releaseSession();
        $data = $this->requestDataObject();
        $search = InputValidator::string($data, 'search', '');
        $dataset = $this->messagesService()->listThreads((int) $me, $search);

        $this->emitJson([
            'dataset' => $dataset,
        ]);
    }

    public function unread()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $me = \Core\AuthGuard::api()->requireCharacter();
        \Core\AuthGuard::releaseSession();
        $unread = $this->messagesService()->countUnread((int) $me);

        $this->emitJson([
            'unread' => $unread,
        ]);
    }

    public function thread()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $me = \Core\AuthGuard::api()->requireCharacter();
        \Core\AuthGuard::releaseSession();
        $data = $this->requestDataObject();

        $thread_id = InputValidator::integer($data, 'thread_id', 0);
        if ($thread_id <= 0) {
            $this->failThreadInvalid();
        }

        $limit = InputValidator::integer($data, 'limit', 30);
        if ($limit < 1) {
            $limit = 30;
        } elseif ($limit > 100) {
            $limit = 100;
        }
        $beforeIdValue = InputValidator::integer($data, 'before_id', 0);
        $before_id = $beforeIdValue > 0 ? $beforeIdValue : null;

        $thread = $this->messagesService()->loadThreadForCharacter($thread_id, (int) $me);
        if (empty($thread)) {
            $this->failThreadInvalid();
        }

        $other_id = $this->messagesService()->resolveOtherParticipantId($thread, (int) $me);
        if ($other_id <= 0) {
            $this->failThreadInvalid();
        }

        $messages = $this->messagesService()->listThreadMessages((int) $thread->id, $limit + 1, $before_id);

        $has_more = false;
        $next_before_id = null;
        if (count($messages) > $limit) {
            $has_more = true;
            array_pop($messages);
        }
        if (count($messages) > 0) {
            $min_id = null;
            foreach ($messages as $msg) {
                if ($min_id === null || $msg->id < $min_id) {
                    $min_id = $msg->id;
                }
            }
            $next_before_id = $min_id;
            $messages = array_reverse($messages);
        }

        $this->messagesService()->markThreadRead((int) $thread->id, (int) $me);
        $other = $this->messagesService()->getCharacterSummary((int) $other_id);

        $this->emitJson([
            'thread' => [
                'id' => $thread->id,
                'subject' => $thread->subject,
            ],
            'other' => $other,
            'messages' => $messages,
            'paging' => [
                'has_more' => $has_more,
                'next_before_id' => $next_before_id,
            ],
        ]);
    }

    public function deleteThread()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $me = \Core\AuthGuard::api()->requireCharacter();
        \Core\AuthGuard::releaseSession();
        $data = $this->requestDataObject();

        $thread_id = InputValidator::integer($data, 'thread_id', 0);
        if ($thread_id <= 0) {
            $this->failThreadInvalid();
        }

        $thread = $this->messagesService()->loadThreadForCharacter($thread_id, (int) $me);
        if (empty($thread)) {
            $this->failThreadInvalid();
        }

        $this->messagesService()->deleteThreadForCharacter($thread_id, (int) $me);

        $this->emitJson(['ok' => true]);
    }

    public function send()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);

        $me = \Core\AuthGuard::api()->requireCharacter();
        $this->enforceWritePermission();
        \Core\AuthGuard::releaseSession();
        $data = $this->requestDataObject();

        $bodyRaw = property_exists($data, 'body') ? (string) $data->body : '';
        $body = $this->messagesService()->normalizeBody($bodyRaw, self::MAX_BODY_LENGTH);
        $this->enforceDmRateLimit((int) $me);

        $threadIdValue = InputValidator::integer($data, 'thread_id', 0);
        $thread_id = $threadIdValue > 0 ? $threadIdValue : null;
        $otherIdValue = InputValidator::integer($data, 'character_id', 0);
        $other_id = $otherIdValue > 0 ? $otherIdValue : null;

        $subject = InputValidator::string($data, 'subject', '');
        $message_type = $this->normalizeMessageType(InputValidator::string($data, 'message_type', 'on'));

        $thread = null;
        if (!empty($thread_id)) {
            $thread = $this->messagesService()->loadThreadById((int) $thread_id);
            if (empty($thread)) {
                $this->failThreadInvalid();
            }

            if (!$this->messagesService()->isThreadParticipant($thread, (int) $me)) {
                $this->failThreadForbidden();
            }

            $other_id = $this->messagesService()->resolveOtherParticipantId($thread, (int) $me);
        } else {
            if (empty($other_id) || $other_id === (int) $me) {
                $this->failCharacterInvalid();
            }
            $subject = $this->messagesService()->normalizeSubject($subject, self::MAX_SUBJECT_LENGTH);
        }

        $dmPolicy = $this->messagesService()->getDmPolicyForCharacter((int) $other_id);
        \Core\AuthGuard::ensureDmAllowed((int) $me, (int) $other_id, $dmPolicy);

        $messageId = 0;
        $this->messagesService()->beginTransaction();
        try {
            if (empty($thread_id)) {
                $thread_id = $this->messagesService()->createThread((int) $me, (int) $other_id, (string) $subject);
            }

            $messageId = $this->messagesService()->insertMessage((int) $thread_id, (int) $me, (int) $other_id, (string) $body, (string) $message_type);
            $this->messagesService()->updateThreadLastMessage((int) $thread_id, (string) $body, (int) $me, (string) $message_type);
            $this->messagesService()->commitTransaction();
        } catch (\Throwable $e) {
            $this->messagesService()->rollbackTransaction();
            throw $e;
        }

        $message = $this->messagesService()->fetchMessageById((int) $messageId);
        try {
            $sender = $this->messagesService()->getCharacterNotificationTarget((int) $me);
            $recipient = $this->messagesService()->getCharacterNotificationTarget((int) $other_id);
            if (!empty($sender) && !empty($recipient) && (int) ($recipient->user_id ?? 0) > 0) {
                $senderName = trim((string) ($sender->name ?? '') . ' ' . (string) ($sender->surname ?? ''));
                if ($senderName === '') {
                    $senderName = 'Qualcuno';
                }

                $excerpt = trim((string) $body);
                if ($excerpt !== '') {
                    if (function_exists('mb_substr')) {
                        $excerpt = mb_substr($excerpt, 0, 120);
                    } else {
                        $excerpt = substr($excerpt, 0, 120);
                    }
                }

                $notifService = new NotificationService();
                $notifService->mergeOrCreateSystemUpdate(
                    (int) $recipient->user_id,
                    (int) $other_id,
                    'direct_message:' . (int) $thread_id,
                    'Nuovo messaggio da ' . $senderName,
                    [
                        'topic' => 'direct_message',
                        'message' => $excerpt !== '' ? $excerpt : null,
                        'actor_user_id' => (int) ($sender->user_id ?? 0),
                        'actor_character_id' => (int) $me,
                        'source_type' => 'direct_message',
                        'source_id' => (int) $messageId,
                        'action_url' => '/game/messages',
                        'priority' => 'normal',
                    ],
                );
            }
        } catch (\Throwable $e) {
            // fire-and-forget: non bloccare l'invio del messaggio
        }

        $this->emitJson([
            'message' => $message,
            'thread_id' => $thread_id,
        ]);
    }
}


