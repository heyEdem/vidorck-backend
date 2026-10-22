<?php

namespace App\Modules\Staff\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Staff\Actions\AuditLogger;
use App\Modules\Staff\Actions\RevokeStaffSessions;
use App\Modules\Staff\Http\Requests\ChangePasswordRequest;
use App\Modules\Staff\Http\Resources\StaffResource;
use App\Modules\Staff\Models\StaffUser;
use App\Support\Http\ApiException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class AuthController extends Controller
{
    public function login(Request $request, AuditLogger $audit): StaffResource
    {
        if (! $request->hasSession() || ! $request->attributes->get('sanctum')) {
            throw new AuthenticationException;
        }
        $input = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:128'],
        ]);
        $email = mb_strtolower(trim($input['email']));
        try {
            // Hash verification and its failure timebox must not hold SQLite's write lock.
            if (! Auth::guard('web')->validate(['email' => $email, 'password' => $input['password'], 'is_active' => true])) {
                $audit->record(null, 'auth.login_failed', after: ['email' => $email]);
                throw new ApiException(422, 'VALIDATION_ERROR', 'Invalid email or password.');
            }
            $candidate = Auth::guard('web')->getLastAttempted();
            if (! $candidate instanceof StaffUser) {
                throw new AuthenticationException;
            }
            $rehashed = Hash::needsRehash($candidate->password) ? Hash::make($input['password']) : null;
            $staff = DB::transaction(function () use ($candidate, $rehashed, $email, $request, $audit): ?StaffUser {
                $staff = StaffUser::find($candidate->id);
                if (! $staff || ! $staff->is_active || $staff->session_version !== $candidate->session_version
                    || ! hash_equals($staff->password, $candidate->password)) {
                    $audit->record(null, 'auth.login_failed', after: ['email' => $email]);

                    return null;
                }
                if ($rehashed !== null) {
                    $staff->password = $rehashed;
                }
                $staff->forceFill(['last_login_at' => now(), 'last_login_ip' => $request->ip()])->save();
                $audit->record($staff, 'auth.login_succeeded', $staff);

                return $staff;
            }, 3);
            if ($staff === null) {
                throw new ApiException(422, 'VALIDATION_ERROR', 'Invalid email or password.');
            }
            Auth::guard('web')->login($staff, false);
            $request->session()->regenerate();
            $request->session()->put([
                'staff_authenticated_at' => now()->timestamp,
                'staff_session_version' => $staff->session_version,
            ]);
        } catch (Throwable $exception) {
            // Session state is outside the DB transaction: explicitly undo a partially completed login.
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            throw $exception;
        }

        return new StaffResource($staff);
    }

    public function me(Request $request): StaffResource
    {
        return new StaffResource($request->user());
    }

    public function logout(Request $request, AuditLogger $audit): Response
    {
        /** @var StaffUser $staff */
        $staff = $request->user();
        try {
            $audit->record($staff, 'auth.logout', $staff);
        } finally {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->noContent();
    }

    public function password(ChangePasswordRequest $request, AuditLogger $audit, RevokeStaffSessions $sessions): StaffResource
    {
        /** @var StaffUser $actor */
        $actor = $request->user();
        $input = $request->validated();
        if (! Hash::check($input['currentPassword'], $actor->password)) {
            throw ValidationException::withMessages(['currentPassword' => ['The current password is incorrect.']]);
        }
        $newHash = Hash::make($input['newPassword']);
        $staff = DB::transaction(function () use ($actor, $newHash, $audit, $sessions): StaffUser {
            $staff = StaffUser::findOrFail($actor->id);
            if (! $staff->is_active || $staff->session_version !== $actor->session_version
                || ! hash_equals($staff->password, $actor->password)) {
                throw new AuthenticationException;
            }
            $staff->forceFill(['password' => $newHash, 'must_change_password' => false])->save();
            $sessions->execute($staff, $staff, 'password_changed');
            $audit->record($staff, 'auth.password_changed', $staff);

            return $staff;
        }, 3);
        Auth::guard('web')->setUser($staff);
        $request->session()->put('staff_session_version', $staff->session_version);
        $request->session()->put('password_hash_web', Auth::guard('web')->hashPasswordForCookie($staff->password));
        $request->session()->regenerate();

        return new StaffResource($staff);
    }
}
