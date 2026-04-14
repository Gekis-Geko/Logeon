<?php

use App\Services\ArchetypeService;
use App\Services\LocationService;
use App\Services\MessagesService;
use App\Services\NarrativeEventService;
use App\Services\NotificationService;
use App\Services\PresenceService;
use Core\AppContext;
use Core\AuthGuard;
use Core\Http\AppError;
use Core\Redirect;

/** @var \Core\Router $route */
$db = AppContext::dbProvider()->connection();
$presence = new PresenceService($db);
$messages = new MessagesService($db);
$locations = new LocationService($db);
$narrativeEvents = new NarrativeEventService($db);
$notifications = new NotificationService($db);

$route->group('/game', function ($route) use ($db, $presence, $messages, $locations, $narrativeEvents, $notifications) {
    $route->get('/', function () use ($db, $presence, $messages, $locations, $narrativeEvents, $notifications) {
        $guard = AuthGuard::html();
        $userId = (int) $guard->requireUser();
        $characterId = $guard->requireCharacter();

        $presence->touchCharacter((int) $characterId);

        $character = $db->fetchOnePrepared(
            'SELECT c.name,
                    c.last_map,
                    c.last_location,
                    m.name AS last_map_name,
                    l.name AS last_location_name
             FROM characters c
             LEFT JOIN maps m ON c.last_map = m.id
             LEFT JOIN locations l ON c.last_location = l.id
             WHERE c.id = ?
             LIMIT 1',
            [$characterId],
        );

        $characterName = '';
        if (!empty($character) && isset($character->name)) {
            $characterName = trim((string) $character->name);
        }

        $sessionPosition = [
            'state' => 'maps',
            'state_label' => 'Alle mappe',
            'map_id' => null,
            'map_name' => '',
            'location_id' => null,
            'location_name' => '',
            'resume_url' => '/game/maps',
        ];

        if (!empty($character)) {
            $lastMapId = isset($character->last_map) ? (int) $character->last_map : 0;
            $lastLocationId = isset($character->last_location) ? (int) $character->last_location : 0;
            $lastMapName = isset($character->last_map_name) ? trim((string) $character->last_map_name) : '';
            $lastLocationName = isset($character->last_location_name) ? trim((string) $character->last_location_name) : '';

            if ($lastMapId > 0 && $lastLocationId > 0) {
                $sessionPosition = [
                    'state' => 'location',
                    'state_label' => 'In location',
                    'map_id' => $lastMapId,
                    'map_name' => $lastMapName,
                    'location_id' => $lastLocationId,
                    'location_name' => $lastLocationName,
                    'resume_url' => '/game/maps/' . $lastMapId . '/location/' . $lastLocationId,
                ];
            } elseif ($lastMapId > 0) {
                $sessionPosition = [
                    'state' => 'map',
                    'state_label' => 'In mappa',
                    'map_id' => $lastMapId,
                    'map_name' => $lastMapName,
                    'location_id' => null,
                    'location_name' => '',
                    'resume_url' => '/game/maps/' . $lastMapId,
                ];
            }
        }

        $unreadPm = (int) $messages->countUnread((int) $characterId);
        $pendingInvites = count($locations->listPendingInvitesForCharacter((int) $characterId));
        $notificationSummary = $notifications->listForRecipient(
            $userId,
            (int) $characterId,
            ['page' => 1, 'results' => 1],
        );

        $unreadNotifications = (int) (($notificationSummary['meta']['unread_count'] ?? 0));
        $pendingActions = (int) (($notificationSummary['meta']['pending_count'] ?? 0));

        $eventsPayload = $narrativeEvents->listForViewer(
            [],
            (int) $characterId,
            AppContext::authContext()->isStaff(),
            8,
            1,
        );
        $recentEvents = is_array($eventsPayload['rows'] ?? null) ? $eventsPayload['rows'] : [];

        return AppContext::templateRenderer()->render('app.twig', [
            'app_page' => 'home',
            'character_name' => $characterName !== '' ? $characterName : '',
            'recent_events' => $recentEvents,
            'session_position' => $sessionPosition,
            'notifications' => [
                'pm_unread' => $unreadPm,
                'pending_invites' => (int) $pendingInvites,
                'unread_total' => $unreadNotifications,
                'pending_actions' => $pendingActions,
            ],
        ]);
    });
});

