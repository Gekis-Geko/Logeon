<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database\DbAdapterFactory;
use Core\Database\DbAdapterInterface;

class JobService
{
    /** @var DbAdapterInterface */
    private $db;

    public function __construct(DbAdapterInterface $db = null)
    {
        $this->db = $db ?: DbAdapterFactory::createFromConfig();
    }

    private function firstPrepared(string $sql, array $params = [])
    {
        return $this->db->fetchOnePrepared($sql, $params);
    }

    private function fetchPrepared(string $sql, array $params = []): array
    {
        return $this->db->fetchAllPrepared($sql, $params);
    }

    private function execPrepared(string $sql, array $params = []): void
    {
        $this->db->executePrepared($sql, $params);
    }

    public function getTaskUnlockAt($jobAssignedAt)
    {
        $raw = trim((string) $jobAssignedAt);
        if ($raw === '') {
            return null;
        }

        $baseTs = strtotime($raw);
        if ($baseTs === false) {
            return null;
        }

        return date('Y-m-d H:i:s', strtotime('+24 hours', $baseTs));
    }

    public function formatDateTimeIt($dateTime)
    {
        $raw = trim((string) $dateTime);
        if ($raw === '') {
            return null;
        }

        $ts = strtotime($raw);
        if ($ts === false) {
            return null;
        }

        return date('d/m/Y H:i', $ts);
    }

    public function isTaskCooldownActive($jobAssignedAt): bool
    {
        $unlockAt = $this->getTaskUnlockAt($jobAssignedAt);
        if ($unlockAt === null) {
            return false;
        }

        $unlockTs = strtotime($unlockAt);
        if ($unlockTs === false) {
            return false;
        }

        return time() < $unlockTs;
    }

    public function isCharacterBlockedFromJobsByGuild(int $characterId): bool
    {
        if ($characterId <= 0) {
            return false;
        }

        $row = $this->firstPrepared(
            'SELECT 1
             FROM guild_members gm
             INNER JOIN guild_requirements gr ON gr.guild_id = gm.guild_id
             WHERE gm.character_id = ?
               AND gr.type = "no_job"
             LIMIT 1',
            [$characterId],
        );

        return !empty($row);
    }

    public function getCharacter($characterId)
    {
        $characterId = (int) $characterId;
        if ($characterId <= 0) {
            return null;
        }

        $character = $this->firstPrepared(
            'SELECT id, money, fame, socialstatus_id, last_location
            FROM characters
            WHERE id = ?',
            [$characterId],
        );

        return !empty($character) ? $character : null;
    }

    public function listAdminJobs(): array
    {
        $rows = $this->fetchPrepared(
            'SELECT j.id, j.name, j.location_id, j.is_active,
                l.name AS location_name
             FROM jobs j
             LEFT JOIN locations l ON j.location_id = l.id
             ORDER BY j.name ASC',
        );

        return !empty($rows) ? $rows : [];
    }

    public function listAvailableJobs(?int $locationId = null): array
    {
        $where = ' WHERE j.is_active = 1';
        $params = [];
        if (!empty($locationId)) {
            $where .= ' AND j.location_id = ?';
            $params[] = (int) $locationId;
        }

        $rows = $this->fetchPrepared(
            'SELECT j.*,
                l.name AS location_name
             FROM jobs j
             LEFT JOIN locations l ON j.location_id = l.id
             ' . $where . '
             ORDER BY j.name ASC',
            $params,
        );

        if (!empty($rows)) {
            foreach ($rows as $row) {
                $status = (int) ($row->min_socialstatus_id ?? 0) > 0
                    ? SocialStatusProviderRegistry::getById((int) $row->min_socialstatus_id)
                    : null;
                $row->required_status_name = $status->name ?? null;
                $row->required_status_min  = $status->min ?? null;
            }
        }

        return !empty($rows) ? $rows : [];
    }

    public function getActiveJobById($jobId)
    {
        $jobId = (int) $jobId;
        if ($jobId <= 0) {
            return null;
        }

        $row = $this->firstPrepared(
            'SELECT j.*
             FROM jobs j
             WHERE j.id = ? AND j.is_active = 1',
            [$jobId],
        );

        if (!empty($row)) {
            $status = (int) ($row->min_socialstatus_id ?? 0) > 0
                ? SocialStatusProviderRegistry::getById((int) $row->min_socialstatus_id)
                : null;
            $row->required_status_name = $status->name ?? null;
            $row->required_status_min  = $status->min ?? null;
        }

        return !empty($row) ? $row : null;
    }

