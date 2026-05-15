<?php

declare(strict_types=1);

namespace App\Services\Update;

use Core\Http\AppError;

class UpdateRollbackService
{
    /** @var UpdateRuntime */
    private $runtime;
    /** @var UpdateLogService */
    private $logService;

    public function __construct(UpdateRuntime $runtime = null, UpdateLogService $logService = null)
    {
        $this->runtime = $runtime ?: new UpdateRuntime();
        $this->logService = $logService ?: new UpdateLogService();
    }

    /**
     * @return array<string,mixed>
     */
    public function recoveryGuide(bool $clearLocks = false): array
    {
        $backups = $this->listBackups();
        $lock = $this->runtime->lockState();
        $maintenance = $this->runtime->maintenanceState();

        if ($clearLocks) {
            $this->runtime->releaseLock();
            $this->runtime->disableMaintenance();
            $lock = null;
            $maintenance = null;
            $this->logService->logEvent(null, 'warning', 'rollback_manual_unlock', 'Lock/maintenance rimossi manualmente');
        }

        return [
            'mode' => 'guided',
            'lock' => $lock,
            'maintenance' => $maintenance,
            'backups' => $backups,
            'instructions' => [
                'Se l\'update fallisce, usa il backup più recente in storage/backups/updates.',
                'Ripristina db.sql sul database corrente e files.zip sui file applicativi.',
                'Verifica configs/distribution.php e logeon.manifest.json dopo il ripristino.',
                'Solo quando il runtime è ripristinato rimuovi lock e maintenance.',
            ],
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function listBackups(): array
    {
        $root = $this->runtime->backupsRoot();
        if (!is_dir($root)) {
            return [];
        }

        $dirs = glob($root . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR);
        if ($dirs === false || empty($dirs)) {
            return [];
        }
        rsort($dirs, SORT_STRING);

        $dataset = [];
        foreach ($dirs as $dir) {
            $id = basename($dir);
            $db = $dir . DIRECTORY_SEPARATOR . 'db.sql';
            $files = $dir . DIRECTORY_SEPARATOR . 'files.zip';
            $meta = $dir . DIRECTORY_SEPARATOR . 'update-before.json';

            $metaPayload = $this->runtime->readJsonFile($meta);
            $dataset[] = [
                'backup_id' => $id,
                'path' => $dir,
                'has_db_dump' => is_file($db),
                'has_files_zip' => is_file($files),
                'meta' => is_array($metaPayload) ? $metaPayload : null,
            ];
        }

        return $dataset;
    }
}