$renderAdminPage = function ($page = 'dashboard') use ($presence) {
    $guard = AuthGuard::html();
    $guard->requireAbility('settings.manage', [], "Accesso non autorizzato all'admin");
    $userId = $guard->requireUser();
    $presence->touchUser((int) $userId);

    $page = strtolower(trim((string) $page));
    if ($page === '') {
        $page = 'dashboard';
    }

    return AppContext::templateRenderer()->render('admin/dashboard.twig', [
        'admin_page' => $page,
    ]);
};

$route->group('/admin', function ($route) use ($renderAdminPage) {
    $route->get('/', function () use ($renderAdminPage) {
        return $renderAdminPage('dashboard');
    });

    $route->get('/{page}', function ($page) use ($renderAdminPage) {
        return $renderAdminPage($page);
    });
});

$route->group('/game/profile', function ($route) use ($presence) {
    $route->get('/', function () use ($presence) {
        $characterId = AuthGuard::html()->requireCharacter();

        $presence->touchCharacter((int) $characterId);

        return AppContext::templateRenderer()->render('app/profile.twig', ['character_id' => $characterId]);
    });

    $route->get('/edit', function () use ($presence) {
        $characterId = AuthGuard::html()->requireCharacter();

        $presence->touchCharacter((int) $characterId);

        return AppContext::templateRenderer()->render('app/profile_edit.twig', ['character_id' => $characterId]);
    });

    $route->get('/{id}', function ($id) use ($presence) {
        $characterId = AuthGuard::html()->requireCharacter();

        $presence->touchCharacter((int) $characterId);

        return AppContext::templateRenderer()->render('app/profile.twig', ['character_id' => $id]);
    });
});

$route->group('/game/settings', function ($route) use ($presence) {
    $route->get('/', function () use ($presence) {
        $characterId = AuthGuard::html()->requireCharacter();

        $presence->touchCharacter((int) $characterId);

        return AppContext::templateRenderer()->render('app/settings.twig');
    });
});

$route->group('/game/quests', function ($route) use ($presence) {
    $route->get('/history', function () use ($presence) {
        $characterId = AuthGuard::html()->requireCharacter();

        $presence->touchCharacter((int) $characterId);

        return AppContext::templateRenderer()->render('app/quest_history.twig', ['character_id' => $characterId]);
    });
});

$route->group('/game/bag', function ($route) use ($presence) {
    $route->get('/', function () use ($presence) {
        $characterId = AuthGuard::html()->requireCharacter();

        $presence->touchCharacter((int) $characterId);

        return AppContext::templateRenderer()->render('app/bag.twig', ['character_id' => $characterId]);
    });
});

$route->group('/game/equips', function ($route) use ($presence) {
    $route->get('/', function () use ($presence) {
        $characterId = AuthGuard::html()->requireCharacter();

        $presence->touchCharacter((int) $characterId);

        return AppContext::templateRenderer()->render('app/equips.twig', ['character_id' => $characterId]);
    });
});