    public function findCharacterJobByJob($characterId, $jobId)
    {
        $characterId = (int) $characterId;
        $jobId = (int) $jobId;
        if ($characterId <= 0 || $jobId <= 0) {
            return null;
        }

        $row = $this->firstPrepared(
            'SELECT id, job_id
             FROM character_jobs
             WHERE character_id = ?
               AND job_id = ?',
            [$characterId, $jobId],
        );

        return !empty($row) ? $row : null;
    }

    public function deactivateCharacterJobs($characterId): void
    {
        $characterId = (int) $characterId;
        if ($characterId <= 0) {
            return;
        }

        $this->execPrepared(
            'UPDATE character_jobs
             SET is_active = 0
             WHERE character_id = ?',
            [$characterId],
        );
    }

    public function leaveCurrentJob($characterId): void
    {
        $this->deactivateCharacterJobs($characterId);
    }

    public function reactivateCharacterJob($characterJobId): void
    {
        $characterJobId = (int) $characterJobId;
        if ($characterJobId <= 0) {
            return;
        }

        $this->execPrepared(
            'UPDATE character_jobs SET
                is_active = 1,
                date_assigned = NOW(),
                date_updated = NOW()
             WHERE id = ?',
            [$characterJobId],
        );
    }

    public function createCharacterJob($characterId, $jobId, $startLevel): void
    {
        $characterId = (int) $characterId;
        $jobId = (int) $jobId;
        $startLevel = (int) $startLevel;
        if ($characterId <= 0 || $jobId <= 0) {
            return;
        }
        if ($startLevel <= 0) {
            $startLevel = 1;
        }

        $this->execPrepared(
            'INSERT INTO character_jobs (character_id, job_id, level, points, is_active, date_assigned, date_updated)
             VALUES (?, ?, ?, 0, 1, NOW(), NOW())',
            [$characterId, $jobId, $startLevel],
        );
    }

    public function getCurrentActiveCharacterJob($characterId)
    {
        $characterId = (int) $characterId;
        if ($characterId <= 0) {
            return null;
        }

        $row = $this->firstPrepared(
            'SELECT cj.id AS character_job_id,
                cj.job_id,
                cj.level,
                cj.points,
                cj.date_assigned,
                j.name,
                j.description,
                j.location_id,
                j.min_socialstatus_id,
                j.daily_tasks,
                j.base_pay,
                l.name AS location_name
             FROM character_jobs cj
             LEFT JOIN jobs j ON cj.job_id = j.id
             LEFT JOIN locations l ON j.location_id = l.id
             WHERE cj.character_id = ?
               AND cj.is_active = 1
             LIMIT 1',
            [$characterId],
        );

        return !empty($row) ? $row : null;
    }

    public function getTaskAssignmentForCharacter($assignmentId, $characterId)
    {
        $assignmentId = (int) $assignmentId;
        $characterId = (int) $characterId;
        if ($assignmentId <= 0 || $characterId <= 0) {
            return null;
        }

        $row = $this->firstPrepared(
            'SELECT cjt.id,
                cjt.status,
                cjt.task_id,
                cjt.character_job_id,
                cjt.assigned_date,
                cj.character_id,
                cj.job_id,
                cj.level,
                cj.points,
                cj.date_assigned AS job_date_assigned,
                jt.title,
                jt.requires_location_id,
                l.map_id AS requires_map_id,
                l.name AS requires_location_name
             FROM character_job_tasks cjt
             LEFT JOIN character_jobs cj ON cjt.character_job_id = cj.id
             LEFT JOIN job_tasks jt ON cjt.task_id = jt.id
             LEFT JOIN locations l ON jt.requires_location_id = l.id
             WHERE cjt.id = ?
               AND cj.character_id = ?
               AND cj.is_active = 1
               AND cjt.assigned_date = CURDATE()',
            [$assignmentId, $characterId],
        );

        return !empty($row) ? $row : null;
    }

    public function getTaskChoice($choiceId, $taskId)
    {
        $choiceId = (int) $choiceId;
        $taskId = (int) $taskId;
        if ($choiceId <= 0 || $taskId <= 0) {
            return null;
        }

        $row = $this->firstPrepared(
            'SELECT id, task_id, choice_code, label, pay, fame, points
             FROM job_task_choices
             WHERE id = ? AND task_id = ?',
            [$choiceId, $taskId],
        );

        return !empty($row) ? $row : null;
    }

