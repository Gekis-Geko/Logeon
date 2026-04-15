USE appdb;

SET FOREIGN_KEY_CHECKS = 0;

SET @admin_user_id := (
    SELECT id
    FROM users
    WHERE is_superuser = 1
    ORDER BY id ASC
    LIMIT 1
);
SET @admin_user_id := IFNULL(@admin_user_id, 1);

DELETE FROM users WHERE id <> @admin_user_id;
DELETE FROM characters WHERE user_id <> @admin_user_id;

SET @admin_char_id := (
    SELECT id
    FROM characters
    WHERE user_id = @admin_user_id
    ORDER BY id ASC
    LIMIT 1
);

DELETE FROM characters WHERE id <> @admin_char_id;

UPDATE characters
SET
    last_map = 0,
    last_location = 0,
    health = health_max,
    experience = 0,
    rank = 1,
    money = 0,
    bank = 0,
    fame = 0,
    date_last_signin = NULL,
    date_last_signout = NULL,
    delete_requested_at = NULL,
    delete_scheduled_at = NULL
WHERE id = @admin_char_id;

TRUNCATE TABLE sys_logs;
TRUNCATE TABLE module_runtime_artifacts;
TRUNCATE TABLE notifications;
TRUNCATE TABLE location_access_logs;
TRUNCATE TABLE locations_messages;
TRUNCATE TABLE messages;
TRUNCATE TABLE messages_threads;
TRUNCATE TABLE message_reports;
TRUNCATE TABLE location_item_drops;
TRUNCATE TABLE location_invites;
TRUNCATE TABLE location_weather_overrides;
TRUNCATE TABLE location_whisper_reads;
TRUNCATE TABLE character_whisper_policies;
TRUNCATE TABLE password_resets;
TRUNCATE TABLE upload_chunks;
TRUNCATE TABLE uploads;
TRUNCATE TABLE blacklist;

TRUNCATE TABLE currency_logs;
TRUNCATE TABLE experience_logs;
TRUNCATE TABLE fame_logs;
TRUNCATE TABLE job_logs;
TRUNCATE TABLE shop_purchases;
TRUNCATE TABLE shop_sales;

TRUNCATE TABLE conflicts;
TRUNCATE TABLE conflict_participants;
TRUNCATE TABLE conflict_actions;
TRUNCATE TABLE conflict_action_targets;
TRUNCATE TABLE conflict_roll_log;

TRUNCATE TABLE system_events;
TRUNCATE TABLE system_event_effects;
TRUNCATE TABLE system_event_participations;
TRUNCATE TABLE system_event_reward_logs;
TRUNCATE TABLE system_event_quest_links;

TRUNCATE TABLE quest_instances;
TRUNCATE TABLE quest_step_instances;
TRUNCATE TABLE quest_progress_logs;
TRUNCATE TABLE quest_closure_reports;
TRUNCATE TABLE quest_reward_assignments;

TRUNCATE TABLE character_equipment;
TRUNCATE TABLE character_item_instances;
TRUNCATE TABLE inventory_items;
TRUNCATE TABLE character_jobs;
TRUNCATE TABLE character_job_tasks;
TRUNCATE TABLE character_events;
TRUNCATE TABLE character_lifecycle_transitions;
TRUNCATE TABLE character_archetypes;
TRUNCATE TABLE character_attribute_values;
TRUNCATE TABLE character_wallets;
TRUNCATE TABLE character_bond_requests;
TRUNCATE TABLE character_bond_events;
TRUNCATE TABLE character_identity_requests;
TRUNCATE TABLE character_loanface_requests;
TRUNCATE TABLE character_name_requests;

TRUNCATE TABLE faction_memberships;
TRUNCATE TABLE faction_relationships;
TRUNCATE TABLE guild_members;
TRUNCATE TABLE guild_logs;
TRUNCATE TABLE guild_announcements;
TRUNCATE TABLE guild_events;
TRUNCATE TABLE guild_applications;
TRUNCATE TABLE guild_kick_requests;

TRUNCATE TABLE forum_threads;
TRUNCATE TABLE narrative_events;
TRUNCATE TABLE weather_overrides;

INSERT INTO character_wallets (character_id, currency_id, balance)
SELECT @admin_char_id, c.id, 0
FROM currencies c
WHERE @admin_char_id IS NOT NULL;

SET FOREIGN_KEY_CHECKS = 1;
