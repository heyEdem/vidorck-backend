<?php

namespace Tests\Fixtures;

use Closure;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EarlyResponseMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        throw new HttpResponseException(response()->noContent());
    }
}
