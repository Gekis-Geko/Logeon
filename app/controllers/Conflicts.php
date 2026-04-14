<?php

declare(strict_types=1);

use App\Services\ConflictService;
use Core\AuthGuard;
use Core\Http\ApiResponse;
use Core\Http\AppError;
use Core\Http\RequestData;
use Core\Http\ResponseEmitter;

class Conflicts
{
    /** @var ConflictService|null */
    private $service = null;

    public function setService(ConflictService $service = null)
    {
        $this->service = $service;
        return $this;
    }

    private function service(): ConflictService
    {
        if ($this->service instanceof ConflictService) {
            return $this->service;
        }

        $this->service = new ConflictService();
        return $this->service;
    }

    private function requestDataObject($default = null, bool $preserveNull = false)
    {
        $request = RequestData::fromGlobals();
        if ($default === null) {
            $default = (object) [];
        }

        $value = $request->postJson('data', $default, false);
        if ($value === null && $preserveNull) {
            return null;
        }
        if (!is_object($value)) {
            return (object) [];
        }
        return $value;
    }

    private function emitJson(array $payload): void
    {
        ResponseEmitter::emit(ApiResponse::json($payload));
    }

    /**
     * @return array<string, mixed>
     */
    private function requireUserCharacter(bool $allowStaffWithoutCharacter = false): array
    {
        $guard = AuthGuard::api();
        $guard->requireUser();

        $isStaff = \Core\AppContext::authContext()->isStaff();
        $characterId = (int) \Core\AppContext::session()->get('character_id');
        if ($characterId <= 0 && !($allowStaffWithoutCharacter && $isStaff)) {
            $characterId = (int) $guard->requireCharacter();
        }

        return [
            'user_id' => (int) $guard->requireUser(),
            'character_id' => $characterId,
            'is_staff' => $isStaff,
        ];
    }

    private function requireAdmin(): void
    {
        AuthGuard::api()->requireAbility('settings.manage', [], 'Operazione non autorizzata');
    }

    public function list()
    {
        $session = $this->requireUserCharacter(true);
        $data = $this->requestDataObject((object) [], true);
        if ($data === null) {
            $data = (object) [];
        }

        $result = $this->service()->listConflicts(
            $data,
            (int) $session['character_id'],
            !empty($session['is_staff']),
        );
        $this->emitJson($result);
        return $this;
    }

    public function get()
    {
        $session = $this->requireUserCharacter(true);
        $data = $this->requestDataObject((object) [], true);
        if ($data === null) {
            $data = (object) [];
        }

        $conflictId = (int) ($data->conflict_id ?? $data->id ?? 0);
        $detail = $this->service()->getConflict($conflictId);

        if (empty($session['is_staff'])) {
            $openedBy = (int) (($detail['conflict']->opened_by ?? 0));
            $isAllowed = $openedBy === (int) $session['character_id'];
            if (!$isAllowed) {
                foreach ($detail['participants'] as $p) {
                    if ((int) ($p->character_id ?? 0) === (int) $session['character_id']) {
                        $isAllowed = true;
                        break;
                    }
                }
            }
            if (
                !$isAllowed
                && (int) $session['character_id'] > 0
                && !empty($detail['conflict'])
                && (int) ($detail['conflict']->location_id ?? 0) > 0
            ) {
                $locationId = (int) ($detail['conflict']->location_id ?? 0);
                $access = (new Locations())->canAccess($locationId, (int) $session['character_id']);
                if (!empty($access['allowed'])) {
                    $isAllowed = true;
                }
            }
            if (!$isAllowed) {
                throw AppError::unauthorized('Operazione non autorizzata sul conflitto', [], 'conflict_read_forbidden');
            }
        }

        $this->emitJson(['dataset' => $detail]);
        return $this;
    }

    public function open()
    {
        $session = $this->requireUserCharacter();
        $data = $this->requestDataObject((object) [], true);
        if ($data === null) {
            throw AppError::validation('Dati mancanti', [], 'payload_missing');
        }

        $result = $this->service()->openConflict($data, (int) $session['character_id']);
        $this->emitJson(['dataset' => $result]);
        return $this;
    }

    public function propose()
    {
        $session = $this->requireUserCharacter();
        $data = $this->requestDataObject((object) [], true);
        if ($data === null) {
            throw AppError::validation('Dati mancanti', [], 'payload_missing');
        }

        $result = $this->service()->proposeConflict(
            $data,
            (int) $session['character_id'],
            !empty($session['is_staff']),
        );
        $this->emitJson(['dataset' => $result]);
        return $this;
    }

    public function proposalRespond()
    {
        $session = $this->requireUserCharacter();
        $data = $this->requestDataObject((object) [], true);
        if ($data === null) {
            throw AppError::validation('Dati mancanti', [], 'payload_missing');
        }

        $result = $this->service()->respondProposal(
            $data,
            (int) $session['character_id'],
            !empty($session['is_staff']),
        );
        $this->emitJson(['dataset' => $result]);
        return $this;
    }

    public function locationFeed()
    {
        $session = $this->requireUserCharacter();
        $data = $this->requestDataObject((object) [], true);
        if ($data === null) {
            throw AppError::validation('Dati mancanti', [], 'payload_missing');
        }

        $result = $this->service()->locationFeed(
            $data,
            (int) $session['character_id'],
            !empty($session['is_staff']),
        );
        $this->emitJson(['dataset' => $result]);
        return $this;
    }

