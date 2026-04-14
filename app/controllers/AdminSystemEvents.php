<?php

declare(strict_types=1);

use App\Services\SystemEventService;
use Core\AuthGuard;
use Core\Http\ApiResponse;
use Core\Http\InputValidator;
use Core\Http\RequestData;
use Core\Http\ResponseEmitter;

class AdminSystemEvents
{
    /** @var SystemEventService|null */
    private $service = null;

    public function setService(SystemEventService $service = null)
    {
        $this->service = $service;
        return $this;
    }

    private function service(): SystemEventService
    {
        if ($this->service instanceof SystemEventService) {
            return $this->service;
        }
        $this->service = new SystemEventService();
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

    private function requireAdmin(): int
    {
        AuthGuard::api()->requireAbility('settings.manage');
        return (int) AuthGuard::api()->requireCharacter();
    }

    public function list($echo = true)
    {
        $this->requireAdmin();
        $data = $this->requestDataObject();
        $query = (isset($data->query) && is_object($data->query)) ? $data->query : (object) [];

        $filters = [
            'status' => trim((string) ($query->status ?? $data->status ?? '')),
            'type' => trim((string) ($query->type ?? $data->type ?? '')),
            'visibility' => trim((string) ($query->visibility ?? $data->visibility ?? '')),
            'scope_type' => trim((string) ($query->scope_type ?? $data->scope_type ?? '')),
            'participant_mode' => trim((string) ($query->participant_mode ?? $data->participant_mode ?? '')),
            'search' => trim((string) ($query->search ?? $data->search ?? '')),
            'tag_ids' => $query->tag_ids ?? ($data->tag_ids ?? ($query->tag_id ?? ($data->tag_id ?? []))),
        ];

        $limit = max(1, min(200, (int) ($data->results ?? $data->limit ?? 20)));
        $page = max(1, (int) ($data->page ?? 1));
        $orderBy = trim((string) ($data->orderBy ?? $data->sort ?? 'id|DESC'));

        $dataset = $this->service()->listForAdmin($filters, $limit, $page, $orderBy);

        $response = [
            'properties' => [
                'query' => [
                    'status' => $filters['status'],
                    'type' => $filters['type'],
                    'visibility' => $filters['visibility'],
                    'scope_type' => $filters['scope_type'],
                    'participant_mode' => $filters['participant_mode'],
                    'search' => $filters['search'],
                    'tag_ids' => $filters['tag_ids'],
                ],
                'page' => (int) ($dataset['page'] ?? $page),
                'results_page' => (int) ($dataset['limit'] ?? $limit),
                'orderBy' => $orderBy,
                'tot' => [
                    'count' => (int) ($dataset['total'] ?? 0),
                ],
            ],
            'dataset' => isset($dataset['rows']) && is_array($dataset['rows']) ? $dataset['rows'] : [],
        ];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function get($echo = true)
    {
        $this->requireAdmin();
        $data = $this->requestDataObject();
        $eventId = (int) ($data->event_id ?? $data->id ?? 0);

        $event = $this->service()->getById($eventId);
        $event['effects'] = $this->service()->listEffects($eventId);
        $event['participations'] = $this->service()->listParticipations($eventId);

        $response = ['dataset' => $event];
        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function create($echo = true)
    {
        $actorCharacterId = $this->requireAdmin();
        $data = $this->requestDataObject();
        $dataset = $this->service()->create((array) $data, $actorCharacterId);
        $response = ['dataset' => $dataset];
        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function update($echo = true)
    {
        $actorCharacterId = $this->requireAdmin();
        $data = $this->requestDataObject();
        $eventId = (int) ($data->event_id ?? $data->id ?? 0);
        $dataset = $this->service()->update($eventId, (array) $data, $actorCharacterId);
        $response = ['dataset' => $dataset];
        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function delete($echo = true)
    {
        $this->requireAdmin();
        $data = $this->requestDataObject();
        $eventId = (int) ($data->event_id ?? $data->id ?? 0);
        $dataset = $this->service()->delete($eventId);
        $response = ['dataset' => $dataset];
        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function statusSet($echo = true)
    {
        $actorCharacterId = $this->requireAdmin();
        $data = $this->requestDataObject();
        $eventId = (int) ($data->event_id ?? $data->id ?? 0);
        $status = trim((string) ($data->status ?? ''));
        $dataset = $this->service()->setStatus($eventId, $status, $actorCharacterId, false);
        $response = ['dataset' => $dataset];
        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function effectsList($echo = true)
    {
        $this->requireAdmin();
        $data = $this->requestDataObject();
        $eventId = (int) ($data->event_id ?? $data->id ?? 0);
        $dataset = $this->service()->listEffects($eventId);
        $response = ['dataset' => $dataset];
        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function effectsUpsert($echo = true)
    {
        $actorCharacterId = $this->requireAdmin();
        $data = $this->requestDataObject();
        $eventId = (int) ($data->event_id ?? $data->id ?? 0);
        $dataset = $this->service()->upsertEffect($eventId, (array) $data, $actorCharacterId);
        $response = ['dataset' => $dataset];
        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function effectsDelete($echo = true)
    {
        $this->requireAdmin();
        $data = $this->requestDataObject();
        $eventId = (int) ($data->event_id ?? 0);
        $effectId = (int) ($data->effect_id ?? $data->id ?? 0);
        $dataset = $this->service()->deleteEffect($eventId, $effectId);
        $response = ['dataset' => $dataset];
        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function participationsList($echo = true)
    {
        $this->requireAdmin();
        $data = $this->requestDataObject();
        $eventId = (int) ($data->event_id ?? $data->id ?? 0);
        $dataset = $this->service()->listParticipations($eventId);
        $response = ['dataset' => $dataset];
        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function participationsUpsert($echo = true)
    {
        $actorCharacterId = $this->requireAdmin();
        $data = $this->requestDataObject();
        $eventId = (int) ($data->event_id ?? $data->id ?? 0);
        $dataset = $this->service()->adminUpsertParticipation($eventId, (array) $data, $actorCharacterId);
        $response = ['dataset' => $dataset];
        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function participationsRemove($echo = true)
    {
        $this->requireAdmin();
        $data = $this->requestDataObject();
        $eventId = (int) ($data->event_id ?? $data->id ?? 0);
        $dataset = $this->service()->adminRemoveParticipation($eventId, (array) $data);
        $response = ['dataset' => $dataset];
        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function rewardsAssign($echo = true)
    {
        $actorCharacterId = $this->requireAdmin();
        $data = $this->requestDataObject();
        $eventId = (int) ($data->event_id ?? $data->id ?? 0);
        $dataset = $this->service()->assignReward($eventId, (array) $data, $actorCharacterId);
        $response = ['dataset' => $dataset];
        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function rewardsLog($echo = true)
    {
        $this->requireAdmin();
        $data = $this->requestDataObject();
        $eventId = (int) ($data->event_id ?? $data->id ?? 0);
        $limit = max(1, min(200, (int) ($data->limit ?? 50)));
        $page = max(1, (int) ($data->page ?? 1));
        $dataset = $this->service()->rewardLog($eventId, $limit, $page);
        $response = ['dataset' => $dataset];
        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function maintenanceRun($echo = true)
    {
        $this->requireAdmin();
        $data = $this->requestDataObject();
        $force = (int) ($data->force ?? 1) === 1;
        $dataset = $this->service()->maintenanceRun($force);
        $response = ['dataset' => $dataset];
        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }
}
