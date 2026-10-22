<?php

namespace App\Support\Http;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/** Writes one structured line per request after the response is sent (spec §8). */
final class RequestLog
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $start = defined('LARAVEL_START') ? LARAVEL_START : $request->server('REQUEST_TIME_FLOAT');

        Log::info('request', [
            'method' => $request->method(),
            'route' => $request->route()?->uri() ?? $request->path(),
            'status' => $response->getStatusCode(),
            'duration_ms' => is_numeric($start) ? (int) round((microtime(true) - (float) $start) * 1000) : null,
        ]);
    }
}