    public function applyTaskCompletion(
        int $characterId,
        $assignment,
        $choice,
        float $pay,
        float $fame,
        int $points,
        float $fameBefore,
        float $fameAfter,
    ): void {
        if ($characterId <= 0 || empty($assignment) || empty($choice)) {
            return;
        }

        $this->execPrepared(
            'UPDATE characters SET
                money = money + ?,
                fame = fame + ?
             WHERE id = ?',
            [$pay, $fame, $characterId],
        );

        $this->execPrepared(
            'UPDATE character_job_tasks SET
                status = "completed",
                choice_id = ?,
                pay = ?,
                fame = ?,
                points = ?,
                date_completed = NOW()
             WHERE id = ?',
            [$choice->id, $pay, $fame, $points, $assignment->id],
        );

        $this->execPrepared(
            'UPDATE character_jobs SET
                points = points + ?,
                date_updated = NOW()
             WHERE id = ?',
            [$points, $assignment->character_job_id],
        );

        $this->execPrepared(
            'INSERT INTO job_logs (character_id, job_id, task_id, choice_id, assigned_date, pay, fame, points, date_created)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())',
            [$characterId, $assignment->job_id, $assignment->task_id, $choice->id, $assignment->assigned_date, $pay, $fame, $points],
        );

        if ($fame != 0) {
            $this->execPrepared(
                'INSERT INTO fame_logs (character_id, fame_before, fame_after, delta, reason, source, author_id, date_created)
                 VALUES (?, ?, ?, ?, ?, "job", NULL, NOW())',
                [$characterId, $fameBefore, $fameAfter, $fame, 'Lavoro: ' . $assignment->title],
            );
        }
    }

    public function getCharacterJob($characterJobId)
    {
        $characterJobId = (int) $characterJobId;
        if ($characterJobId <= 0) {
            return null;
        }

        $job = $this->firstPrepared(
            'SELECT cj.id AS character_job_id,
                cj.job_id,
                cj.level,
                cj.points,
                cj.date_assigned,
                j.name,
                j.description,
                j.location_id,
                j.min_socialstatus_id,
                j.daily_tasks,
                j.base_pay,
                l.name AS location_name
            FROM character_jobs cj
            LEFT JOIN jobs j ON cj.job_id = j.id
            LEFT JOIN locations l ON j.location_id = l.id
            WHERE cj.id = ?
            LIMIT 1',
            [$characterJobId],
        );

        if (!empty($job)) {
            $levelRow = $this->firstPrepared(
                'SELECT level, title, min_points, pay_bonus_percent FROM job_levels
                WHERE job_id = ?
                  AND level = ?',
                [$job->job_id, $job->level],
            );
            if (!empty($levelRow)) {
                $job->level_title = $levelRow->title;
                $job->pay_bonus_percent = (int) $levelRow->pay_bonus_percent;
                $job->current_level_min_points = (int) $levelRow->min_points;
            } else {
                $job->level_title = null;
                $job->pay_bonus_percent = 0;
                $job->current_level_min_points = 0;
            }

            $nextLevelRow = $this->firstPrepared(
                'SELECT level, title, min_points, pay_bonus_percent
                 FROM job_levels
                 WHERE job_id = ?
                   AND level > ?
                 ORDER BY level ASC
                 LIMIT 1',
                [$job->job_id, $job->level],
            );

            $points = isset($job->points) ? (int) $job->points : 0;
            $currentMinPoints = isset($job->current_level_min_points) ? (int) $job->current_level_min_points : 0;
            $job->next_level = null;
            $job->next_level_title = null;
            $job->next_level_min_points = null;
            $job->next_level_pay_bonus_percent = null;
            $job->points_to_next_level = 0;
            $job->progress_percent = 100;
            $job->tasks_unlock_at = $this->getTaskUnlockAt($job->date_assigned ?? null);
            $job->tasks_locked = $this->isTaskCooldownActive($job->date_assigned ?? null) ? 1 : 0;

            if (!empty($nextLevelRow)) {
                $nextLevel = (int) $nextLevelRow->level;
                $nextMinPoints = (int) $nextLevelRow->min_points;
                $required = $nextMinPoints - $points;
                $required = ($required > 0) ? $required : 0;

                $range = $nextMinPoints - $currentMinPoints;
                if ($range > 0) {
                    $progressRaw = (($points - $currentMinPoints) / $range) * 100;
                    $progress = (int) round($progressRaw);
                    if ($progress < 0) {
                        $progress = 0;
                    }
                    if ($progress > 100) {
                        $progress = 100;
                    }
                    $job->progress_percent = $progress;
                } else {
                    $job->progress_percent = 100;
                }

                $job->next_level = $nextLevel;
                $job->next_level_title = $nextLevelRow->title;
                $job->next_level_min_points = $nextMinPoints;
                $job->next_level_pay_bonus_percent = (int) $nextLevelRow->pay_bonus_percent;
                $job->points_to_next_level = $required;
            }
        }

        return $job;
    }

