<?php

use App\Modules\Staff\Http\Middleware\EnsureAdminRequestsAreStateful;
use App\Modules\Staff\Http\Middleware\EnsureStaffSession;
use App\Modules\Staff\Http\Middleware\RequirePasswordChange;
use App\Support\Http\ErrorResponse;
use App\Support\Http\RequestId;
use App\Support\Http\RequestLog;
use App\Support\Http\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Wrap HandleCors so preflight responses also receive these headers.
        $middleware->prepend([RequestId::class, RequestLog::class, SecurityHeaders::class]);
        // Only the admin API is stateful; see EnsureAdminRequestsAreStateful.
        $middleware->api(prepend: [EnsureAdminRequestsAreStateful::class]);
        $middleware->trimStrings(except: ['currentPassword', 'newPassword', 'newPasswordConfirmation']);
        $middleware->prependToPriorityList(SubstituteBindings::class, EnsureStaffSession::class);
        $middleware->prependToPriorityList(SubstituteBindings::class, RequirePasswordChange::class);
        $middleware->redirectGuestsTo(fn (Request $request) => null);
    })
    ->withCommands([app_path('Modules/Staff/Console'), app_path('Modules/Catalog/Console')])
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['currentPassword', 'newPassword', 'newPasswordConfirmation', 'temporaryPassword']);
        $exceptions->shouldRenderJsonWhen(fn (Request $request, Throwable $exception): bool => true);
        $exceptions->render(fn (Throwable $exception) => $exception instanceof HttpResponseException
            ? null : ErrorResponse::render($exception));
    })->create();
