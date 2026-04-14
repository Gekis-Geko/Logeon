<?php

declare(strict_types=1);

namespace Core\Http;

use Core\AppContext;
use Core\Logging\LegacyLoggerAdapter;
use Core\Logging\LoggerInterface;

class ErrorResponder
{
    /** @var LoggerInterface|null */
    private static $logger = null;

    public static function setLogger(LoggerInterface $logger = null): void
    {
        static::$logger = $logger;
    }

    private static function logger(): LoggerInterface
    {
        if (static::$logger instanceof LoggerInterface) {
            return static::$logger;
        }

        static::$logger = new LegacyLoggerAdapter();
        return static::$logger;
    }

    public static function legacy(string $message): void
    {
        static::json($message, 400);
    }

    /**
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    public static function json(string $message, int $status = 400, array $extra = []): array
    {
        $payload = array_merge(['error' => $message], $extra);

        return ResponseEmitter::emit(ApiResponse::json($payload, $status));
    }

    public static function html(string $message, int $status = 400): string
    {
        $rendered = static::renderTwigErrorPage($message, $status);
        if (is_string($rendered) && $rendered !== '') {
            return ResponseEmitter::html($rendered, $status);
        }

        $safeMessage = static::escapeHtml($message);
        $title = ($status >= 500) ? 'Errore di sistema' : 'Errore';

        return ResponseEmitter::html(
            '<!doctype html>'
            . '<html lang="it"><head><meta charset="UTF-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
            . '<title>' . $title . '</title>'
            . '<style>body{font-family:Arial,sans-serif;margin:0;padding:2rem;background:#f5f5f5;color:#222}'
            . '.err{max-width:720px;margin:0 auto;background:#fff;border:1px solid #ddd;border-radius:8px;padding:1.25rem}'
            . 'h1{margin:0 0 .5rem;font-size:1.25rem}p{margin:.25rem 0 0;line-height:1.4}</style>'
            . '</head><body><div class="err"><h1>' . $title . '</h1><p>' . $safeMessage . '</p></div></body></html>',
            $status,
        );
    }

    public static function fromThrowableHtml(\Throwable $e)
    {
        if ($e instanceof AppError) {
            return static::html($e->getMessage(), $e->status());
        }

        if (CONFIG['debug']) {
            return static::html($e->getMessage(), 500);
        }

        return static::html('Errore di sistema', 500);
    }

    /**
     * Transitional adapter:
     * - AppError -> emit structured JSON and return
     * - other Throwable -> emit generic JSON error
     *
     * @return array<string,mixed>|null
     */
    public static function fromThrowable(\Throwable $e, bool $legacyOnUnknown = true)
    {
        if ($e instanceof AppError) {
            return static::json(
                $e->getMessage(),
                $e->status(),
                static::withErrorCode($e->payload(), $e->errorCode()),
            );
        }

        if ($legacyOnUnknown) {
            return static::json(
                CONFIG['debug'] ? $e->getMessage() : 'Errore di sistema',
                500,
                ['error_code' => 'system_error'],
            );
        }

        return static::json('Errore di sistema', 500, ['error_code' => 'system_error']);
    }

    private static function escapeHtml(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function renderTwigErrorPage(string $message, int $status)
    {
        $title = static::resolveErrorTitle($status);

        try {
            ob_start();
            AppContext::templateRenderer()->render('sys/errors/app_error.twig', [
                'status_code' => $status,
                'error_title' => $title,
                'error_message' => $message,
            ]);
            return (string) ob_get_clean();
        } catch (\Throwable $e) {
            if (ob_get_level() > 0) {
                ob_end_clean();
            }
            return '';
        }
    }

    private static function resolveErrorTitle(int $status): string
    {
        if ($status === 403) {
            return 'Operazione non autorizzata';
        }
        if ($status === 404) {
            return 'Risorsa non trovata';
        }
        if ($status >= 500) {
            return 'Errore di sistema';
        }

        return 'Errore';
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private static function withErrorCode(array $payload, string $errorCode): array
    {
        if (!array_key_exists('error_code', $payload) && $errorCode !== '') {
            $payload['error_code'] = $errorCode;
        }

        if (array_key_exists('error_code', $payload)) {
            $payload['error_code'] = (string) $payload['error_code'];
        }

        return $payload;
    }
}
