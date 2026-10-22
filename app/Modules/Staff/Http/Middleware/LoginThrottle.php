<?php

namespace App\Modules\Staff\Http\Middleware;

use App\Modules\Staff\Actions\AuditLogger;
use App\Support\Http\ApiException;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

final class LoginThrottle
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasSession() || ! $request->attributes->get('sanctum')) {
            throw new AuthenticationException;
        }
        $email = $request->input('email');
        $email = is_string($email) ? mb_strtolower(trim($email)) : '';
        $keys = [
            'staff-login:'.hash('sha256', $email.'|'.$request->ip()) => 5,
            'staff-login-ip:'.hash('sha256', (string) $request->ip()) => 30,
        ];
        foreach ($keys as $key => $limit) {
            if (RateLimiter::hit($key, 60) > $limit) {
                $retry = max(1, RateLimiter::availableIn($key));
                if (Cache::add($key.':lockout-logged', true, $retry)) {
                    app(AuditLogger::class)->record(null, 'auth.login_locked_out', after: ['emailHash' => hash('sha256', $email)]);
                }
                throw new ApiException(429, 'RATE_LIMITED', 'Too many requests. Please try again later.',
                    headers: ['Retry-After' => (string) $retry]);
            }
        }

        return $next($request);
    }
}
