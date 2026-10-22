<?php

namespace App\Modules\Staff\Http\Middleware;

use App\Modules\Staff\Models\StaffUser;
use App\Support\Http\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequirePasswordChange
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $staff = $request->user();
        if ($staff instanceof StaffUser && $staff->must_change_password
            && ! $request->routeIs('admin.auth.me', 'admin.auth.logout', 'admin.auth.password')) {
            throw new ApiException(403, 'PASSWORD_CHANGE_REQUIRED', 'Please change your password.');
        }

        return $next($request);
    }
}