    public function checkJobRequirements($job, $character): array
    {
        $result = [
            'allowed' => true,
            'reason' => null,
        ];

        if (empty($job) || empty($character)) {
            $result['allowed'] = false;
            $result['reason'] = 'Requisiti non soddisfatti';
            return $result;
        }

        if (!empty($job->min_socialstatus_id)) {
            $characterId = isset($character->id) ? (int) $character->id : 0;
            $requiredStatusId = (int) $job->min_socialstatus_id;
            $meetsStatus = SocialStatusProviderRegistry::meetsRequirement(
                $characterId,
                $requiredStatusId > 0 ? $requiredStatusId : null,
            );
            if (!$meetsStatus) {
                $result['allowed'] = false;
                $result['reason'] = 'Stato sociale insufficiente';
            }
        }

        return $result;
    }

    public function getJobStartLevel($jobId): int
    {
        $jobId = (int) $jobId;
        if ($jobId <= 0) {
            return 1;
        }

        $row = $this->firstPrepared(
            'SELECT level FROM job_levels
            WHERE job_id = ?
            ORDER BY level ASC
            LIMIT 1',
            [$jobId],
        );

        if (!empty($row) && isset($row->level)) {
            return (int) $row->level;
        }

        return 1;
    }

    public function syncLevel($characterJobId, $jobId): void
    {
        $characterJobId = (int) $characterJobId;
        $jobId = (int) $jobId;
        if ($characterJobId <= 0 || $jobId <= 0) {
            return;
        }

        $row = $this->firstPrepared(
            'SELECT level, points FROM character_jobs WHERE id = ?',
            [$characterJobId],
        );
        if (empty($row)) {
            return;
        }

        $levelRow = $this->firstPrepared(
            'SELECT level
            FROM job_levels
            WHERE job_id = ?
              AND min_points <= ?
            ORDER BY level DESC
            LIMIT 1',
            [$jobId, $row->points],
        );

        if (!empty($levelRow) && (int) $levelRow->level !== (int) $row->level) {
            $this->execPrepared(
                'UPDATE character_jobs SET level = ?, date_updated = NOW()
                WHERE id = ?',
                [$levelRow->level, $characterJobId],
            );
        }
    }

    public function getLevelBonus($jobId, $level): float
    {
        $jobId = (int) $jobId;
        $level = (int) $level;
        if ($jobId <= 0 || $level <= 0) {
            return 0.0;
        }

        $row = $this->firstPrepared(
            'SELECT pay_bonus_percent
            FROM job_levels
            WHERE job_id = ?
              AND level = ?
            LIMIT 1',
            [$jobId, $level],
        );

        return (!empty($row) && isset($row->pay_bonus_percent)) ? (float) $row->pay_bonus_percent : 0.0;
    }

    public function expireOldTasks($characterJobId): void
    {
        $characterJobId = (int) $characterJobId;
        if ($characterJobId <= 0) {
            return;
        }

        $this->execPrepared(
            'UPDATE character_job_tasks SET status = "expired"
            WHERE character_job_id = ?
              AND assigned_date < CURDATE()
              AND status = "pending"',
            [$characterJobId],
        );
    }

    public function ensureDailyTasks($job, $character): void
    {
        if (empty($job) || empty($character)) {
            return;
        }

        $daily = isset($job->daily_tasks) ? (int) $job->daily_tasks : 0;
        if ($daily < 1) {
            return;
        }

        $countRow = $this->firstPrepared(
            'SELECT COUNT(*) AS total
            FROM character_job_tasks
            WHERE character_job_id = ?
              AND assigned_date = CURDATE()',
            [$job->character_job_id],
        );
        $existing = !empty($countRow) ? (int) $countRow->total : 0;
        $needed = $daily - $existing;

        if ($needed <= 0) {
            return;
        }

        $tasks = $this->fetchPrepared(
            'SELECT id
            FROM job_tasks
            WHERE job_id = ?
              AND is_active = 1
              AND min_level <= ?
              AND id NOT IN (
                  SELECT task_id
                  FROM character_job_tasks
                  WHERE character_job_id = ?
                    AND assigned_date = CURDATE()
              )
            ORDER BY RAND()
            LIMIT ?',
            [$job->job_id, $job->level, $job->character_job_id, $needed],
        );

        if (empty($tasks)) {
            return;
        }

        foreach ($tasks as $task) {
            $this->execPrepared(
                'INSERT IGNORE INTO character_job_tasks (character_job_id, task_id, assigned_date, status, date_completed)
                 VALUES (?, ?, CURDATE(), "pending", NULL)',
                [$job->character_job_id, $task->id],
            );
        }
    }