$route->group('/game/maps', function ($route) use ($db, $presence) {
    $route->get('/', function () use ($db, $presence) {
        $characterId = AuthGuard::html()->requireCharacter();

        // "Alle mappe / In giro": nessuna mappa e nessuna location attiva.
        $presence->setCharacterPositionAndTouch((int) $characterId, null, null);

        $maps = $db->fetchAllPrepared(
            'SELECT id
             FROM maps
             ORDER BY position ASC, id ASC
             LIMIT 2',
            [],
        );

        if (!empty($maps) && count($maps) === 1) {
            Redirect::url('/game/maps/' . intval($maps[0]->id));
            return;
        }

        return AppContext::templateRenderer()->render('app/maps.twig');
    });

    $route->get('/{id}', function ($id) use ($presence) {
        $characterId = AuthGuard::html()->requireCharacter();

        // Stato intermedio: in mappa, ma fuori da una location/chat specifica.
        $presence->setCharacterPositionAndTouch((int) $characterId, (int) $id, null);

        return AppContext::templateRenderer()->render('app/locations.twig', ['map_id' => $id]);
    });

    $route->get('/{mapId}/location/{locationId}', function ($map_id, $location_id) use ($db, $presence) {
        $character_id = AuthGuard::html()->requireCharacter();

        $access = (new Locations())->canAccess($location_id, $character_id);
        if (!$access['allowed']) {
            throw AppError::notFound('Location non trovata');
        }

        $presence->setCharacterPositionAndTouch((int) $character_id, (int) $map_id, (int) $location_id);

        $has_location_job = $db->fetchOnePrepared(
            'SELECT cj.id
             FROM character_jobs cj
             LEFT JOIN jobs j ON cj.job_id = j.id
             WHERE cj.character_id = ?
               AND cj.is_active = 1
               AND j.is_active = 1
               AND j.location_id = ?
             LIMIT 1',
            [(int) $character_id, (int) $location_id],
        );

        $location_meta = $db->fetchOnePrepared(
            'SELECT maps.name AS map_name,
                    locations.name AS location_name,
                    locations.description AS location_description,
                    locations.status AS location_status
             FROM locations
             LEFT JOIN maps ON locations.map_id = maps.id
             WHERE locations.id = ?
               AND locations.map_id = ?
             LIMIT 1',
            [(int) $location_id, (int) $map_id],
        );

        $map_name = (!empty($location_meta) && isset($location_meta->map_name)) ? (string) $location_meta->map_name : 'Mappa';
        $location_name = (!empty($location_meta) && isset($location_meta->location_name)) ? (string) $location_meta->location_name : 'Location';
        $location_description = (!empty($location_meta) && isset($location_meta->location_description)) ? (string) $location_meta->location_description : '';
        $location_status = (!empty($location_meta) && isset($location_meta->location_status)) ? (string) $location_meta->location_status : '';

        return AppContext::templateRenderer()->render('app/location.twig', [
            'map_id' => (int) $map_id,
            'location_id' => (int) $location_id,
            'show_jobs_board' => !empty($has_location_job),
            'map_name' => $map_name,
            'location_name' => $location_name,
            'location_description' => $location_description,
            'location_status' => $location_status,
        ]);
    });
});

$route->group('/game/onlines', function ($route) use ($presence) {
    $route->get('/', function () use ($presence) {
        $characterId = AuthGuard::html()->requireCharacter();

        $presence->touchCharacter((int) $characterId);

        return AppContext::templateRenderer()->render('app/onlines.twig');
    });
});

