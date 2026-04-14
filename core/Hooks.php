<?php

declare(strict_types=1);

namespace Core;

class Hooks
{
    private static $actions = [];

    public static function add($hook, $callback, $priority = 10)
    {
        if (!is_callable($callback)) {
            return false;
        }
        if (!isset(self::$actions[$hook])) {
            self::$actions[$hook] = [];
        }
        if (!isset(self::$actions[$hook][$priority])) {
            self::$actions[$hook][$priority] = [];
        }
        self::$actions[$hook][$priority][] = $callback;
        return true;
    }

    public static function run($hook, &...$args)
    {
        if (empty(self::$actions[$hook])) {
            return null;
        }
        ksort(self::$actions[$hook]);
        foreach (self::$actions[$hook] as $callbacks) {
            foreach ($callbacks as $callback) {
                call_user_func_array($callback, $args);
            }
        }
        return null;
    }

    public static function filter($hook, $value, ...$args)
    {
        if (empty(self::$actions[$hook])) {
            return $value;
        }
        ksort(self::$actions[$hook]);
        foreach (self::$actions[$hook] as $callbacks) {
            foreach ($callbacks as $callback) {
                $value = call_user_func_array($callback, array_merge([$value], $args));
            }
        }
        return $value;
    }

    /**
     * Backward-compatible alias used by newer services.
     *
     * @param string $hook
     * @param mixed ...$args
     * @return null
     */
    public static function fire($hook, ...$args)
    {
        if (empty(self::$actions[$hook])) {
            return null;
        }
        ksort(self::$actions[$hook]);
        foreach (self::$actions[$hook] as $callbacks) {
            foreach ($callbacks as $callback) {
                call_user_func_array($callback, $args);
            }
        }
        return null;
    }
}
