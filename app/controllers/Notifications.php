<?php

declare(strict_types=1);

use App\Services\NotificationService;
use Core\AuthGuard;
use Core\Http\ApiResponse;
use Core\Http\AppError;
use Core\Http\InputValidator;
use Core\Http\RequestData;
use Core\Http\ResponseEmitter;

class Notifications
{
    /** @var NotificationService|null */
    private $service = null;

    public function setService(NotificationService $service = null)
    {
        $this->service = $service;
        return $this;
    }

    private function service(): NotificationService
    {
        if ($this->service instanceof NotificationService) {
            return $this->service;
        }
        $this->service = new NotificationService();
        return $this->service;
    }

    private function requestData()
    {
        $request = RequestData::fromGlobals();
        return InputValidator::postJsonObject($request, 'data', true);
    }

    private function emitJson(array $payload): void
    {
        ResponseEmitter::emit(ApiResponse::json($payload));
    }

    /**
     * @return array{user_id: int, character_id: int}
     */
    private function requireSession(): array
    {
        $guard = AuthGuard::api();
        $userId = (int) $guard->requireUser();
        $characterId = (int) \Core\SessionStore::get('character_id');
        return ['user_id' => $userId, 'character_id' => $characterId];
    }

    public function list()
    {
        $session = $this->requireSession();
        \Core\AuthGuard::releaseSession();
        $data = $this->requestData();

        $filters = [
            'page' => (int) ($data->page ?? 1),
            'results' => (int) ($data->results ?? 20),
            'unread_only' => (int) ($data->unread_only ?? 0),
            'kind' => isset($data->kind) ? (string) $data->kind : null,
            'pending_only' => (int) ($data->pending_only ?? 0),
        ];

        $result = $this->service()->listForRecipient(
            $session['user_id'],
            $session['character_id'] > 0 ? $session['character_id'] : null,
            $filters,
        );

        $this->emitJson(['success' => true, 'dataset' => $result]);
        return $this;
    }

    public function read()
    {
        $session = $this->requireSession();
        $data = $this->requestData();

        $notificationId = (int) ($data->notification_id ?? $data->id ?? 0);
        if ($notificationId <= 0) {
            throw AppError::validation('ID notifica non valido', [], 'notification_invalid');
        }

        $result = $this->service()->markRead($notificationId, $session['user_id']);
        $this->emitJson(['success' => true, 'dataset' => $result]);
        return $this;
    }

    public function readDelete()
    {
        $session = $this->requireSession();
        $data = $this->requestData();

        $notificationId = (int) ($data->notification_id ?? $data->id ?? 0);
        if ($notificationId <= 0) {
            throw AppError::validation('ID notifica non valido', [], 'notification_invalid');
        }

        $result = $this->service()->markReadAndDelete($notificationId, $session['user_id']);
        $this->emitJson(['success' => true, 'dataset' => $result]);
        return $this;
    }

    public function delete()
    {
        $session = $this->requireSession();
        $data = $this->requestData();

        $notificationId = (int) ($data->notification_id ?? $data->id ?? 0);
        if ($notificationId <= 0) {
            throw AppError::validation('ID notifica non valido', [], 'notification_invalid');
        }

        $result = $this->service()->delete($notificationId, $session['user_id']);
        $this->emitJson(['success' => true, 'dataset' => $result]);
        return $this;
    }

    public function readAll()
    {
        $session = $this->requireSession();
        $data = $this->requestData();

        $filters = [
            'kind' => isset($data->kind) ? (string) $data->kind : null,
            'pending_only' => (int) ($data->pending_only ?? 0),
        ];

        $result = $this->service()->markAllRead($session['user_id'], $filters);
        $this->emitJson(['success' => true, 'dataset' => $result]);
        return $this;
    }

    public function respond()
    {
        $session = $this->requireSession();
        $data = $this->requestData();

        $notificationId = (int) ($data->notification_id ?? $data->id ?? 0);
        $decision = isset($data->decision) ? (string) $data->decision : '';

        if ($notificationId <= 0) {
            throw AppError::validation('ID notifica non valido', [], 'notification_invalid');
        }
        if ($session['character_id'] <= 0) {
            throw AppError::validation('Personaggio non valido', [], 'character_invalid');
        }

        $result = $this->service()->respond(
            $notificationId,
            $session['user_id'],
            $session['character_id'],
            $decision,
        );

        $this->emitJson(['success' => true, 'dataset' => $result]);
        return $this;
    }

    public function unreadCount()
    {
        $session = $this->requireSession();
        \Core\AuthGuard::releaseSession();
        $count = $this->service()->getUnreadCount($session['user_id']);
        $this->emitJson(['success' => true, 'unread_count' => $count]);
        return $this;
    }
}