$route->group('/game/anagrafica', function ($route) use ($db, $presence) {
    $route->get('/', function () use ($db, $presence) {
        $guard = AuthGuard::html();
        $characterId = (int) $guard->requireCharacter();
        $presence->touchCharacter($characterId);

        $viewerIsStaff = AppContext::authContext()->isStaff();
        $searchQuery = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
        $page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
        if ($page < 1) {
            $page = 1;
        }
        $resultsAllowed = [10, 20, 50];
        $results = isset($_GET['results']) ? (int) $_GET['results'] : 20;
        if (!in_array($results, $resultsAllowed, true)) {
            $results = 20;
        }

        $whereParts = [
            'u.date_actived IS NOT NULL',
            'NOT EXISTS (
                SELECT 1
                FROM blacklist b
                WHERE b.banned_id = u.id
                  AND b.date_start <= NOW()
                  AND (b.date_end IS NULL OR b.date_end > NOW())
            )',
        ];

        if (!$viewerIsStaff) {
            $whereParts[] = 'c.is_visible = 1';
        }

        $whereParams = [];
        if ($searchQuery !== '') {
            $whereParts[] = '(
                LOWER(c.name) LIKE LOWER(?)
                OR LOWER(IFNULL(c.surname, "")) LIKE LOWER(?)
                OR LOWER(CONCAT(c.name, " ", IFNULL(c.surname, ""))) LIKE LOWER(?)
            )';
            $searchLike = '%' . $searchQuery . '%';
            $whereParams[] = $searchLike;
            $whereParams[] = $searchLike;
            $whereParams[] = $searchLike;
        }

        $whereSql = '';
        if (!empty($whereParts)) {
            $whereSql = ' WHERE ' . implode(' AND ', $whereParts);
        }

        $countRow = $db->fetchOnePrepared(
            'SELECT COUNT(*) AS count
             FROM characters c
             LEFT JOIN users u ON c.user_id = u.id
             ' . $whereSql . '
             LIMIT 1',
            $whereParams,
        );

        $totalCount = (!empty($countRow) && isset($countRow->count)) ? (int) $countRow->count : 0;
        $totalPages = $totalCount > 0 ? (int) ceil($totalCount / $results) : 0;
        if ($totalPages > 0 && $page > $totalPages) {
            $page = $totalPages;
        }
        $offset = ($page - 1) * $results;
        if ($offset < 0) {
            $offset = 0;
        }

        $rows = $db->fetchAllPrepared(
            'SELECT c.id,
                    c.name,
                    c.surname,
                    c.gender,
                    c.avatar,
                    c.is_visible,
                    c.date_created,
                    c.last_map,
                    c.last_location,
                    CASE
                        WHEN c.is_visible = 0 THEN 0
                        WHEN IFNULL(c.privacy_show_online, 1) = 0 THEN 0
                        WHEN c.date_last_signin IS NOT NULL
                          AND c.date_last_signin > IFNULL(c.date_last_signout, "1970-01-01 00:00:00")
                          AND DATE_ADD(c.date_last_seed, INTERVAL 20 MINUTE) > NOW()
                        THEN 1
                        ELSE 0
                    END AS is_online,
                    ss.name AS socialstatus_name,
                    m.name AS map_name,
                    l.name AS location_name
             FROM characters c
             LEFT JOIN users u ON c.user_id = u.id
              LEFT JOIN social_status ss ON c.socialstatus_id = ss.id
              LEFT JOIN maps m ON c.last_map = m.id
              LEFT JOIN locations l ON c.last_location = l.id
              ' . $whereSql . '
              ORDER BY c.name ASC, c.surname ASC, c.id ASC
              LIMIT ?, ?',
            array_merge($whereParams, [$offset, $results]),
        );

        return AppContext::templateRenderer()->render('app/anagrafica.twig', [
            'anagrafica_rows' => is_array($rows) ? $rows : [],
            'is_staff_viewer' => $viewerIsStaff,
            'search_query' => $searchQuery,
            'page' => $page,
            'results' => $results,
            'total_count' => $totalCount,
        ]);
    });
});

$route->group('/game/archetypes', function ($route) use ($presence) {
    $route->get('/', function () use ($presence) {
        $guard = AuthGuard::html();
        $characterId = (int) $guard->requireCharacter();
        $presence->touchCharacter($characterId);

        $archetypeService = new ArchetypeService();
        $payload = $archetypeService->publicList();
        $config = is_array($payload['config'] ?? null) ? $payload['config'] : [];
        $rows = is_array($payload['dataset'] ?? null) ? $payload['dataset'] : [];
        $enabled = (int) ($config['archetypes_enabled'] ?? 0) === 1;

        return AppContext::templateRenderer()->render('app/archetypes.twig', [
            'archetypes_enabled' => $enabled,
            'archetypes_config' => $config,
            'archetypes_rows' => $rows,
        ]);
    });
});

