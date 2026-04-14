<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\ConflictResolverInterface;
use Core\Database\DbAdapterFactory;
use Core\Database\DbAdapterInterface;
use Core\Hooks;

class ConflictResolverFactory
{
    /** @var DbAdapterInterface */
    private $db;
    /** @var ConflictSettingsService */
    private $settings;

    public function __construct(
        DbAdapterInterface $db = null,
        ConflictSettingsService $settings = null,
    ) {
        $this->db = $db ?: DbAdapterFactory::createFromConfig();
        $this->settings = $settings ?: new ConflictSettingsService($this->db);
    }

    public function forMode(string $mode): ConflictResolverInterface
    {
        $mode = strtolower(trim($mode));
        if ($mode === ConflictSettingsService::MODE_RANDOM) {
            $resolver = new ConflictRandomResolver($this->db, $this->settings);
        } else {
            $resolver = new ConflictNarrativeResolver($this->db, $this->settings);
        }

        if (!class_exists('\\Core\\Hooks')) {
            return $resolver;
        }

        $filtered = Hooks::filter('conflict.resolver', $resolver, $mode, $this->db, $this->settings);
        if ($filtered instanceof ConflictResolverInterface) {
            return $filtered;
        }

        if (is_string($filtered) && class_exists($filtered)) {
            $candidate = new $filtered($this->db, $this->settings);
            if ($candidate instanceof ConflictResolverInterface) {
                return $candidate;
            }
        }

        return $resolver;
    }
}
