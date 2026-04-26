<?php

declare(strict_types=1);

namespace Modules\Logeon\Archetypes\Controllers;

use Modules\Logeon\Archetypes\Services\ArchetypeConfigAccessor;
use Modules\Logeon\Archetypes\Services\ArchetypeProviderRegistry;
use Modules\Logeon\Archetypes\Contracts\ArchetypeProviderInterface;
use Core\Http\ApiResponse;
use Core\Http\AppError;
use Core\Http\InputValidator;
use Core\Http\RequestData;
use Core\Http\ResponseEmitter;
use Core\Logging\LoggerInterface;

class Archetypes
{
    /** @var LoggerInterface|null */
    private $logger = null;
    /** @var ArchetypeProviderInterface|null */
    private $archetypeProvider = null;

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

    protected function trace($message, $context = false): void
    {
        $this->logger()->trace($message, $context);
    }

    private function archetypeProvider(): ArchetypeProviderInterface
    {
        if ($this->archetypeProvider instanceof ArchetypeProviderInterface) {
            return $this->archetypeProvider;
        }
        $this->archetypeProvider = ArchetypeProviderRegistry::provider();
        return $this->archetypeProvider;
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

    /**
     * @param array<int,mixed> $rows
     * @return array<int,array<string,mixed>>
     */
    private function normalizeDatasetRows(array $rows): array
    {
        $normalized = [];
        foreach ($rows as $row) {
            if (is_object($row)) {
                $row = (array) $row;
            }
            if (is_array($row)) {
                $normalized[] = $row;
            }
        }

        return $normalized;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{config:array{archetypes_enabled:int,archetype_required:int,multiple_archetypes_allowed:int},dataset:array<int,array<string,mixed>>}
     */
    private function normalizePublicPayload(array $payload): array
    {
        $rawConfig = is_array($payload['config'] ?? null) ? $payload['config'] : [];
        $rawDataset = is_array($payload['dataset'] ?? null) ? $payload['dataset'] : [];

        return [
            'config' => ArchetypeConfigAccessor::normalize($rawConfig),
            'dataset' => $this->normalizeDatasetRows($rawDataset),
        ];
    }

    private function requireCharacter(): int
    {
        return (int) \Core\AuthGuard::api()->requireCharacter();
    }

    private function requireAdmin(): void
    {
        \Core\AuthGuard::api()->requireAbility('settings.manage');
    }

    // -------------------------------------------------------------------------
    // Game-facing endpoints
    // -------------------------------------------------------------------------

    public function publicList($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);

        $response = $this->normalizePublicPayload($this->archetypeProvider()->publicList());

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function publicDocsList($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);

        // Always show active archetypes regardless of the gameplay enabled flag.
        // archetypes_enabled governs character selection, not documentation visibility.
        $allRows = $this->archetypeProvider()->adminList(
            ['is_active' => 1],
            100,
            1,
            'sort_order|ASC'
        );
        $rows = is_array($allRows['rows'] ?? null) ? $allRows['rows'] : [];

        $chapters   = [];
        $chapterNum = 1;

        foreach ($rows as $row) {
            if (is_object($row)) {
                $row = (array) $row;
            }
            if (!is_array($row)) {
                continue;
            }
            if ((int) ($row['is_active'] ?? 1) !== 1) {
                continue;
            }

            $body = '';
            if (!empty($row['icon'])) {
                $icon = htmlspecialchars((string) $row['icon'], ENT_QUOTES, 'UTF-8');
                $alt  = htmlspecialchars((string) ($row['name'] ?? 'Archetipo'), ENT_QUOTES, 'UTF-8');
                $body .= '<img src="' . $icon . '" alt="' . $alt . '"'
                    . ' class="rounded border mb-3" style="max-width:96px;max-height:96px;object-fit:cover;display:block;">';
            }
            if (!empty($row['description'])) {
                $body .= $row['description'];
            }

            $subchapters = [];
            if (!empty($row['lore_text'])) {
                $subchapters[] = [
                    'chapter' => $chapterNum,
                    'sub'     => 1,
                    'label'   => 'Approfondimento narrativo',
                    'body'    => $row['lore_text'],
                ];
            }

            $chapters[] = [
                'chapter'     => $chapterNum,
                'label'       => $row['name'] ?? ('Archetipo ' . $chapterNum),
                'body'        => $body,
                'subchapters' => $subchapters,
            ];
            $chapterNum++;
        }

        $response = ['chapters' => $chapters];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function characterArchetypes($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $characterId = $this->requireCharacter();

        $rows = $this->normalizeDatasetRows($this->archetypeProvider()->getCharacterArchetypes($characterId));
        $response = ['dataset' => $rows];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function assignArchetype($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $characterId = $this->requireCharacter();

        $data = $this->requestDataObject();
        $archetypeId = InputValidator::integer($data, 'archetype_id', 0);

        if ($archetypeId <= 0) {
            throw AppError::validation('ID archetipo obbligatorio', [], 'archetype_id_required');
        }

        $config = $this->archetypeProvider()->getConfig();
        $multipleAllowed = ArchetypeConfigAccessor::isMultipleAllowed($config);

        $this->archetypeProvider()->assignArchetype($characterId, $archetypeId, $multipleAllowed);

        $rows = $this->normalizeDatasetRows($this->archetypeProvider()->getCharacterArchetypes($characterId));
        $response = ['dataset' => $rows];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function removeArchetype($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $characterId = $this->requireCharacter();

        $data = $this->requestDataObject();
        $archetypeId = InputValidator::integer($data, 'archetype_id', 0);

        if ($archetypeId <= 0) {
            throw AppError::validation('ID archetipo obbligatorio', [], 'archetype_id_required');
        }

        $this->archetypeProvider()->removeArchetype($characterId, $archetypeId);

        $rows = $this->normalizeDatasetRows($this->archetypeProvider()->getCharacterArchetypes($characterId));
        $response = ['dataset' => $rows];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    // -------------------------------------------------------------------------
    // Admin — config
    // -------------------------------------------------------------------------

    public function adminConfigGet($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();

        $config = ArchetypeConfigAccessor::normalize($this->archetypeProvider()->getConfig());
        $response = ['dataset' => $config];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function adminConfigUpdate($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();

        $data = $this->requestDataObject();
        $config = ArchetypeConfigAccessor::normalize($this->archetypeProvider()->updateConfig($data));
        $response = ['dataset' => $config];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    // -------------------------------------------------------------------------
    // Admin — CRUD
    // -------------------------------------------------------------------------

    public function adminList($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();

        $data = $this->requestDataObject();
        $query = (isset($data->query) && is_object($data->query)) ? $data->query : (object) [];

        $filters = [
            'search' => InputValidator::firstString($query, ['search'], InputValidator::string($data, 'search', '')),
        ];

        $isActiveRaw = property_exists($query, 'is_active')
            ? InputValidator::integer($query, 'is_active', 0)
            : (property_exists($data, 'is_active') ? InputValidator::integer($data, 'is_active', 0) : null);
        if ($isActiveRaw !== null) {
            $filters['is_active'] = ((int) $isActiveRaw === 1) ? 1 : 0;
        }

        $isSelectableRaw = property_exists($query, 'is_selectable')
            ? InputValidator::integer($query, 'is_selectable', 0)
            : (property_exists($data, 'is_selectable') ? InputValidator::integer($data, 'is_selectable', 0) : null);
        if ($isSelectableRaw !== null) {
            $filters['is_selectable'] = ((int) $isSelectableRaw === 1) ? 1 : 0;
        }

        $limit = InputValidator::integer($data, 'results', 0);
        if ($limit <= 0) {
            $limit = InputValidator::integer($data, 'limit', 20);
        }
        $limit = max(1, min(100, $limit));

        $page = max(1, InputValidator::integer($data, 'page', 1));
        $sort = InputValidator::firstString($data, ['orderBy', 'sort'], 'sort_order|ASC');

        $result = $this->archetypeProvider()->adminList($filters, $limit, $page, $sort);
        $response = [
            'properties' => [
                'query' => [
                    'search' => $filters['search'],
                    'is_active' => array_key_exists('is_active', $filters) ? (int) $filters['is_active'] : '',
                    'is_selectable' => array_key_exists('is_selectable', $filters) ? (int) $filters['is_selectable'] : '',
                ],
                'page' => (int) $result['page'],
                'results_page' => (int) $result['limit'],
                'orderBy' => $sort,
                'tot' => ['count' => (int) $result['total']],
            ],
            'dataset' => $this->normalizeDatasetRows($result['rows']),
        ];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function adminCreate($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();

        $data = $this->requestDataObject();
        $archetype = $this->archetypeProvider()->adminCreate($data);
        $response = ['dataset' => $archetype];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function adminUpdate($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();

        $data = $this->requestDataObject();
        $archetype = $this->archetypeProvider()->adminUpdate($data);
        $response = ['dataset' => $archetype];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function adminDelete($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();

        $data = $this->requestDataObject();
        $id = InputValidator::integer($data, 'id', 0);

        $this->archetypeProvider()->adminDelete($id);
        $response = ['status' => 'ok'];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    // -------------------------------------------------------------------------
    // Admin — character archetype management
    // -------------------------------------------------------------------------

    public function adminCharacterArchetypes($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();

        $data = $this->requestDataObject();
        $characterId = InputValidator::integer($data, 'character_id', 0);

        $rows = $this->normalizeDatasetRows($this->archetypeProvider()->getCharacterArchetypes($characterId));
        $response = ['dataset' => $rows];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function adminAssignArchetype($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();

        $data = $this->requestDataObject();
        $characterId = InputValidator::integer($data, 'character_id', 0);
        $archetypeId = InputValidator::integer($data, 'archetype_id', 0);

        if ($characterId <= 0) {
            throw AppError::validation('ID personaggio obbligatorio', [], 'character_id_required');
        }
        if ($archetypeId <= 0) {
            throw AppError::validation('ID archetipo obbligatorio', [], 'archetype_id_required');
        }

        $config = $this->archetypeProvider()->getConfig();
        $multipleAllowed = ArchetypeConfigAccessor::isMultipleAllowed($config);

        $this->archetypeProvider()->assignArchetype($characterId, $archetypeId, $multipleAllowed);

        $rows = $this->normalizeDatasetRows($this->archetypeProvider()->getCharacterArchetypes($characterId));
        $response = ['dataset' => $rows];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function adminRemoveArchetype($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();

        $data = $this->requestDataObject();
        $characterId = InputValidator::integer($data, 'character_id', 0);
        $archetypeId = InputValidator::integer($data, 'archetype_id', 0);

        $this->archetypeProvider()->removeArchetype($characterId, $archetypeId);

        $rows = $this->normalizeDatasetRows($this->archetypeProvider()->getCharacterArchetypes($characterId));
        $response = ['dataset' => $rows];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }
}
