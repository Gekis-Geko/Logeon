<?php

declare(strict_types=1);

namespace Core;

use Core\Database\DbAdapterFactory;
use Core\Database\DbAdapterInterface;
use Core\Http\ApiResponse;
use Core\Http\AppError;
use Core\Http\RequestData;
use Core\Http\ResponseEmitter;
use Core\Services\UploadPaths;

class UploadManager
{
    protected static $default_max_mb = 5;
    protected static $cleanup_hours = 24;
    protected static $target_limits_mb = [
        'avatar' => 2,
        'richtext_image' => 5,
        'background_music_url' => 10,
        'sound_dm' => 10,
        'sound_notifications' => 10,
        'sound_whispers' => 10,
        'sound_global' => 10,
    ];
    protected static $allowed_mime = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'audio/mpeg' => 'mp3',
        'audio/mp3' => 'mp3',
        'audio/ogg' => 'ogg',
        'audio/wav' => 'wav',
        'audio/x-wav' => 'wav',
        'audio/aac' => 'aac',
        'audio/mp4' => 'm4a',
        'audio/x-m4a' => 'm4a',
        'audio/webm' => 'webm',
    ];
    protected static $target_allowed_mime = [
        'avatar' => ['image/jpeg', 'image/png', 'image/gif'],
        'richtext_image' => ['image/jpeg', 'image/png', 'image/gif'],
        'background_music_url' => ['audio/mpeg', 'audio/mp3', 'audio/ogg', 'audio/wav', 'audio/x-wav', 'audio/aac', 'audio/mp4', 'audio/x-m4a', 'audio/webm'],
        'sound_dm' => ['audio/mpeg', 'audio/mp3', 'audio/ogg', 'audio/wav', 'audio/x-wav', 'audio/aac', 'audio/mp4', 'audio/x-m4a', 'audio/webm'],
        'sound_notifications' => ['audio/mpeg', 'audio/mp3', 'audio/ogg', 'audio/wav', 'audio/x-wav', 'audio/aac', 'audio/mp4', 'audio/x-m4a', 'audio/webm'],
        'sound_whispers' => ['audio/mpeg', 'audio/mp3', 'audio/ogg', 'audio/wav', 'audio/x-wav', 'audio/aac', 'audio/mp4', 'audio/x-m4a', 'audio/webm'],
        'sound_global' => ['audio/mpeg', 'audio/mp3', 'audio/ogg', 'audio/wav', 'audio/x-wav', 'audio/aac', 'audio/mp4', 'audio/x-m4a', 'audio/webm'],
    ];
    private static $dbAdapter = null;
    private static $paths = null;

    public static function setDbAdapter(DbAdapterInterface $adapter = null): void
    {
        self::$dbAdapter = $adapter;
    }

    private static function db(): DbAdapterInterface
    {
        if (self::$dbAdapter instanceof DbAdapterInterface) {
            return self::$dbAdapter;
        }

        self::$dbAdapter = DbAdapterFactory::createFromConfig();
        return self::$dbAdapter;
    }

    public static function setPaths(UploadPaths $paths = null): void
    {
        self::$paths = $paths;
    }

    private static function paths(): UploadPaths
    {
        if (self::$paths instanceof UploadPaths) {
            return self::$paths;
        }

        self::$paths = new UploadPaths();
        return self::$paths;
    }

    public static function execute(RequestData $request = null)
    {
        $request = $request ?? RequestData::fromGlobals();
        $action = trim((string) $request->query('action', ''));
        switch ($action) {
            case 'uploadStart':
                return static::uploadStart($request);
            case 'uploadChunk':
                return static::uploadChunk($request);
            case 'uploadCheck':
                return static::uploadCheck($request);
            case 'uploadCancel':
                return static::uploadCancel($request);
            case 'uploadFinalize':
                return static::uploadFinalize($request);
            default:
                static::failValidation('Comando non riconosciuto');
        }

        return [];
    }

    protected static function requireUserCharacterIds(): array
    {
        $guard = AuthGuard::api();
        $userId = $guard->requireUser();
        $characterId = $guard->requireCharacter();

        return [
            'user_id' => $userId,
            'character_id' => $characterId,
        ];
    }

    protected static function uploadStart(RequestData $request)
    {
        static::cleanupOldUploads();
        $data = $request->postJson('data', [], true);
        if (!is_array($data)) {
            static::failValidation('Dati non validi');
        }

        $name = trim($data['name'] ?? '');
        $size = intval($data['size'] ?? 0);
        $type = trim($data['type'] ?? '');
        $hash = trim($data['hash'] ?? '');
        $chunks = $data['chunks'] ?? [];
        $chunks_total = intval($data['chunks_total'] ?? 0);
        $chunk_size = intval($data['chunk_size'] ?? 0);
        $target = isset($data['target']) ? trim($data['target']) : '';

        if ($name === '') {
            static::failValidation('Nome file mancante');
        }
        if ($hash === '') {
            static::failValidation('Hash file mancante');
        }
        if ($size <= 0) {
            static::failValidation('Dimensione file non valida');
        }
        $maxSize = static::getMaxFileSize();
        $targetMax = static::getTargetMaxFileSize($target);
        if ($targetMax !== null && $targetMax < $maxSize) {
            $maxSize = $targetMax;
        }
        if (!empty($maxSize) && $size > $maxSize) {
            static::failValidation('File troppo grande');
        }
        if ($type === '' || !isset(static::$allowed_mime[$type])) {
            static::failValidation('Formato non supportato');
        }
        if ($target !== '' && isset(static::$target_allowed_mime[$target]) && !in_array($type, static::$target_allowed_mime[$target])) {
            static::failValidation('Formato non supportato per questo tipo di upload');
        }
        if (!is_array($chunks) || count($chunks) === 0) {
            static::failValidation('Chunks non validi');
        }
        if ($chunks_total <= 0) {
            $chunks_total = count($chunks);
        }
        if ($chunk_size <= 0) {
            static::failValidation('Dimensione chunk non valida');
        }

        $ids = static::requireUserCharacterIds();
        $user_id = $ids['user_id'];
        $character_id = $ids['character_id'];

        $existing = static::findExistingUpload($hash, $type, $character_id);
        $existingPath = !empty($existing->final_path) ? static::normalizeFsPath($existing->final_path) : '';
        if (!empty($existing->token) && $existingPath !== '' && file_exists($existingPath)) {
            $url = static::getPublicUrlFromPath($existingPath);
            if ($url !== null) {
                return static::response([
                    'token' => $existing->token,
                    'completed' => true,
                    'url' => $url,
                    'max_mb' => intval($maxSize / (1024 * 1024)),
                    'max_bytes' => $maxSize,
                ]);
            }
        }

        $token = static::generateToken();

        $db = self::db();
        $db->executePrepared(
            'INSERT INTO uploads (
                token, user_id, character_id, file_name, file_size, mime_type, file_hash,
                chunk_size, chunks_total, chunks_received, status
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0)',
            [$token, (int) $user_id, (int) $character_id, $name, (int) $size, $type, $hash, (int) $chunk_size, (int) $chunks_total],
        );

        $upload_id = (int) $db->lastInsertId();
        if ($upload_id <= 0) {
            static::failValidation('Errore creazione upload');
        }

        foreach ($chunks as $chunk) {
            $chunk_id = isset($chunk['chunk_id']) ? intval($chunk['chunk_id']) : (isset($chunk['chunkIndex']) ? intval($chunk['chunkIndex']) : -1);
            $chunk_hash = trim($chunk['hash'] ?? '');
            $chunk_bytes = intval($chunk['size'] ?? 0);
            if ($chunk_id < 0 || $chunk_hash === '' || $chunk_bytes <= 0) {
                static::failValidation('Chunk non valido');
            }
            $db->executePrepared(
                'INSERT INTO upload_chunks (upload_id, chunk_index, chunk_hash, chunk_size, received, received_bytes)
                 VALUES (?, ?, ?, ?, 0, 0)',
                [$upload_id, $chunk_id, $chunk_hash, $chunk_bytes],
            );
        }
        if (count($chunks) !== $chunks_total) {
            static::failValidation('Numero chunk non valido');
        }

        static::ensureDir(static::getChunkDir($token));

        return static::response([
            'token' => $token,
            'chunks_total' => $chunks_total,
            'chunk_size' => $chunk_size,
            'max_mb' => intval($maxSize / (1024 * 1024)),
            'max_bytes' => $maxSize,
        ]);
    }

    protected static function uploadChunk(RequestData $request)
    {
        $token = trim((string) $request->query('token', ''));
        $chunk_id = intval($request->query('chunk_id', -1));
        if ($token === '' || $chunk_id < 0) {
            static::failValidation('Parametri mancanti');
        }

        $upload = static::getUploadByToken($token);
        if (empty($upload->id)) {
            static::failValidation('Upload non trovato');
        }

        $chunk = static::getChunk($upload->id, $chunk_id);
        if (empty($chunk->id)) {
            static::failValidation('Chunk non trovato');
        }

        $chunk_data = $request->rawBody();
        if ($chunk_data === '') {
            $chunk_data = file_get_contents('php://input');
        }
        if ($chunk_data === false || $chunk_data === '') {
            static::failValidation('Chunk vuoto');
        }

        $hash = hash('sha256', $chunk_data);
        if ($hash !== $chunk->chunk_hash) {
            static::failValidation('Hash chunk non valido');
        }

        $chunk_dir = static::getChunkDir($token);
        static::ensureDir($chunk_dir);
        $chunk_path = $chunk_dir . '/' . $chunk_id;
        file_put_contents($chunk_path . '.partial', $chunk_data);
        @rename($chunk_path . '.partial', $chunk_path);

        if (intval($chunk->received) === 0) {
            self::db()->executePrepared(
                'UPDATE upload_chunks
                 SET received = 1,
                     received_bytes = ?
                 WHERE id = ?',
                [strlen($chunk_data), (int) $chunk->id],
            );
            self::db()->executePrepared(
                'UPDATE uploads
                 SET chunks_received = chunks_received + 1
                 WHERE id = ?',
                [(int) $upload->id],
            );
        }

        $upload = static::getUploadById($upload->id);
        if (intval($upload->chunks_received) >= intval($upload->chunks_total)) {
            static::assembleUpload($upload);
            $upload = static::getUploadById($upload->id);
        }

        return static::response([
            'completed' => (intval($upload->status) >= 1),
            'received' => intval($upload->chunks_received),
            'total' => intval($upload->chunks_total),
        ]);
    }

    protected static function uploadCheck(RequestData $request)
    {
        $token = trim((string) $request->query('token', ''));
        if ($token === '') {
            static::failValidation('Token mancante');
        }
        $upload = static::getUploadByToken($token);
        if (empty($upload->id)) {
            static::failValidation('Upload non trovato');
        }

        $received = static::getReceivedCount($upload->id);
        if ($received != intval($upload->chunks_received)) {
            self::db()->executePrepared(
                'UPDATE uploads
                 SET chunks_received = ?
                 WHERE id = ?',
                [$received, (int) $upload->id],
            );
            $upload->chunks_received = $received;
        }

        if (intval($upload->chunks_received) >= intval($upload->chunks_total)) {
            static::assembleUpload($upload);
            $upload = static::getUploadById($upload->id);
        }

        return static::response([
            'completed' => (intval($upload->status) >= 1),
            'received' => intval($upload->chunks_received),
            'total' => intval($upload->chunks_total),
        ]);
    }

    protected static function uploadCancel(RequestData $request)
    {
        $token = trim((string) $request->query('token', ''));
        if ($token === '') {
            static::failValidation('Token mancante');
        }
        $upload = static::getUploadByToken($token);
        if (empty($upload->id)) {
            return static::response(['cancelled' => true]);
        }

        if (intval($upload->status) < 2) {
            $chunkDir = static::getChunkDir($token);
            static::removeDir($chunkDir);
            $finalPath = $upload->final_path ?? '';
            if ($finalPath !== '' && file_exists($finalPath)) {
                @unlink($finalPath);
            }
            static::cleanupChunks($upload->id);
            self::db()->executePrepared(
                'DELETE FROM uploads WHERE id = ?',
                [(int) $upload->id],
            );
        }

        return static::response(['cancelled' => true]);
    }

    protected static function uploadFinalize(RequestData $request)
    {
        $token = trim((string) $request->query('token', ''));
        if ($token === '') {
            static::failValidation('Token mancante');
        }
        $data = $request->postJson('data', [], true);
        $target = isset($data['target']) ? trim($data['target']) : '';
        $allowed_targets = ['avatar', 'richtext_image', 'background_music_url', 'sound_dm', 'sound_notifications', 'sound_whispers', 'sound_global'];
        if (!in_array($target, $allowed_targets)) {
            static::failValidation('Target non valido');
        }

        $upload = static::getUploadByToken($token);
        if (empty($upload->id)) {
            static::failValidation('Upload non trovato');
        }

        if (intval($upload->status) < 1) {
            static::failValidation('Upload non completato');
        }

        $final_path = static::normalizeFsPath($upload->final_path ?? '');
        if ($final_path === '' || !file_exists($final_path)) {
            $candidateComplete = static::normalizeFsPath(static::getCompleteDir() . '/' . $upload->token . '_' . $upload->file_hash . '.complete');
            if ($candidateComplete !== '' && file_exists($candidateComplete)) {
                $final_path = $candidateComplete;
                self::db()->executePrepared(
                    'UPDATE uploads SET final_path = ? WHERE id = ?',
                    [$final_path, (int) $upload->id],
                );
            }
        }
        if ($final_path === '' || !file_exists($final_path)) {
            static::failValidation('File non disponibile');
        }

        if (intval($upload->status) >= 2) {
            $url = static::getPublicUrlFromPath($final_path);
            if ($url === null) {
                static::failValidation('URL non disponibile');
            }
            return static::response([
                'url' => $url,
                'token' => $token,
            ]);
        }

        $ext = static::$allowed_mime[$upload->mime_type] ?? null;
        if ($ext === null) {
            static::failValidation('Formato non supportato');
        }

        $ids = static::requireUserCharacterIds();
        $user_id = $ids['user_id'];
        $character_id = $ids['character_id'];

        if ($target === 'background_music_url') {
            if (empty($user_id)) {
                static::failValidation('Utente non valido');
            }
            $audioDir = self::paths()->userAudioDir((int) $user_id);
            static::ensureDir($audioDir);

            // Elimina i file audio precedenti dell'utente in questa cartella
            if (is_dir($audioDir)) {
                $audioExts = ['mp3', 'ogg', 'wav', 'aac', 'm4a', 'webm'];
                $files = @scandir($audioDir);
                if ($files) {
                    foreach ($files as $f) {
                        if ($f === '.' || $f === '..') {
                            continue;
                        }
                        $fExt = strtolower(pathinfo($f, PATHINFO_EXTENSION));
                        if (in_array($fExt, $audioExts)) {
                            @unlink($audioDir . '/' . $f);
                        }
                    }
                }
            }

            $filename = 'audio_' . $upload->file_hash . '.' . $ext;
            $dest = static::normalizeFsPath($audioDir . '/' . $filename);

            if (!@rename($final_path, $dest)) {
                static::failValidation('Errore salvataggio file');
            }

            self::db()->executePrepared(
                'UPDATE uploads
                 SET status = 2,
                     final_path = ?,
                     date_completed = NOW()
                 WHERE id = ?',
                [$dest, (int) $upload->id],
            );
            static::cleanupChunks($upload->id);

            $url = self::paths()->publicUrlFromPath($dest);
            if ($url === null) {
                $url = self::paths()->userAudioPublicUrl((int) $user_id, $filename);
            }

            return static::response([
                'url' => $url,
                'token' => $token,
            ]);
        }

        if (strncmp($target, 'sound_', 6) === 0) {
            if (empty($user_id)) {
                static::failValidation('Utente non valido');
            }
            $audioDir = self::paths()->userAudioDir((int) $user_id);
            static::ensureDir($audioDir);

            // Delete only the previous file for this specific sound type
            $prefix = $target . '_';
            if (is_dir($audioDir)) {
                $audioExts = ['mp3', 'ogg', 'wav', 'aac', 'm4a', 'webm'];
                $files = @scandir($audioDir);
                if ($files) {
                    foreach ($files as $f) {
                        if ($f === '.' || $f === '..') {
                            continue;
                        }
                        if (strncmp($f, $prefix, strlen($prefix)) !== 0) {
                            continue;
                        }
                        $fExt = strtolower(pathinfo($f, PATHINFO_EXTENSION));
                        if (in_array($fExt, $audioExts)) {
                            @unlink($audioDir . '/' . $f);
                        }
                    }
                }
            }

            $filename = $target . '_' . $upload->file_hash . '.' . $ext;
            $dest = static::normalizeFsPath($audioDir . '/' . $filename);

            if (!@rename($final_path, $dest)) {
                static::failValidation('Errore salvataggio file');
            }

            self::db()->executePrepared(
                'UPDATE uploads
                 SET status = 2,
                     final_path = ?,
                     date_completed = NOW()
                 WHERE id = ?',
                [$dest, (int) $upload->id],
            );
            static::cleanupChunks($upload->id);

            $url = self::paths()->publicUrlFromPath($dest);
            if ($url === null) {
                $url = self::paths()->userAudioPublicUrl((int) $user_id, $filename);
            }

            return static::response([
                'url' => $url,
                'token' => $token,
            ]);
        }

        // target === 'avatar'
        if (empty($character_id)) {
            static::failValidation('Personaggio non valido');
        }

        $publicBase = self::paths()->characterUploadDir((int) $character_id);
        static::ensureDir($publicBase);
        $filename = $target . '_' . $upload->file_hash . '_' . $token . '.' . $ext;
        $dest = static::normalizeFsPath($publicBase . '/' . $filename);

        if (!@rename($final_path, $dest)) {
            static::failValidation('Errore salvataggio file');
        }

        self::db()->executePrepared(
            'UPDATE uploads
             SET status = 2,
                 final_path = ?,
                 date_completed = NOW()
             WHERE id = ?',
            [$dest, (int) $upload->id],
        );
        static::cleanupChunks($upload->id);

        $url = self::paths()->publicUrlFromPath($dest);
        if ($url === null) {
            $url = self::paths()->characterPublicUrl((int) $character_id, $filename);
        }

        return static::response([
            'url' => $url,
            'token' => $token,
        ]);
    }

    protected static function getUploadByToken($token)
    {
        return self::db()->fetchOnePrepared(
            'SELECT * FROM uploads WHERE token = ? LIMIT 1',
            [(string) $token],
        );
    }

    protected static function findExistingUpload($hash, $mime, $character_id)
    {
        if (empty($character_id)) {
            return null;
        }
        return self::db()->fetchOnePrepared(
            'SELECT token, final_path
             FROM uploads
             WHERE file_hash = ?
               AND mime_type = ?
               AND character_id = ?
               AND status = 2
             ORDER BY id DESC
             LIMIT 1',
            [(string) $hash, (string) $mime, (int) $character_id],
        );
    }

    protected static function getUploadById($id)
    {
        return self::db()->fetchOnePrepared(
            'SELECT * FROM uploads WHERE id = ? LIMIT 1',
            [(int) $id],
        );
    }

    protected static function getChunk($upload_id, $chunk_id)
    {
        return self::db()->fetchOnePrepared(
            'SELECT *
             FROM upload_chunks
             WHERE upload_id = ?
               AND chunk_index = ?
             LIMIT 1',
            [(int) $upload_id, (int) $chunk_id],
        );
    }

    protected static function getReceivedCount($upload_id)
    {
        $row = self::db()->fetchOnePrepared(
            'SELECT COUNT(*) AS total
             FROM upload_chunks
             WHERE upload_id = ?
               AND received = 1',
            [(int) $upload_id],
        );
        return !empty($row->total) ? intval($row->total) : 0;
    }

    protected static function assembleUpload($upload)
    {
        $storedFinalPath = static::normalizeFsPath($upload->final_path ?? '');
        if (intval($upload->status) >= 1 && $storedFinalPath !== '' && file_exists($storedFinalPath)) {
            if ($storedFinalPath !== ($upload->final_path ?? '')) {
                self::db()->executePrepared(
                    'UPDATE uploads SET final_path = ? WHERE id = ?',
                    [$storedFinalPath, (int) $upload->id],
                );
            }
            return;
        }

        if (intval($upload->status) >= 1) {
            $candidateComplete = static::normalizeFsPath(static::getCompleteDir() . '/' . $upload->token . '_' . $upload->file_hash . '.complete');
            if ($candidateComplete !== '' && file_exists($candidateComplete)) {
                self::db()->executePrepared(
                    'UPDATE uploads
                     SET status = 1,
                         final_path = ?,
                         date_completed = NOW()
                     WHERE id = ?',
                    [$candidateComplete, (int) $upload->id],
                );
                return;
            }
        }

        $chunk_dir = static::getChunkDir($upload->token);
        $complete_dir = static::getCompleteDir();
        static::ensureDir($complete_dir);
        $final_path = static::normalizeFsPath($complete_dir . '/' . $upload->token . '_' . $upload->file_hash . '.complete');

        if (file_exists($final_path)) {
            $hash = hash_file('sha256', $final_path);
            if ($hash === $upload->file_hash) {
                self::db()->executePrepared(
                    'UPDATE uploads
                     SET status = 1,
                         final_path = ?,
                         date_completed = NOW()
                     WHERE id = ?',
                    [$final_path, (int) $upload->id],
                );
                return;
            }
            @unlink($final_path);
        }

        $out = fopen($final_path, 'wb');
        if ($out === false) {
            static::failValidation('Impossibile creare il file finale');
        }

        for ($i = 0; $i < intval($upload->chunks_total); $i++) {
            $chunk_path = $chunk_dir . '/' . $i;
            if (!file_exists($chunk_path)) {
                fclose($out);
                static::failValidation('Chunk mancante');
            }
            $in = fopen($chunk_path, 'rb');
            if ($in === false) {
                fclose($out);
                static::failValidation('Impossibile leggere il chunk');
            }
            stream_copy_to_stream($in, $out);
            fclose($in);
        }
        fclose($out);

        $hash = hash_file('sha256', $final_path);
        if ($hash !== $upload->file_hash) {
            @unlink($final_path);
            static::failValidation('Hash file non valido');
        }

        self::db()->executePrepared(
            'UPDATE uploads
             SET status = 1,
                 final_path = ?,
                 date_completed = NOW()
             WHERE id = ?',
            [$final_path, (int) $upload->id],
        );

        for ($i = 0; $i < intval($upload->chunks_total); $i++) {
            $chunk_path = $chunk_dir . '/' . $i;
            if (file_exists($chunk_path)) {
                @unlink($chunk_path);
            }
        }
        @rmdir($chunk_dir);
    }

    protected static function getBaseDir()
    {
        return self::paths()->baseTmpDir();
    }

    protected static function normalizeFsPath($path)
    {
        return self::paths()->normalizePath((string) $path);
    }

    protected static function getMaxFileSize()
    {
        $row = self::db()->fetchOnePrepared(
            'SELECT value FROM sys_settings WHERE `key` = ? LIMIT 1',
            ['upload_max_mb'],
        );
        $mb = !empty($row->value) ? intval($row->value) : static::$default_max_mb;
        if ($mb <= 0) {
            $mb = static::$default_max_mb;
        }
        return $mb * 1024 * 1024;
    }

    protected static function getTargetMaxFileSize($target)
    {
        if (empty($target)) {
            return null;
        }
        $key = null;
        if ($target === 'avatar') {
            $key = 'upload_max_avatar_mb';
        } elseif ($target === 'richtext_image') {
            $key = null;
        } elseif ($target === 'background_music_url' || strncmp($target, 'sound_', 6) === 0) {
            $key = 'upload_max_audio_mb';
        }
        $mb = null;
        if ($key) {
            $row = self::db()->fetchOnePrepared(
                'SELECT value FROM sys_settings WHERE `key` = ? LIMIT 1',
                [$key],
            );
            if (!empty($row->value)) {
                $mb = intval($row->value);
            }
        }
        if ($mb === null || $mb <= 0) {
            if (!isset(static::$target_limits_mb[$target])) {
                return null;
            }
            $mb = intval(static::$target_limits_mb[$target]);
        }
        if ($mb <= 0) {
            return null;
        }
        return $mb * 1024 * 1024;
    }

    protected static function cleanupOldUploads()
    {
        $hours = intval(static::$cleanup_hours);
        if ($hours <= 0) {
            return;
        }
        $cutoff = date('Y-m-d H:i:s', time() - ($hours * 3600));
        $rows = self::db()->fetchAllPrepared(
            'SELECT id, token, final_path
             FROM uploads
             WHERE status < 2
               AND date_created < ?',
            [$cutoff],
        );
        if (empty($rows)) {
            return;
        }
        foreach ($rows as $row) {
            $token = $row->token ?? '';
            if ($token !== '') {
                $chunkDir = static::getChunkDir($token);
                static::removeDir($chunkDir);
            }
            $finalPath = $row->final_path ?? '';
            if ($finalPath !== '' && file_exists($finalPath)) {
                @unlink($finalPath);
            }
            static::cleanupChunks($row->id);
            self::db()->executePrepared(
                'DELETE FROM uploads WHERE id = ?',
                [(int) $row->id],
            );
        }
    }

    protected static function cleanupChunks($upload_id)
    {
        self::db()->executePrepared(
            'DELETE FROM upload_chunks WHERE upload_id = ?',
            [(int) $upload_id],
        );
    }

    protected static function getChunkDir($token)
    {
        return self::paths()->chunksDir($token);
    }

    protected static function getCompleteDir()
    {
        return self::paths()->completeDir();
    }

    protected static function getPublicUrlFromPath($path)
    {
        return self::paths()->publicUrlFromPath((string) $path);
    }

    protected static function ensureDir($path)
    {
        if (!file_exists($path)) {
            mkdir($path, 0755, true);
        }
    }

    protected static function removeDir($path)
    {
        if (!file_exists($path)) {
            return;
        }
        if (!is_dir($path)) {
            @unlink($path);
            return;
        }
        $files = scandir($path);
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            $target = $path . '/' . $file;
            if (is_dir($target)) {
                static::removeDir($target);
            } else {
                @unlink($target);
            }
        }
        @rmdir($path);
    }

    protected static function generateToken()
    {
        return bin2hex(openssl_random_pseudo_bytes(16));
    }

    protected static function failValidation(string $message): void
    {
        throw AppError::validation($message);
    }

    protected static function response($dataset): array
    {
        return ResponseEmitter::emit(ApiResponse::json([
            'status' => true,
            'dataset' => $dataset,
        ]));
    }
}
