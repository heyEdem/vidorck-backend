<?php

namespace App\Modules\Staff\Http\Middleware;

use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

/**
 * Sanctum's session + CSRF handling, limited to the staff admin API.
 * The storefront shares the admin's origin, so its public calls must stay stateless and CSRF-free.
 */
final class EnsureAdminRequestsAreStateful extends EnsureFrontendRequestsAreStateful
{
    public static function fromFrontend($request)
    {
        return $request->is('api/v1/admin', 'api/v1/admin/*') && parent::fromFrontend($request);
    }
}
