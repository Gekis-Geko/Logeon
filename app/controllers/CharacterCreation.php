<?php

declare(strict_types=1);

use App\Services\CharacterCreationService;
use Core\Http\ApiResponse;
use Core\Http\AppError;
use Core\Http\InputValidator;
use Core\Http\RequestData;
use Core\Http\ResponseEmitter;
use Core\Logging\LoggerInterface;

class CharacterCreation
{
    /** @var LoggerInterface|null */
    private $logger = null;
    /** @var object|null */
    private $archetypeProvider = null;
    /** @var CharacterCreationService|null */
    private $characterCreationService = null;

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

    private function isArchetypeProviderContract($provider): bool
    {
        if (!is_object($provider)) {
            return false;
        }

        return method_exists($provider, 'getConfig')
            && method_exists($provider, 'assignArchetype')
            && method_exists($provider, 'clearCharacterArchetypes')
            && method_exists($provider, 'validateSelectableArchetypes');
    }

    private function archetypeProvider(): ?object
    {
        if (is_object($this->archetypeProvider)) {
            return $this->archetypeProvider;
        }

        if (!class_exists('\\Core\\Hooks')) {
            return null;
        }

        $provider = \Core\Hooks::filter('character.archetype.provider', null);
        if ($this->isArchetypeProviderContract($provider)) {
            $this->archetypeProvider = $provider;
        }

        return $this->archetypeProvider;
    }

    private function characterCreationService(): CharacterCreationService
    {
        if ($this->characterCreationService instanceof CharacterCreationService) {
            return $this->characterCreationService;
        }

        $this->characterCreationService = new CharacterCreationService();
        return $this->characterCreationService;
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

    private function requireUser(): int
    {
        return (int) \Core\AuthGuard::api()->requireUser();
    }

    public function createCharacter($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $userId = $this->requireUser();

        $data = $this->requestDataObject();
        $name = InputValidator::string($data, 'name', '');
        $surname = InputValidator::string($data, 'surname', '');
        $gender = InputValidator::integer($data, 'gender', 0);
        if ($gender !== 0 && $gender !== 1) {
            $gender = 0;
        }

        if ($name === '') {
            throw AppError::validation('Il nome del personaggio e obbligatorio', [], 'character_name_required');
        }
        if (strlen($name) > 25) {
            throw AppError::validation('Il nome del personaggio supera la lunghezza massima consentita', [], 'character_name_too_long');
        }
        if ($surname !== '' && strlen($surname) > 30) {
            throw AppError::validation('Il cognome del personaggio supera la lunghezza massima consentita', [], 'character_surname_too_long');
        }

        if (class_exists('\\Core\\Hooks')) {
            $hookErrors = \Core\Hooks::filter('character.create.validate', [], $data);
            if (is_array($hookErrors) && !empty($hookErrors)) {
                $firstError = (string) reset($hookErrors);
                if ($firstError === '') {
                    $firstError = 'Validazione personaggio non superata';
                }
                throw AppError::validation($firstError, $hookErrors, 'character_validation_hook_failed');
            }
        }

        $archetypeProvider = $this->archetypeProvider();
        $archetypesEnabled = false;
        $archetypeRequired = false;
        $multipleAllowed = false;
        if ($archetypeProvider !== null) {
            $rawConfig = $archetypeProvider->getConfig();
            $archetypesEnabled = ((int) $rawConfig['archetypes_enabled']) === 1;
            $archetypeRequired = $archetypesEnabled && ((int) $rawConfig['archetype_required']) === 1;
            $multipleAllowed = $archetypesEnabled && ((int) $rawConfig['multiple_archetypes_allowed']) === 1;
        }

        $rawArchetypeIds = [];
        if (isset($data->archetype_ids)) {
            if (is_array($data->archetype_ids)) {
                $rawArchetypeIds = $data->archetype_ids;
            } elseif (is_object($data->archetype_ids)) {
                $rawArchetypeIds = array_values((array) $data->archetype_ids);
            } else {
                $rawArchetypeIds = [$data->archetype_ids];
            }
        } elseif (isset($data->archetype_id)) {
            $rawArchetypeIds = [$data->archetype_id];
        }

        $archetypeIds = [];
        foreach ($rawArchetypeIds as $rawArchetypeId) {
            $id = (int) $rawArchetypeId;
            if ($id > 0 && !in_array($id, $archetypeIds, true)) {
                $archetypeIds[] = $id;
            }
        }

        if (!$multipleAllowed && count($archetypeIds) > 1) {
            throw AppError::validation('In questa configurazione puoi selezionare un solo archetipo', [], 'archetype_single_only');
        }
        if ($archetypeRequired && empty($archetypeIds)) {
            throw AppError::validation('La selezione dell\'archetipo e obbligatoria', [], 'archetype_required');
        }
        if ($archetypesEnabled && !empty($archetypeIds) && $archetypeProvider !== null) {
            $this->characterCreationService()->validateSelectableArchetypes($archetypeIds, $archetypeProvider);
        }
        if (!$archetypesEnabled) {
            $archetypeIds = [];
        }

        $character = $this->characterCreationService()->createCharacter(
            $userId,
            $name,
            $surname !== '' ? $surname : null,
            $gender,
            $archetypeIds,
            $archetypeRequired,
            $multipleAllowed,
            $archetypeProvider,
        );

        $characterId = (int) ($character->id ?? 0);
        \Core\SessionStore::set('character_id', $characterId);
        \Core\SessionStore::set('character_gender', (int) ($character->gender ?? 0));
        \Core\SessionStore::set('character_last_location', isset($character->last_location) ? (int) $character->last_location : null);
        \Core\SessionStore::set('character_last_map', isset($character->last_map) ? (int) $character->last_map : null);

        if (class_exists('\\Core\\Hooks')) {
            \Core\Hooks::run('character.created', $characterId, $data);
        }

        $response = [
            'status' => 'ok',
            'character_id' => $characterId,
            'redirect' => '/game/',
        ];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }
}