    public function participantsUpsert()
    {
        $session = $this->requireUserCharacter();
        $data = $this->requestDataObject((object) [], true);
        if ($data === null) {
            throw AppError::validation('Dati mancanti', [], 'payload_missing');
        }

        $result = $this->service()->upsertParticipants(
            $data,
            (int) $session['character_id'],
            !empty($session['is_staff']),
        );
        $this->emitJson(['dataset' => $result]);
        return $this;
    }

    public function actionAdd()
    {
        $session = $this->requireUserCharacter();
        $data = $this->requestDataObject((object) [], true);
        if ($data === null) {
            throw AppError::validation('Dati mancanti', [], 'payload_missing');
        }

        $result = $this->service()->addAction(
            $data,
            (int) $session['character_id'],
            !empty($session['is_staff']),
        );
        $this->emitJson(['dataset' => $result]);
        return $this;
    }

    public function actionExecute()
    {
        $session = $this->requireUserCharacter();
        $data = $this->requestDataObject((object) [], true);
        if ($data === null) {
            throw AppError::validation('Dati mancanti', [], 'payload_missing');
        }

        $result = $this->service()->executeAction(
            $data,
            (int) $session['character_id'],
            !empty($session['is_staff']),
        );
        $this->emitJson(['dataset' => $result]);
        return $this;
    }

    public function statusSet()
    {
        $session = $this->requireUserCharacter();
        $data = $this->requestDataObject((object) [], true);
        if ($data === null) {
            throw AppError::validation('Dati mancanti', [], 'payload_missing');
        }

        $result = $this->service()->setStatus(
            $data,
            (int) $session['character_id'],
            !empty($session['is_staff']),
        );
        $this->emitJson(['dataset' => $result]);
        return $this;
    }

    public function roll()
    {
        $session = $this->requireUserCharacter();
        $data = $this->requestDataObject((object) [], true);
        if ($data === null) {
            throw AppError::validation('Dati mancanti', [], 'payload_missing');
        }

        $result = $this->service()->performRoll(
            $data,
            (int) $session['character_id'],
            !empty($session['is_staff']),
        );
        $this->emitJson(['dataset' => $result]);
        return $this;
    }

    public function resolve()
    {
        $session = $this->requireUserCharacter();
        $data = $this->requestDataObject((object) [], true);
        if ($data === null) {
            throw AppError::validation('Dati mancanti', [], 'payload_missing');
        }

        $result = $this->service()->resolveConflict(
            $data,
            (int) $session['character_id'],
            !empty($session['is_staff']),
        );
        $this->emitJson(['dataset' => $result]);
        return $this;
    }

    public function close()
    {
        $session = $this->requireUserCharacter();
        $data = $this->requestDataObject((object) [], true);
        if ($data === null) {
            throw AppError::validation('Dati mancanti', [], 'payload_missing');
        }

        $result = $this->service()->closeConflict(
            $data,
            (int) $session['character_id'],
            !empty($session['is_staff']),
        );
        $this->emitJson(['dataset' => $result]);
        return $this;
    }

    public function settingsGet()
    {
        $this->requireAdmin();
        $this->emitJson(['dataset' => $this->service()->getSettings()]);
        return $this;
    }

    public function settingsUpdate()
    {
        $this->requireAdmin();
        $data = $this->requestDataObject((object) [], true);
        if ($data === null) {
            $data = (object) [];
        }

        $dataset = $this->service()->updateSettings($data);
        $this->emitJson(['dataset' => $dataset]);
        return $this;
    }

    public function forceOpen()
    {
        $this->requireAdmin();
        $session = $this->requireUserCharacter(true);
        $data = $this->requestDataObject((object) [], true);
        if ($data === null) {
            throw AppError::validation('Dati mancanti', [], 'payload_missing');
        }

        $dataset = $this->service()->forceOpenConflict($data, (int) $session['character_id'], true);
        $this->emitJson(['dataset' => $dataset]);
        return $this;
    }

    public function forceClose()
    {
        $this->requireAdmin();
        $session = $this->requireUserCharacter(true);
        $data = $this->requestDataObject((object) [], true);
        if ($data === null) {
            throw AppError::validation('Dati mancanti', [], 'payload_missing');
        }

        $dataset = $this->service()->forceCloseConflict($data, (int) $session['character_id'], true);
        $this->emitJson(['dataset' => $dataset]);
        return $this;
    }

    public function editLog()
    {
        $this->requireAdmin();
        $session = $this->requireUserCharacter(true);
        $data = $this->requestDataObject((object) [], true);
        if ($data === null) {
            throw AppError::validation('Dati mancanti', [], 'payload_missing');
        }

        $dataset = $this->service()->editConflictLog($data, (int) $session['character_id'], true);
        $this->emitJson(['dataset' => $dataset]);
        return $this;
    }

    public function overrideRoll()
    {
        $this->requireAdmin();
        $session = $this->requireUserCharacter(true);
        $data = $this->requestDataObject((object) [], true);
        if ($data === null) {
            throw AppError::validation('Dati mancanti', [], 'payload_missing');
        }

        $dataset = $this->service()->overrideRoll($data, (int) $session['character_id'], true);
        $this->emitJson(['dataset' => $dataset]);
        return $this;
    }
}
