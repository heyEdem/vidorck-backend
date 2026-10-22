<?php

namespace App\Modules\Staff\Http\Middleware;

use App\Modules\Staff\Actions\AuditLogger;
use App\Modules\Staff\Models\StaffUser;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class EnsureStaffSession
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $staff = $request->user();
        if (! $request->hasSession() || ! $staff instanceof StaffUser) {
            throw new AuthenticationException;
        }
        // Refresh instead of trusting a guard object held by a long-running process.
        $staff = $staff->fresh();
        $started = $request->session()->get('staff_authenticated_at');
        $version = $request->session()->get('staff_session_version');
        $reason = match (true) {
            ! $staff || ! $staff->is_active => 'inactive',
            ! is_int($started) || $started > now()->timestamp || now()->timestamp - $started >= 43200 => 'expired',
            $version !== $staff->session_version => 'revoked',
            default => null,
        };
        if ($reason !== null) {
            app(AuditLogger::class)->record($staff, 'auth.session_rejected', $staff, after: ['reason' => $reason]);
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            throw new AuthenticationException;
        }
        Auth::guard('web')->setUser($staff);

        return $next($request);
    }
}
