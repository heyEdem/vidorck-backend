<?php

namespace App\Support\Http;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class RequestId
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $supplied = (string) $request->header('X-Request-Id', '');
        $id = preg_match('/\A[A-Za-z0-9._:-]{1,128}\z/', $supplied) === 1
            ? $supplied : (string) Str::uuid();

        $request->attributes->set('request_id', $id);
        Log::shareContext(['request_id' => $id]);

        $response = $next($request);
        $response->headers->set('X-Request-Id', $id);

        return $response;
    }
}
