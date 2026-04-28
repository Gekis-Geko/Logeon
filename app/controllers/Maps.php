<?php

declare(strict_types=1);

use App\Models\Map;
use App\Services\MapsAdminService;
use Core\Http\ApiResponse;
use Core\Http\AppError;
use Core\Http\InputValidator;
use Core\Http\RequestData;
use Core\Http\ResponseEmitter;

use Core\Logging\LoggerInterface;

class Maps extends Map
{
    /** @var LoggerInterface|null */
    private $logger = null;

    /** @var MapsAdminService|null */
    private $mapsAdminService = null;

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

        $this->logger = \Core\AppContext::logger();
        return $this->logger;
    }

    private function mapsAdminService(): MapsAdminService
    {
        if ($this->mapsAdminService instanceof MapsAdminService) {
            return $this->mapsAdminService;
        }

        $this->mapsAdminService = new MapsAdminService();
        return $this->mapsAdminService;
    }

    protected function trace($message, $context = false): void
    {
        $this->logger()->trace($message, $context);
    }

    private function requestDataObject()
    {
        $request = RequestData::fromGlobals();
        return InputValidator::postJsonObject($request, 'data', true);
    }

    private function requireAdmin()
    {
        \Core\AuthGuard::api()->requireAbility('settings.manage');
    }

    private function failValidation($message, string $errorCode = 'validation_error')
    {
        throw AppError::validation((string) $message, [], $errorCode);
    }

    public function list($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);

        return parent::list($echo);
    }

    public function adminList($echo = true)
    {
        $this->requireAdmin();

        $data = $this->requestDataObject();
        $query = (isset($data->query) && is_object($data->query)) ? $data->query : (object) [];

        $nameLike = InputValidator::string($query, 'name', '');
        $renderMode = InputValidator::string($query, 'render_mode', '');
        $initial = InputValidator::string($query, 'initial', '');
        $mobile = InputValidator::string($query, 'mobile', '');
        $results = max(1, min(500, InputValidator::integer($data, 'results', 20)));
        $page = max(1, InputValidator::integer($data, 'page', 1));
        $sort = InputValidator::string($data, 'orderBy', 'position|ASC');

        $response = $this->mapsAdminService()->adminList(
            $nameLike,
            $renderMode,
            $initial,
            $mobile,
            $results,
            $page,
            $sort,
        );

        ResponseEmitter::emit(ApiResponse::json($response));
        return $this;
    }

    public function create()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();

        $data = $this->requestDataObject();
        $name = InputValidator::string($data, 'name', '');
        if ($name === '') {
            $this->failValidation('Nome mappa obbligatorio', 'map_name_required');
        }

        $parentMapId = InputValidator::integer($data, 'parent_map_id', 0);

        $this->mapsAdminService()->create([
            'name' => $name,
            'description' => $data->description ?? null,
            'status' => $data->status ?? null,
            'initial' => $data->initial ?? 0,
            'position' => $data->position ?? null,
            'parent_map_id' => $parentMapId > 0 ? $parentMapId : null,
            'mobile' => $data->mobile ?? 0,
            'icon' => $data->icon ?? null,
            'image' => $data->image ?? null,
            'render_mode' => $data->render_mode ?? 'grid',
            'meteo' => $data->meteo ?? null,
        ]);

        return $this;
    }

    public function update()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();

        $data = $this->requestDataObject();
        $id = InputValidator::integer($data, 'id', 0);
        if ($id <= 0) {
            $this->failValidation('ID mappa non valido', 'map_id_invalid');
        }

        $name = InputValidator::string($data, 'name', '');
        if ($name === '') {
            $this->failValidation('Nome mappa obbligatorio', 'map_name_required');
        }

        $parentMapId = InputValidator::integer($data, 'parent_map_id', 0);

        $this->mapsAdminService()->update($id, [
            'name' => $name,
            'description' => $data->description ?? null,
            'status' => $data->status ?? null,
            'initial' => $data->initial ?? 0,
            'position' => $data->position ?? null,
            'parent_map_id' => $parentMapId > 0 ? $parentMapId : null,
            'mobile' => $data->mobile ?? 0,
            'icon' => $data->icon ?? null,
            'image' => $data->image ?? null,
            'render_mode' => $data->render_mode ?? 'grid',
            'meteo' => $data->meteo ?? null,
        ]);

        return $this;
    }

    public function delete($operator = '=')
    {
        $this->requireAdmin();

        $data = $this->requestDataObject();
        $id = InputValidator::integer($data, 'id', 0);
        if ($id <= 0) {
            $this->failValidation('ID mappa non valido', 'map_id_invalid');
        }

        $this->mapsAdminService()->assertCanDelete($id);

        return parent::delete($operator);
    }
}