    public function getRecentCompletions($characterJobId, int $limit = 5): array
    {
        $characterJobId = (int) $characterJobId;
        if ($characterJobId <= 0) {
            return [];
        }

        if ($limit < 1) {
            $limit = 1;
        }
        if ($limit > 20) {
            $limit = 20;
        }

        $rows = $this->fetchPrepared(
            'SELECT cjt.id,
                cjt.assigned_date,
                cjt.date_completed,
                cjt.pay,
                cjt.fame,
                cjt.points,
                jt.title,
                jtc.label AS choice_label
             FROM character_job_tasks cjt
            LEFT JOIN character_jobs cj ON cjt.character_job_id = cj.id
             LEFT JOIN job_tasks jt ON cjt.task_id = jt.id
             LEFT JOIN job_task_choices jtc ON cjt.choice_id = jtc.id
             WHERE cjt.character_job_id = ?
               AND cjt.status = "completed"
             ORDER BY COALESCE(cjt.date_completed, CONCAT(cjt.assigned_date, " 00:00:00")) DESC, cjt.id DESC
             LIMIT ?',
            [$characterJobId, $limit],
        );

        return !empty($rows) ? $rows : [];
    }

    public function getDailyTasks($characterJobId, $character): array
    {
        $characterJobId = (int) $characterJobId;
        if ($characterJobId <= 0 || empty($character)) {
            return [];
        }

        $tasks = $this->fetchPrepared(
            'SELECT cjt.id AS assignment_id,
                cjt.status,
                cjt.choice_id,
                cjt.pay,
                cjt.fame,
                cjt.points,
                cjt.assigned_date,
                cjt.date_completed,
                cj.date_assigned AS job_date_assigned,
                jt.id AS task_id,
                jt.title,
                jt.body,
                jt.requires_location_id,
                l.map_id AS requires_map_id,
                l.name AS requires_location_name
            FROM character_job_tasks cjt
            LEFT JOIN character_jobs cj ON cjt.character_job_id = cj.id
            LEFT JOIN job_tasks jt ON cjt.task_id = jt.id
            LEFT JOIN locations l ON jt.requires_location_id = l.id
            WHERE cjt.character_job_id = ?
              AND cjt.assigned_date = CURDATE()
            ORDER BY cjt.id ASC',
            [$characterJobId],
        );

        if (empty($tasks)) {
            return [];
        }

        $taskIds = [];
        foreach ($tasks as $task) {
            $taskIds[] = (int) $task->task_id;
            $task->choices = [];
            $task->can_complete = true;
            $task->tasks_unlock_at = $this->getTaskUnlockAt($task->job_date_assigned ?? null);
            $task->lock_reason = null;

            if ($this->isTaskCooldownActive($task->job_date_assigned ?? null)) {
                $task->can_complete = false;
                if (!empty($task->tasks_unlock_at)) {
                    $unlockAtLabel = $this->formatDateTimeIt($task->tasks_unlock_at);
                    if (!empty($unlockAtLabel)) {
                        $task->lock_reason = 'Disponibile dal ' . $unlockAtLabel;
                    } else {
                        $task->lock_reason = 'Disponibile dal ' . $task->tasks_unlock_at;
                    }
                } else {
                    $task->lock_reason = 'Disponibile dopo 24 ore dalla scelta del lavoro';
                }
            }

            if ($task->can_complete && !empty($task->requires_location_id)) {
                $task->can_complete = ((int) $character->last_location === (int) $task->requires_location_id);
            }
        }

        $taskIds = array_values(array_unique($taskIds));
        $placeholders = implode(',', array_fill(0, count($taskIds), '?'));
        $choices = $this->fetchPrepared(
            'SELECT id, task_id, choice_code, label, pay, fame, points
            FROM job_task_choices
            WHERE task_id IN (' . $placeholders . ')
            ORDER BY id ASC',
            $taskIds,
        );

        if (!empty($choices)) {
            $map = [];
            foreach ($choices as $choice) {
                $map[$choice->task_id][] = $choice;
            }

            foreach ($tasks as $task) {
                $task->choices = $map[$task->task_id] ?? [];
            }
        }

        return $tasks;
    }
}
