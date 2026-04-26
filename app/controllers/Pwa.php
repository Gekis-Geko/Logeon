<?php

declare(strict_types=1);

use Core\Http\ResponseEmitter;
use Core\PwaRuntime;

class Pwa
{
    public function manifest()
    {
        $runtime = PwaRuntime::build();
        if (($runtime['enabled'] ?? false) !== true) {
            return ResponseEmitter::html('Not Found', 404, [
                'Content-Type' => 'text/plain; charset=UTF-8',
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
            ]);
        }

        return ResponseEmitter::json(
            PwaRuntime::manifestPayload($runtime),
            200,
            [
                'Content-Type' => 'application/manifest+json; charset=UTF-8',
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
                'X-Content-Type-Options' => 'nosniff',
            ],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    public function serviceWorker()
    {
        $runtime = PwaRuntime::build();
        if (($runtime['enabled'] ?? false) !== true) {
            return ResponseEmitter::html('Not Found', 404, [
                'Content-Type' => 'text/plain; charset=UTF-8',
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
            ]);
        }

        return ResponseEmitter::html(PwaRuntime::serviceWorkerBody($runtime), 200, [
            'Content-Type' => 'application/javascript; charset=UTF-8',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Service-Worker-Allowed' => (string) ($runtime['scope'] ?? '/'),
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