$route->group('/game/jobs', function ($route) use ($db, $presence) {
    $route->get('/', function () use ($presence) {
        $characterId = AuthGuard::html()->requireCharacter();

        $presence->touchCharacter((int) $characterId);

        return AppContext::templateRenderer()->render('app/jobs.twig');
    });

    $route->get('/location/{id}', function ($id) use ($db, $presence) {
        $characterId = AuthGuard::html()->requireCharacter();

        $presence->touchCharacter((int) $characterId);

        $location_name = null;
        $location = $db->fetchOnePrepared(
            'SELECT name FROM locations WHERE id = ? LIMIT 1',
            [(int) $id],
        );
        if (!empty($location)) {
            $location_name = $location->name;
        }

        return AppContext::templateRenderer()->render('app/jobs.twig', [
            'location_id' => $id,
            'location_name' => $location_name,
        ]);
    });
});

$route->group('/game/guilds', function ($route) use ($presence) {
    $route->get('/', function () use ($presence) {
        $characterId = AuthGuard::html()->requireCharacter();

        $presence->touchCharacter((int) $characterId);

        return AppContext::templateRenderer()->render('app/guilds.twig');
    });

    $route->get('/{id}', function ($id) use ($presence) {
        $characterId = AuthGuard::html()->requireCharacter();

        $presence->touchCharacter((int) $characterId);

        return AppContext::templateRenderer()->render('app/guild.twig', ['guild_id' => $id]);
    });
});

$route->group('/game/factions', function ($route) use ($presence) {
    $route->get('/', function () use ($presence) {
        $characterId = AuthGuard::html()->requireCharacter();
        $presence->touchCharacter((int) $characterId);
        return AppContext::templateRenderer()->render('app/factions.twig');
    });
});

$route->group('/game/shop', function ($route) use ($presence) {
    $route->get('/', function () use ($presence) {
        $characterId = AuthGuard::html()->requireCharacter();

        $presence->touchCharacter((int) $characterId);

        return AppContext::templateRenderer()->render('app/shop.twig');
    });

    $route->get('/location/{id}', function ($id) use ($presence) {
        $characterId = AuthGuard::html()->requireCharacter();

        $presence->touchCharacter((int) $characterId);

        return AppContext::templateRenderer()->render('app/shop.twig', ['location_id' => $id]);
    });
});

$route->group('/game/bank', function ($route) use ($presence) {
    $route->get('/', function () use ($presence) {
        $characterId = AuthGuard::html()->requireCharacter();

        $presence->touchCharacter((int) $characterId);

        return AppContext::templateRenderer()->render('app/bank.twig');
    });
});

$route->group('/game/forum', function ($route) use ($presence) {
    $route->get('/', function () use ($presence) {
        $characterId = AuthGuard::html()->requireCharacter();

        $presence->touchCharacter((int) $characterId);

        return AppContext::templateRenderer()->render('app/forum.twig');
    });

    $route->get('/{forumId}', function ($forum_id) use ($presence) {
        $characterId = AuthGuard::html()->requireCharacter();

        $presence->touchCharacter((int) $characterId);

        return AppContext::templateRenderer()->render('app/threads.twig', ['forum_id' => $forum_id]);
    });

    $route->get('/{forumId}/thread/{threadId}', function ($forum_id, $thread_id) use ($presence) {
        $characterId = AuthGuard::html()->requireCharacter();

        $presence->touchCharacter((int) $characterId);

        return AppContext::templateRenderer()->render('app/thread.twig', ['forum_id' => $forum_id, 'thread_id' => $thread_id]);
    });

    $route->apiPost('/{forumId}/thread/{threadId}/answers', 'Threads@list');

    $route->apiPost('/thread/create', 'Threads@create');
    $route->apiPost('/thread/answer', 'Threads@answer');
    $route->apiPost('/thread/update', 'Threads@update');

    $route->apiPost('/thread/delete', 'Threads@delete');
    $route->apiPost('/thread/move', 'Threads@move');

    $route->apiPost('/thread/set/important', 'Threads@important');
    $route->apiPost('/thread/set/common', 'Threads@common');
    $route->apiPost('/thread/set/lock', 'Threads@lock');
    $route->apiPost('/thread/set/unlock', 'Threads@unlock');
});
