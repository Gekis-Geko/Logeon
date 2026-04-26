<?php

declare(strict_types=1);

namespace Core;

use Core\Http\AppError;
use Core\Http\RequestContext;

class Logs
{
    public static $str_logger = '';

    private static function isAjaxRequest(): bool
    {
        return RequestContext::isAjaxRequest();
    }

    public function __construct()
    {
        // No implicit side-effect on construction.
    }

    public static function trace($str, $obj = false): void
    {
        if (!self::shouldTrace()) {
            return;
        }

        if (!self::shouldTraceStack()) {
            self::traceLite($str, $obj);
            return;
        }

        $elem = [];
        $name = '';
        $limit = self::traceStackLimit();
        $stack = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, $limit);
        $total_logs = count($stack);

        $first = $stack[$total_logs - 1] ?? [];
        if (isset($first['file']) && $first['file'] !== '') {
            $line = $first['line'] ?? '';
            $elem[] = '<small>' . basename($first['file']) . '(' . $line . ')</small>';
        }

        $ignored_functions = [
            'trace',
            'traceLite',
            'query',
            '_sys_error',
            '_user_error',
            'ajaxErrorHandler',
        ];

        for ($i = 0; $i < $total_logs; $i++) {
            $e = $stack[$i];
            $fn = $e['function'];
            if (in_array($fn, $ignored_functions, true)) {
                continue;
            }

            $class = '';
            if (!empty($e['class'])) {
                $class = $e['class'] . ($e['type'] ?? '');
            } elseif (!empty($e['file'])) {
                $class = $e['file'];
            }
            if ($class === '') {
                continue;
            }

            $line = $e['line'] ?? '';
            $elem[] = '<span>' . $class . ' -> ' . $fn . '(' . $line . ')</span>';
        }

        $name = implode(' | ', $elem);

        if (!is_array($str) && !is_object($str) && !$obj) {
            $string_to_log = '<small>(' . date('Y-m-d H:i:s') . ')</small> - ' . $name . ': ' . $str . '<br />';
        } else {
            $string_to_log = '<small>(' . date('Y-m-d H:i:s') . ')</small> - ' . $name . ' - oggetto:<blockquote><div><pre>' . htmlentities(print_r($str, 1), ENT_IGNORE) . '</pre></div></blockquote>';
        }

        $string_to_log .= '<hr>';
        self::write($string_to_log);
        self::emitDebugIfNeeded($string_to_log);
    }

    public static function traceLite($str, $obj = false): void
    {
        if (!self::shouldTrace()) {
            return;
        }

        if (!is_array($str) && !is_object($str) && !$obj) {
            $string_to_log = '<small>(' . date('Y-m-d H:i:s') . ')</small> - ' . $str . '<br />';
        } else {
            $string_to_log = '<small>(' . date('Y-m-d H:i:s') . ')</small> - oggetto:<blockquote><div><pre>' . htmlentities(print_r($str, 1), ENT_IGNORE) . '</pre></div></blockquote>';
        }

        $string_to_log .= '<hr>';
        self::write($string_to_log);
        self::emitDebugIfNeeded($string_to_log);
    }

    private static function write($string_to_log): void
    {
        self::$str_logger .= Filter::htmlEntities((string) $string_to_log);
    }

    private static function shouldTrace(): bool
    {
        $logs = self::logsConfig();
        if (array_key_exists('trace', $logs)) {
            return $logs['trace'] === true;
        }

        $config = self::runtimeConfig();
        return array_key_exists('debug', $config) && $config['debug'] === true;
    }

    private static function shouldTraceStack(): bool
    {
        $logs = self::logsConfig();
        if (array_key_exists('trace_stack', $logs)) {
            return $logs['trace_stack'] === true;
        }

        return false;
    }

    private static function traceStackLimit(): int
    {
        $logs = self::logsConfig();
        if (array_key_exists('trace_stack_limit', $logs)) {
            $limit = (int) $logs['trace_stack_limit'];
            if ($limit > 0) {
                return $limit;
            }
        }

        return 8;
    }

    private static function shouldEchoCli(): bool
    {
        if (PHP_SAPI !== 'cli' || self::isAjaxRequest()) {
            return false;
        }

        $logs = self::logsConfig();
        if (!array_key_exists('echo_cli', $logs)) {
            return false;
        }

        return $logs['echo_cli'] === true;
    }

    /**
     * @return array<string,mixed>
     */
    private static function runtimeConfig(): array
    {
        if (!defined('CONFIG')) {
            return [];
        }

        /** @var array<string,mixed> $config */
        $config = constant('CONFIG');
        return $config;
    }

    /**
     * @return array<string,mixed>
     */
    private static function logsConfig(): array
    {
        $config = self::runtimeConfig();
        if (!array_key_exists('logs', $config) || !is_array($config['logs'])) {
            return [];
        }

        return $config['logs'];
    }

    private static function asCliText(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $text = preg_replace('/\s+/', ' ', $text);
        return trim((string) $text);
    }

    private static function emitDebugIfNeeded(string $chunk = ''): void
    {
        if (!self::shouldEchoCli()) {
            return;
        }

        $payload = $chunk !== '' ? $chunk : (string) self::$str_logger;
        $line = self::asCliText($payload);
        if ($line === '') {
            return;
        }

        echo $line . PHP_EOL;
    }

    public function read()
    {
        return self::$str_logger;
    }

    public static function getMicrotime()
    {
        list($usec, $sec) = explode(' ', microtime());
        return ((float) $usec + (float) $sec);
    }

    public function sendLogMail($extra = null)
    {
        if ($extra !== null) {
            self::trace($extra);
        }
        //mail($this->email, 'DEBUG ' . $this->send_log_subject, $this->str_logger, 'Content-type:text/html;charset=UTF-8\r\n');
    }

    public static function error($str): void
    {
        self::traceLite('Errore: ' . $str);
        throw AppError::validation((string) $str);
    }

    public static function sys_error($str): void
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT);
        $caller = $trace[2] ?? ($trace[1] ?? []);

        $header = '<h3>ERRORE</h3><p class="lead">' . $str . '</p><hr>';
        $caller_file = $caller['file'] ?? 'unknown';
        $caller_line = $caller['line'] ?? '';
        $header .= 'FILE: <b>' . $caller_file . '</b> (Linea: ' . $caller_line . ')<br>';

        if (isset($caller['class'])) {
            $header .= 'OGGETTO: <b>' . $caller['class'] . '</b>' . ($caller['type'] ?? '');
        }

        if (isset($caller['function'])) {
            $header .= $caller['function'] . '(';
        }

        if (isset($caller['args'])) {
            $header .= '<br><blockquote><pre>' . var_export($caller['args'], 1) . '</pre></blockquote>)';
        }

        $request = RequestContext::request();
        $str_request = empty($request) ? '' : '<h5>Richiesta</h5><pre>' . print_r($request, 1) . '</pre><hr>';
        $body = $header . $str_request . '<h4>Log</h4><p>' . self::$str_logger . '</p><hr>';

        self::write($body);
        self::emitDebugIfNeeded($body);

        throw new \RuntimeException('Si e verificato un errore di sistema.');
    }
}
