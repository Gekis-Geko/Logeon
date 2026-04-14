<?php

declare(strict_types=1);

use App\Services\QuestAssignmentService;
use App\Services\QuestClosureService;
use App\Services\QuestDefinitionService;
use App\Services\QuestProgressService;
use App\Services\QuestRewardService;
use Core\AuthGuard;
use Core\Http\ApiResponse;
use Core\Http\AppError;
use Core\Http\InputValidator;
use Core\Http\RequestData;
use Core\Http\ResponseEmitter;

class AdminQuests
{
    /** @var QuestDefinitionService|null */
    private $definitionService = null;
    /** @var QuestAssignmentService|null */
    private $assignmentService = null;
    /** @var QuestProgressService|null */
    private $progressService = null;
    /** @var QuestClosureService|null */
    private $closureService = null;
    /** @var QuestRewardService|null */
    private $rewardService = null;

    private function definitionService(): QuestDefinitionService
    {
        if ($this->definitionService instanceof QuestDefinitionService) {
            return $this->definitionService;
        }
        $this->definitionService = new QuestDefinitionService();
        return $this->definitionService;
    }

    private function assignmentService(): QuestAssignmentService
    {
        if ($this->assignmentService instanceof QuestAssignmentService) {
            return $this->assignmentService;
        }
        $this->assignmentService = new QuestAssignmentService();
        return $this->assignmentService;
    }

    private function progressService(): QuestProgressService
    {
        if ($this->progressService instanceof QuestProgressService) {
            return $this->progressService;
        }
        $this->progressService = new QuestProgressService();
        return $this->progressService;
    }

    private function closureService(): QuestClosureService
    {
        if ($this->closureService instanceof QuestClosureService) {
            return $this->closureService;
        }
        $this->closureService = new QuestClosureService();
        return $this->closureService;
    }

