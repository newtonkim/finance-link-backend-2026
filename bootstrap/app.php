<?php

use App\Central\Http\Middleware\EnsureCentralDomain;
use App\Central\Http\Middleware\EnsureCentralUser;
use App\Domain\Licensing\Entities\License;
use App\Http\Middleware\EnforceLicense;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\IdentifyTenant;
use App\Http\Middleware\PermissionMiddleware;
use App\Http\Middleware\SetTenantDatabase;
use App\Tenant\Http\Middleware\EnsureLicenseActive;
use App\Tenant\Http\Middleware\EnsurePlanFeatureEnabled;
use App\Tenant\Http\Middleware\EnsureTenantDomain;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: [__DIR__.'/../routes/api.php', __DIR__.'/../routes/central.php'],
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(prepend: [
            IdentifyTenant::class,
            SetTenantDatabase::class,
        ]);

        $middleware->web(append: [
            HandleAppearance::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->api(prepend: [
            IdentifyTenant::class,
            SetTenantDatabase::class,

        ]);

        $middleware->api(append: [
            EnforceLicense::class,
        ]);

        // EnsureFrontendRequestsAreStateful removed — API uses Bearer token auth only.
        // Stateful cookie/session auth is not used; keeping this causes CSRF redirects
        // when Origin matches a SANCTUM_STATEFUL_DOMAIN.

        $middleware->validateCsrfTokens(except: [
            'api/v1/auth/login',
            'api/v1/auth/register',
            'api/*',
        ]);

        $middleware->alias([
            'tenant.init' => EnsureTenantDomain::class,
            'license.active' => EnsureLicenseActive::class,
            'central.auth' => EnsureCentralUser::class,
            'central.domain' => EnsureCentralDomain::class,
            'feature' => EnsurePlanFeatureEnabled::class,
            'tenant.api' => EnsureTenantDomain::class,
            // 'role'        => \Spatie\Permission\Middlewares\RoleMiddleware::class,
            // 'permission'  => \Spatie\Permission\Middlewares\PermissionMiddleware::class,
            'permission' => PermissionMiddleware::class,
        ]);
    })
    ->withSchedule(function (Schedule $schedule) {
        $schedule->command('licenses:check-expiry')->daily();
        $schedule->command('expense:process-recurring')->daily();

        // Step 9: Automatic Expiry Update
        $schedule->call(function () {
            License::where('expires_at', '<', now())
                ->where('status', 'active')
                ->update(['status' => 'expired']);
        })->daily();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (AuthenticationException $e, $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }
        });

        // Return JSON 404 for all API requests instead of an HTML page
        $exceptions->render(function (NotFoundHttpException $e, $request) {
            if ($request->is('api/*')) {
                $previous = $e->getPrevious();
                if ($previous instanceof ModelNotFoundException) {
                    $model = class_basename($previous->getModel());
                    $ids = implode(', ', (array) $previous->getIds());

                    return response()->json([
                        'message' => "{$model} with ID [{$ids}] not found.",
                    ], 404);
                }

                return response()->json([
                    'message' => $e->getMessage() ?: 'The requested resource was not found.',
                ], 404);
            }
        });

        $exceptions->render(function (Throwable $e, $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            if (
                $e instanceof AuthenticationException
                || $e instanceof ValidationException
                || $e instanceof HttpExceptionInterface
            ) {
                return null;
            }

            report($e);

            return response()->json([
                'message' => app()->hasDebugModeEnabled()
                    ? $e->getMessage()
                    : 'Server error.',
            ], 500);
        });
    })->create();
