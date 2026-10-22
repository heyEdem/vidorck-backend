<?php

namespace App\Providers;

use App\Modules\Staff\Enums\Permission;
use App\Modules\Staff\Models\StaffUser;
use App\Modules\Staff\Policies\StaffPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

final class AuthServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        foreach (['staff-password-change', 'staff-password-reset'] as $limiter) {
            RateLimiter::for($limiter, fn (Request $request): Limit => Limit::perMinute(5)
                ->by($request->user()?->getAuthIdentifier().'|'.$request->ip()));
        }
        RateLimiter::for('checkout-start', fn (Request $request): Limit => Limit::perMinute(10)->by((string) $request->ip()));
        RateLimiter::for('checkout-status', fn (Request $request): Limit => Limit::perMinute(30)->by((string) $request->ip()));
        // product-spec §6.5: 5/min and 20/hour per IP, plus 10/hour per order number.
        $byIpAndOrder = fn (Request $request): array => [
            Limit::perMinute(5)->by('m|'.$request->ip()),
            Limit::perHour(20)->by('h|'.$request->ip()),
            Limit::perHour(10)->by('o|'.strtoupper(trim(is_string($request->input('orderNumber')) ? $request->input('orderNumber') : ''))),
        ];
        RateLimiter::for('cancellation-requests', $byIpAndOrder);
        RateLimiter::for('order-tracking', $byIpAndOrder);
        RateLimiter::for('order-confirmation', fn (Request $request): Limit => Limit::perMinute(30)->by((string) $request->ip()));
        RateLimiter::for('contact-submissions', fn (Request $request): Limit => Limit::perMinutes(10, 3)->by((string) $request->ip()));
        Gate::policy(StaffUser::class, StaffPolicy::class);
        Gate::before(fn (Authenticatable $user): ?bool => $user instanceof StaffUser && $user->is_active ? null : false);

        foreach (Permission::cases() as $permission) {
            Gate::define($permission->value, fn (StaffUser $staff): bool => $staff->is_active && $staff->role->allows($permission));
        }
        Gate::define('delete-orders', fn (StaffUser $staff): bool => false);
    }
}