    private function rewardService(): QuestRewardService
    {
        if ($this->rewardService instanceof QuestRewardService) {
            return $this->rewardService;
        }
        $this->rewardService = new QuestRewardService();
        return $this->rewardService;
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

    private function requireAdminCharacter(): int
    {
        $guard = AuthGuard::api();
        $guard->requireAbility('settings.manage');
        return (int) $guard->requireCharacter();
    }

    public function definitionsList($echo = true)
    {
        $this->requireAdminCharacter();
        $data = $this->requestDataObject();
        $query = (isset($data->query) && is_object($data->query)) ? $data->query : (object) [];

        $filters = [
            'status' => trim((string) ($query->status ?? $data->status ?? '')),
            'visibility' => trim((string) ($query->visibility ?? $data->visibility ?? '')),
            'scope_type' => trim((string) ($query->scope_type ?? $data->scope_type ?? '')),
            'search' => trim((string) ($query->search ?? $data->search ?? '')),
            'tag_ids' => $query->tag_ids ?? ($data->tag_ids ?? ($query->tag_id ?? ($data->tag_id ?? []))),
        ];

        $limit = max(1, min(200, (int) ($data->results ?? $data->limit ?? 20)));
        $page = max(1, (int) ($data->page ?? 1));
        $orderBy = trim((string) ($data->orderBy ?? $data->sort ?? 'sort_order|ASC'));

        $dataset = $this->definitionService()->listForAdmin($filters, $limit, $page, $orderBy);
        $response = [
            'properties' => [
                'query' => [
                    'status' => $filters['status'],
                    'visibility' => $filters['visibility'],
                    'scope_type' => $filters['scope_type'],
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

    public function definitionsCreate($echo = true)
    {
        $actorCharacterId = $this->requireAdminCharacter();
        $data = $this->requestDataObject();
        $dataset = $this->definitionService()->create((array) $data, $actorCharacterId);
        $response = ['dataset' => $dataset];
        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function definitionsUpdate($echo = true)
    {
        $actorCharacterId = $this->requireAdminCharacter();
        $data = $this->requestDataObject();
        $definitionId = (int) ($data->quest_definition_id ?? $data->id ?? 0);
        if ($definitionId <= 0) {
            throw AppError::validation('Quest non valida', [], 'quest_not_found');
        }
        $dataset = $this->definitionService()->update($definitionId, (array) $data, $actorCharacterId);
        $response = ['dataset' => $dataset];
        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function definitionsPublish($echo = true)
    {
        $actorCharacterId = $this->requireAdminCharacter();
        $data = $this->requestDataObject();
        $definitionId = (int) ($data->quest_definition_id ?? $data->id ?? 0);
        $dataset = $this->definitionService()->setStatus($definitionId, 'published', $actorCharacterId);
        $response = ['dataset' => $dataset];
        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function definitionsArchive($echo = true)
    {
        $actorCharacterId = $this->requireAdminCharacter();
        $data = $this->requestDataObject();
        $definitionId = (int) ($data->quest_definition_id ?? $data->id ?? 0);
        $dataset = $this->definitionService()->setStatus($definitionId, 'archived', $actorCharacterId);
        $response = ['dataset' => $dataset];
        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function definitionsDelete($echo = true)
    {
        $this->requireAdminCharacter();
        $data = $this->requestDataObject();
        $definitionId = (int) ($data->quest_definition_id ?? $data->id ?? 0);
        $dataset = $this->definitionService()->delete($definitionId);
        $response = ['dataset' => $dataset];
        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function definitionsReorder($echo = true)
    {
        $this->requireAdminCharacter();
        $data = $this->requestDataObject();
        $items = [];
        if (isset($data->items) && is_array($data->items)) {
            $items = $data->items;
        } elseif (is_array($data)) {
            $items = $data;
        }
        $dataset = $this->definitionService()->reorder($items);
        $response = ['dataset' => $dataset];
        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function stepsList($echo = true)
    {
        $this->requireAdminCharacter();
        $data = $this->requestDataObject();
        $definitionId = (int) ($data->quest_definition_id ?? $data->id ?? 0);
        $dataset = $this->definitionService()->listSteps($definitionId);
        $response = ['dataset' => $dataset];
        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function stepsUpsert($echo = true)
    {
        $this->requireAdminCharacter();
        $data = $this->requestDataObject();
        $definitionId = (int) ($data->quest_definition_id ?? 0);
        $dataset = $this->definitionService()->upsertStep($definitionId, (array) $data);
        $response = ['dataset' => $dataset];
        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function stepsDelete($echo = true)
    {
        $this->requireAdminCharacter();
        $data = $this->requestDataObject();
        $definitionId = (int) ($data->quest_definition_id ?? 0);
        $stepId = (int) ($data->step_id ?? $data->id ?? 0);
        $dataset = $this->definitionService()->deleteStep($definitionId, $stepId);
        $response = ['dataset' => $dataset];
        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function stepsReorder($echo = true)
    {
        $this->requireAdminCharacter();
        $data = $this->requestDataObject();
        $definitionId = (int) ($data->quest_definition_id ?? 0);
        $items = isset($data->items) && is_array($data->items) ? $data->items : [];
        $dataset = $this->definitionService()->reorderSteps($definitionId, $items);
        $response = ['dataset' => $dataset];
        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function conditionsList($echo = true)
    {
        $this->requireAdminCharacter();
        $data = $this->requestDataObject();
        $filters = [
            'quest_definition_id' => (int) ($data->quest_definition_id ?? 0),
            'quest_step_definition_id' => (int) ($data->quest_step_definition_id ?? 0),
            'condition_type' => trim((string) ($data->condition_type ?? '')),
        ];
        $dataset = $this->definitionService()->listConditions($filters);
        $response = ['dataset' => $dataset];
        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function conditionsUpsert($echo = true)
    {
        $this->requireAdminCharacter();
        $data = $this->requestDataObject();
        $dataset = $this->definitionService()->upsertCondition((array) $data);
        $response = ['dataset' => $dataset];
        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function conditionsDelete($echo = true)
    {
        $this->requireAdminCharacter();
        $data = $this->requestDataObject();
        $conditionId = (int) ($data->condition_id ?? $data->id ?? 0);
        $dataset = $this->definitionService()->deleteCondition($conditionId);
        $response = ['dataset' => $dataset];
        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function outcomesList($echo = true)
    {
        $this->requireAdminCharacter();
        $data = $this->requestDataObject();
        $definitionId = (int) ($data->quest_definition_id ?? $data->id ?? 0);
        $dataset = $this->definitionService()->listOutcomes($definitionId);
        $response = ['dataset' => $dataset];
        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function outcomesUpsert($echo = true)
    {
        $this->requireAdminCharacter();
        $data = $this->requestDataObject();
        $definitionId = (int) ($data->quest_definition_id ?? 0);
        $dataset = $this->definitionService()->upsertOutcome($definitionId, (array) $data);
        $response = ['dataset' => $dataset];
        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function outcomesDelete($echo = true)
    {
        $this->requireAdminCharacter();
        $data = $this->requestDataObject();
        $definitionId = (int) ($data->quest_definition_id ?? 0);
        $outcomeId = (int) ($data->outcome_id ?? $data->id ?? 0);
        $dataset = $this->definitionService()->deleteOutcome($definitionId, $outcomeId);
        $response = ['dataset' => $dataset];
        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function instancesList($echo = true)
    {
        $this->requireAdminCharacter();
        $data = $this->requestDataObject();
        $query = (isset($data->query) && is_object($data->query)) ? $data->query : (object) [];
        $filters = [
            'status' => trim((string) ($query->status ?? $data->status ?? '')),
            'quest_definition_id' => (int) ($query->quest_definition_id ?? $data->quest_definition_id ?? 0),
            'assignee_type' => trim((string) ($query->assignee_type ?? $data->assignee_type ?? '')),
            'assignee_id' => (int) ($query->assignee_id ?? $data->assignee_id ?? 0),
        ];
        $dataset = $this->assignmentService()->listInstancesForStaff(
            $filters,
            max(1, min(200, (int) ($data->results ?? $data->limit ?? 20))),
            max(1, (int) ($data->page ?? 1)),
            trim((string) ($data->orderBy ?? $data->sort ?? 'id|DESC')),
        );

        $response = [
            'properties' => [
                'query' => $filters,
                'page' => (int) ($dataset['page'] ?? 1),
                'results_page' => (int) ($dataset['limit'] ?? 20),
                'orderBy' => trim((string) ($data->orderBy ?? $data->sort ?? 'id|DESC')),
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

    public function instancesGet($echo = true)
    {
        $this->requireAdminCharacter();
        $data = $this->requestDataObject();
        $instanceId = (int) ($data->quest_instance_id ?? $data->instance_id ?? $data->id ?? 0);
        if ($instanceId <= 0) {
            throw AppError::validation('Istanza non valida', [], 'quest_not_found');
        }
        $dataset = (new \App\Services\QuestResolverService())->getInstanceDetail($instanceId);
        $response = ['dataset' => $dataset];
        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function instancesAssign($echo = true)
    {
        $actorCharacterId = $this->requireAdminCharacter();
        $data = $this->requestDataObject();
        $dataset = $this->assignmentService()->assign((array) $data, $actorCharacterId);
        $response = ['dataset' => $dataset];
        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function instancesStatusSet($echo = true)
    {
        $actorCharacterId = $this->requireAdminCharacter();
        $data = $this->requestDataObject();
        $instanceId = (int) ($data->quest_instance_id ?? $data->instance_id ?? 0);
        $status = trim((string) ($data->status ?? ''));
        $intensityLevel = null;
        if (isset($data->intensity_level)) {
            $intensityLevel = trim((string) $data->intensity_level);
        }
        $dataset = $this->progressService()->setInstanceStatus($instanceId, $status, $actorCharacterId, 'admin_status_set', $intensityLevel);
        $response = ['dataset' => $dataset];
        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function instancesStepSet($echo = true)
    {
        $actorCharacterId = $this->requireAdminCharacter();
        $data = $this->requestDataObject();
        $instanceId = (int) ($data->quest_instance_id ?? $data->instance_id ?? 0);
        $stepInstanceId = (int) ($data->step_instance_id ?? $data->id ?? 0);
        $status = trim((string) ($data->status ?? 'completed'));
        $dataset = $this->progressService()->setStepStatus($instanceId, $stepInstanceId, $status, $actorCharacterId, 'admin_step_set');
        $response = ['dataset' => $dataset];
        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function closuresList($echo = true)
    {
        $this->requireAdminCharacter();
        $data = $this->requestDataObject();
        $query = (isset($data->query) && is_object($data->query)) ? $data->query : (object) [];

        $filters = [
            'quest_definition_id' => (int) ($query->quest_definition_id ?? $data->quest_definition_id ?? 0),
            'quest_instance_id' => (int) ($query->quest_instance_id ?? $data->quest_instance_id ?? 0),
            'closure_type' => trim((string) ($query->closure_type ?? $data->closure_type ?? '')),
            'player_visible' => $query->player_visible ?? $data->player_visible ?? '',
            'search' => trim((string) ($query->search ?? $data->search ?? '')),
        ];

        $limit = max(1, min(200, (int) ($data->results ?? $data->limit ?? 20)));
        $page = max(1, (int) ($data->page ?? 1));
        $orderBy = trim((string) ($data->orderBy ?? $data->sort ?? 'closed_at|DESC'));

        $dataset = $this->closureService()->listForAdmin($filters, $limit, $page, $orderBy);
        $response = [
            'properties' => [
                'query' => $filters,
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

    public function closuresGet($echo = true)
    {
        $this->requireAdminCharacter();
        $data = $this->requestDataObject();
        $closureId = (int) ($data->closure_id ?? $data->id ?? 0);
        $instanceId = (int) ($data->quest_instance_id ?? $data->instance_id ?? 0);

        $dataset = $this->closureService()->getForAdmin($closureId, $instanceId);
        $response = ['dataset' => $dataset];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function closuresUpsert($echo = true)
    {
        $actorCharacterId = $this->requireAdminCharacter();
        $data = $this->requestDataObject();
        $instanceId = (int) ($data->quest_instance_id ?? $data->instance_id ?? 0);
        if ($instanceId <= 0) {
            throw AppError::validation('Istanza non valida', [], 'quest_closure_invalid');
        }

        $dataset = $this->closureService()->upsert($instanceId, (array) $data, $actorCharacterId);
        $response = ['dataset' => $dataset];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function rewardsList($echo = true)
    {
        $actorCharacterId = $this->requireAdminCharacter();
        $data = $this->requestDataObject();
        $instanceId = (int) ($data->quest_instance_id ?? $data->instance_id ?? 0);

        $dataset = $this->rewardService()->listByInstance(
            $instanceId,
            $actorCharacterId,
            true,
            max(1, min(200, (int) ($data->limit ?? $data->results ?? 50))),
            max(1, (int) ($data->page ?? 1)),
        );

        $response = ['dataset' => $dataset];
        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function rewardsAssign($echo = true)
    {
        $actorCharacterId = $this->requireAdminCharacter();
        $data = $this->requestDataObject();

        $dataset = $this->rewardService()->assign((array) $data, $actorCharacterId, true);
        $response = ['dataset' => $dataset];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function rewardsRemove($echo = true)
    {
        $this->requireAdminCharacter();
        $data = $this->requestDataObject();
        $rewardId = (int) ($data->reward_id ?? $data->id ?? 0);
        $instanceId = (int) ($data->quest_instance_id ?? $data->instance_id ?? 0);

        $dataset = $this->rewardService()->remove($rewardId, $instanceId);
        $response = ['dataset' => $dataset];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function linksList($echo = true)
    {
        $this->requireAdminCharacter();
        $data = $this->requestDataObject();
        $definitionId = (int) ($data->quest_definition_id ?? 0);
        $instanceId = (int) ($data->quest_instance_id ?? 0);
        $dataset = $this->definitionService()->listLinks($definitionId, $instanceId);
        $response = ['dataset' => $dataset];
        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function linksUpsert($echo = true)
    {
        $actorCharacterId = $this->requireAdminCharacter();
        $data = $this->requestDataObject();
        $dataset = $this->definitionService()->upsertLink((array) $data, $actorCharacterId);
        $response = ['dataset' => $dataset];
        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function linksDelete($echo = true)
    {
        $this->requireAdminCharacter();
        $data = $this->requestDataObject();
        $linkId = (int) ($data->link_id ?? $data->id ?? 0);
        $dataset = $this->definitionService()->deleteLink($linkId);
        $response = ['dataset' => $dataset];
        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function logsList($echo = true)
    {
        $this->requireAdminCharacter();
        $data = $this->requestDataObject();
        $filters = [
            'quest_instance_id' => (int) ($data->quest_instance_id ?? 0),
            'quest_definition_id' => (int) ($data->quest_definition_id ?? 0),
            'log_type' => trim((string) ($data->log_type ?? '')),
        ];
        $dataset = $this->progressService()->listLogs(
            $filters,
            max(1, min(200, (int) ($data->limit ?? 50))),
            max(1, (int) ($data->page ?? 1)),
        );
        $response = ['dataset' => $dataset];
        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function maintenanceRun($echo = true)
    {
        $this->requireAdminCharacter();
        $data = $this->requestDataObject();
        $dataset = $this->progressService()->maintenanceRun(((int) ($data->force ?? 1) === 1));
        $response = ['dataset' => $dataset];
        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }
}
