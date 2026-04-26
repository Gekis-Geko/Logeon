<?php

declare(strict_types=1);

namespace Modules\Logeon\Quests;

final class QuestModuleBootstrap
{
    public static function registerHooks(): void
    {
        if (!class_exists('\\Core\\Hooks')) {
            return;
        }

        \Core\Hooks::add('twig.view_paths', static function ($paths) {
            if (!is_array($paths)) {
                $paths = [];
            }
            $viewPath = __DIR__ . '/../views';
            if (!in_array($viewPath, $paths, true)) {
                $paths[] = $viewPath;
            }
            return $paths;
        });

        \Core\Hooks::add('twig.slot.game.modals', static function ($fragments) {
            if (!is_array($fragments)) {
                $fragments = [];
            }
            $fragments[] = [
                'id' => 'quests-game-modals',
                'template' => 'app/modals/quests/quests.twig',
                'after' => '',
                'before' => '',
                'data' => [],
            ];
            return $fragments;
        });

        \Core\Hooks::add('twig.slot.game.navbar.quests', static function ($fragments) {
            if (!is_array($fragments)) {
                $fragments = [];
            }
            $fragments[] = [
                'id' => 'quests-game-navbar-link',
                'template' => 'app/layout/navbar-quests-link.twig',
                'after' => '',
                'before' => '',
                'data' => [],
            ];
            return $fragments;
        });

        \Core\Hooks::add('twig.slot.game.navbar.organizations.after_bank', static function ($fragments) {
            if (!is_array($fragments)) {
                $fragments = [];
            }
            $fragments[] = [
                'id' => 'quests-game-navbar-link',
                'template' => 'app/layout/navbar-quests-link.twig',
                'after' => '',
                'before' => '',
                'data' => [],
            ];
            return $fragments;
        });

        \Core\Hooks::add('twig.slot.game.offcanvas.mobile.quests', static function ($fragments) {
            if (!is_array($fragments)) {
                $fragments = [];
            }
            $fragments[] = [
                'id' => 'quests-game-offcanvas-link',
                'template' => 'app/offcanvas/mobile-quests-link.twig',
                'after' => '',
                'before' => '',
                'data' => [],
            ];
            return $fragments;
        });

        \Core\Hooks::add('twig.slot.game.offcanvas.mobile.organizations.after', static function ($fragments) {
            if (!is_array($fragments)) {
                $fragments = [];
            }
            $fragments[] = [
                'id' => 'quests-game-offcanvas-link',
                'template' => 'app/offcanvas/mobile-quests-link.twig',
                'after' => '',
                'before' => '',
                'data' => [],
            ];
            return $fragments;
        });

        \Core\Hooks::add('twig.slot.game.home.quick_actions', static function ($fragments) {
            if (!is_array($fragments)) {
                $fragments = [];
            }
            $fragments[] = [
                'id' => 'quests-game-home-quick-action',
                'template' => 'app/home/quick-action-quest.twig',
                'after' => '',
                'before' => '',
                'data' => [],
            ];
            return $fragments;
        });

        \Core\Hooks::add('twig.slot.game.narrative_events.source_filter_options', static function ($fragments) {
            if (!is_array($fragments)) {
                $fragments = [];
            }
            $fragments[] = [
                'id' => 'quests-game-narrative-events-source-option',
                'template' => 'app/modals/narrative-events/source-filter-option.twig',
                'after' => '',
                'before' => '',
                'data' => [],
            ];
            return $fragments;
        });

        \Core\Hooks::add('twig.slot.admin.dashboard.quests', static function ($fragments) {
            if (!is_array($fragments)) {
                $fragments = [];
            }
            $fragments[] = [
                'id' => 'quests-admin-dashboard-page',
                'template' => 'admin/pages/quests.twig',
                'after' => '',
                'before' => '',
                'data' => [],
            ];
            return $fragments;
        });
    }

    public static function bootstrapTriggers(): void
    {
        if (class_exists('\\App\\Services\\QuestTriggerService')) {
            \App\Services\QuestTriggerService::bootstrap();
        }
    }
}
