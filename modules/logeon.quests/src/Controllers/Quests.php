<?php

declare(strict_types=1);

namespace Modules\Logeon\Quests\Controllers;

use App\Services\QuestAssignmentService;
use App\Services\QuestClosureService;
use App\Services\QuestHistoryResolverService;
use App\Services\QuestProgressService;
use Core\AuthGuard;
use Core\Http\ApiResponse;
use Core\Http\AppError;
use Core\Http\InputValidator;
use Core\Http\RequestData;
use Core\Http\ResponseEmitter;

class Quests
{
    /** @var QuestAssignmentService|null */
    private $assignmentService = null;
    /** @var QuestProgressService|null */
    private $progressService = null;
    /** @var QuestClosureService|null */
    private $closureService = null;
    /** @var QuestHistoryResolverService|null */
    private $historyService = null;

    public function setAssignmentService(QuestAssignmentService $service = null)
    {
        $this->assignmentService = $service;
        return $this;
    }

    public function setProgressService(QuestProgressService $service = null)
    {
        $this->progressService = $service;
        return $this;
    }

    public function setClosureService(QuestClosureService $service = null)
    {
        $this->closureService = $service;
        return $this;
    }

    public function setHistoryService(QuestHistoryResolverService $service = null)
    {
        $this->historyService = $service;
        return $this;
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

    private function historyService(): QuestHistoryResolverService
    {
        if ($this->historyService instanceof QuestHistoryResolverService) {
            return $this->historyService;
        }
        $this->historyService = new QuestHistoryResolverService();
        return $this->historyService;
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

    private function requireCharacter(): int
    {
        $guard = AuthGuard::api();
        $guard->requireUser();
        return (int) $guard->requireCharacter();
    }

    private function isStaff(): bool
    {
        return \Core\AppContext::authContext()->isStaff();
    }

    private function requireStaffCharacter(): int
    {
        $characterId = $this->requireCharacter();
        if (!$this->isStaff()) {
            throw AppError::unauthorized('Operazione riservata allo staff', [], 'quest_participation_forbidden');
        }
        return $characterId;
    }

    public function list($echo = true)
    {
        $characterId = $this->requireCharacter();
        $isStaff = $this->isStaff();
        \Core\AuthGuard::releaseSession();
        $data = $this->requestDataObject();
        $filters = [
            'status' => InputValidator::string($data, 'status', ''),
            'scope_type' => InputValidator::string($data, 'scope_type', ''),
            'tag_ids' => InputValidator::arrayOfValues(
                $data,
                'tag_ids',
                InputValidator::arrayOfValues($data, 'tag_id', []),
            ),
        ];
        $limit = max(1, min(100, InputValidator::integer($data, 'limit', 30)));
        $page = max(1, InputValidator::integer($data, 'page', 1));

        $viewerFactionIds = $this->assignmentService()->viewerFactionIds($characterId);
        $viewerGuildIds = $this->assignmentService()->viewerGuildIds($characterId);
        $dataset = $this->assignmentService()->listForGame(
            $filters,
            $characterId,
            $isStaff,
            $viewerFactionIds,
            $viewerGuildIds,
            $limit,
            $page,
        );

        $response = ['dataset' => $dataset];
        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function get($echo = true)
    {
        $characterId = $this->requireCharacter();
        $isStaff = $this->isStaff();
        $data = $this->requestDataObject();
        $definitionId = InputValidator::integer($data, 'quest_definition_id', 0);
        if ($definitionId <= 0) {
            $definitionId = InputValidator::integer($data, 'quest_id', 0);
        }
        if ($definitionId <= 0) {
            $definitionId = InputValidator::positiveInt($data, 'id', 'Quest non valida', 'quest_not_found');
        }

        $viewerFactionIds = $this->assignmentService()->viewerFactionIds($characterId);
        $viewerGuildIds = $this->assignmentService()->viewerGuildIds($characterId);
        $dataset = $this->assignmentService()->getForGame(
            $definitionId,
            $characterId,
            $isStaff,
            $viewerFactionIds,
            $viewerGuildIds,
        );

        $response = ['dataset' => $dataset];
        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function participationJoin($echo = true)
    {
        $characterId = $this->requireCharacter();
        $isStaff = $this->isStaff();
        $data = $this->requestDataObject();
        $definitionId = InputValidator::integer($data, 'quest_definition_id', 0);
        if ($definitionId <= 0) {
            $definitionId = InputValidator::integer($data, 'quest_id', 0);
        }
        if ($definitionId <= 0) {
            $definitionId = InputValidator::positiveInt($data, 'id', 'Quest non valida', 'quest_not_found');
        }

        $dataset = $this->assignmentService()->joinForGame($definitionId, (array) $data, $characterId, $isStaff);
        $response = ['dataset' => $dataset];
        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function participationLeave($echo = true)
    {
        $characterId = $this->requireCharacter();
        $isStaff = $this->isStaff();
        $data = $this->requestDataObject();
        $definitionId = InputValidator::integer($data, 'quest_definition_id', 0);
        if ($definitionId <= 0) {
            $definitionId = InputValidator::integer($data, 'quest_id', 0);
        }
        if ($definitionId <= 0) {
            $definitionId = InputValidator::positiveInt($data, 'id', 'Quest non valida', 'quest_not_found');
        }

        $dataset = $this->assignmentService()->leaveForGame($definitionId, (array) $data, $characterId, $isStaff);
        $response = ['dataset' => $dataset];
        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function staffInstancesList($echo = true)
    {
        $this->requireStaffCharacter();
        $data = $this->requestDataObject();
        $filters = [
            'status' => InputValidator::string($data, 'status', ''),
            'quest_definition_id' => InputValidator::integer($data, 'quest_definition_id', 0),
            'assignee_type' => InputValidator::string($data, 'assignee_type', ''),
            'assignee_id' => InputValidator::integer($data, 'assignee_id', 0),
        ];
        $limit = max(1, min(200, InputValidator::integer($data, 'limit', 30)));
        $page = max(1, InputValidator::integer($data, 'page', 1));
        $orderBy = InputValidator::string($data, 'orderBy', 'id|DESC');
        $dataset = $this->assignmentService()->listInstancesForStaff(
            $filters,
            $limit,
            $page,
            $orderBy,
        );
        $response = ['dataset' => $dataset];
        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function staffStepConfirm($echo = true)
    {
        $actorCharacterId = $this->requireStaffCharacter();
        $data = $this->requestDataObject();
        $dataset = $this->progressService()->confirmStepForStaff((array) $data, $actorCharacterId);
        $response = ['dataset' => $dataset];
        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function staffInstanceStatusSet($echo = true)
    {
        $actorCharacterId = $this->requireStaffCharacter();
        $data = $this->requestDataObject();
        $instanceId = InputValidator::integer($data, 'quest_instance_id', 0);
        if ($instanceId <= 0) {
            $instanceId = InputValidator::integer($data, 'instance_id', 0);
        }
        $status = InputValidator::string($data, 'status', '');
        $intensityLevel = null;
        if (isset($data->intensity_level)) {
            $intensityLevel = InputValidator::string($data, 'intensity_level', '');
        }
        if ($instanceId <= 0 || $status === '') {
            throw AppError::validation('Dati non validi', [], 'quest_instance_invalid_state');
        }
        $dataset = $this->progressService()->setInstanceStatus($instanceId, $status, $actorCharacterId, 'staff_status_set', $intensityLevel);
        $response = ['dataset' => $dataset];
        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function staffInstanceForceProgress($echo = true)
    {
        $actorCharacterId = $this->requireStaffCharacter();
        $data = $this->requestDataObject();
        $dataset = $this->progressService()->forceProgress((array) $data, $actorCharacterId);
        $response = ['dataset' => $dataset];
        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function staffClosureGet($echo = true)
    {
        $actorCharacterId = $this->requireStaffCharacter();
        $data = $this->requestDataObject();
        $instanceId = InputValidator::integer($data, 'quest_instance_id', 0);
        if ($instanceId <= 0) {
            $instanceId = InputValidator::integer($data, 'instance_id', 0);
        }
        if ($instanceId <= 0) {
            $instanceId = InputValidator::positiveInt($data, 'id', 'Istanza non valida', 'quest_closure_invalid');
        }

        $dataset = $this->closureService()->getForStaff($instanceId, $actorCharacterId);
        $response = ['dataset' => $dataset];
        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function staffClosureFinalize($echo = true)
    {
        $actorCharacterId = $this->requireStaffCharacter();
        $data = $this->requestDataObject();
        $dataset = $this->closureService()->finalize((array) $data, $actorCharacterId);
        $response = ['dataset' => $dataset];
        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function historyList($echo = true)
    {
        $characterId = $this->requireCharacter();
        $isStaff = $this->isStaff();
        $data = $this->requestDataObject();

        $filters = [
            'status' => InputValidator::string($data, 'status', ''),
            'search' => InputValidator::string($data, 'search', ''),
            'from' => InputValidator::firstString($data, ['from', 'period_from'], ''),
            'to' => InputValidator::firstString($data, ['to', 'period_to'], ''),
        ];
        $limit = max(1, min(100, InputValidator::integer($data, 'limit', 20)));
        $page = max(1, InputValidator::integer($data, 'page', 1));

        $dataset = $this->historyService()->listForViewer(
            $characterId,
            $isStaff,
            $filters,
            $limit,
            $page,
        );

        $response = ['dataset' => $dataset];
        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function historyGet($echo = true)
    {
        $characterId = $this->requireCharacter();
        $isStaff = $this->isStaff();
        $data = $this->requestDataObject();
        $instanceId = InputValidator::integer($data, 'quest_instance_id', 0);
        if ($instanceId <= 0) {
            $instanceId = InputValidator::integer($data, 'instance_id', 0);
        }
        if ($instanceId <= 0) {
            $instanceId = InputValidator::positiveInt($data, 'id', 'Istanza non valida', 'quest_history_forbidden');
        }

        $dataset = $this->historyService()->getForViewer($instanceId, $characterId, $isStaff);
        $response = ['dataset' => $dataset];
        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }
}



