<?php

declare(strict_types=1);

namespace Core;

use Core\Database\DbAdapterFactory;
use Core\Database\DbAdapterInterface;

class AuditLogService
{
    /** @var DbAdapterInterface|null */
    private static $dbAdapter = null;

    public static function setDbAdapter(DbAdapterInterface $adapter = null): void
    {
        static::$dbAdapter = $adapter;
    }

    private static function db(): DbAdapterInterface
    {
        if (static::$dbAdapter instanceof DbAdapterInterface) {
            return static::$dbAdapter;
        }

        static::$dbAdapter = DbAdapterFactory::createFromConfig();
        return static::$dbAdapter;
    }

    private static function getSessionValue($key)
    {
        return SessionStore::get($key);
    }

    private static function getSessionAdminId(): int
    {
        return (int) static::getSessionValue('admin_id');
    }

    private static function getSessionUserId(): int
    {
        return (int) static::getSessionValue('user_id');
    }

    private static function resolveAuthor($author = null)
    {
        if ($author !== null) {
            return (int) $author;
        }

        $adminId = static::getSessionAdminId();
        if ($adminId > 0) {
            return $adminId;
        }

        $userId = static::getSessionUserId();
        if ($userId > 0) {
            return $userId;
        }

        return null;
    }

    public static function write($module, $action, $data = null, $area = 'system', $url = null, $author = null)
    {
        $module = trim((string) $module);
        $action = trim((string) $action);
        if ($module === '' || $action === '') {
            return false;
        }

        $authorId = static::resolveAuthor($author);
        $payload = ($data === null) ? null : json_encode($data, JSON_UNESCAPED_UNICODE);
        $url = ($url === null || trim((string) $url) === '') ? '/audit/' . $module . '/' . $action : (string) $url;

        static::db()->executePrepared(
            'INSERT INTO sys_logs (
                author,
                url,
                area,
                module,
                action,
                data,
                date_created
             ) VALUES (?, ?, ?, ?, ?, ?, NOW())',
            [
                $authorId,
                (string) $url,
                (string) $area,
                (string) $module,
                (string) $action,
                $payload,
            ],
        );

        return true;
    }

    public static function writeEvent($event, $data = null, $area = 'system', $author = null)
    {
        $event = trim((string) $event);
        if ($event === '') {
            return false;
        }

        $parts = explode('.', $event, 2);
        $module = $parts[0];
        $action = $parts[1] ?? 'event';

        return static::write($module, $action, $data, $area, null, $author);
    }

    public static function writeFromUrl($url, $data = null, $author = null)
    {
        $url = (string) $url;
        $authorId = static::resolveAuthor($author);

        $extra = explode('/', $url);
        $action = $extra[count($extra) - 1] ?? '';
        $module = !empty($extra[count($extra) - 2]) ? $extra[count($extra) - 2] : 'system';
        if ($module === 'sys-logs') {
            return false;
        }

        $area = (in_array('backend', $extra, true)) ? 'backend/' . $action : ($extra[count($extra) - 2] ?? '');
        if ($area == '') {
            $area = 'system (<small>' . $module . '/' . $action . '</small>)';
        }

        $payload = ($data === null) ? null : json_encode($data);

        static::db()->executePrepared(
            'INSERT INTO sys_logs (
                author,
                url,
                area,
                module,
                action,
                data,
                date_created
             ) VALUES (?, ?, ?, ?, ?, ?, NOW())',
            [
                $authorId,
                (string) $url,
                (string) $area,
                (string) $module,
                (string) $action,
                $payload,
            ],
        );

        return true;
    }
}
