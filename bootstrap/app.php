<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: '',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // Enterprise edition routes (#250), only in a copy of the source that has them.
        then: function () {
            if (is_file(base_path('routes/enterprise.php'))) {
                require base_path('routes/enterprise.php');
            }
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->statefulApi();
        // Bearer-token requests and token-in-path endpoints skip CSRF (#153).
        $middleware->web(replace: [
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class => \App\Http\Middleware\ValidateCsrfTokenUnlessBearer::class,
        ]);
        $middleware->prepend(\App\Http\Middleware\ServeCustomStatusDomain::class);

        $middleware->alias([
            'auth.api_or_sanctum' => \App\Http\Middleware\AuthenticateApiKeyOrSanctum::class,
            'locale.from_user' => \App\Http\Middleware\SetLocaleFromUser::class,
            'tenant.context' => \App\Http\Middleware\ResolveTenantEnvironment::class,
            'terms.accepted' => \App\Http\Middleware\EnsureTermsAccepted::class,
            'tenant.permission' => \App\Http\Middleware\EnforceTenantPermission::class,
            'grandpasson.workspace_aud' => \App\Http\Middleware\EnforceGrandpaSsonWorkspaceAud::class,
            'public.status.throttle' => \App\Http\Middleware\EnforcePublicStatusPageRateLimit::class,
            'status.slug_redirect' => \App\Http\Middleware\RedirectRenamedStatusPage::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
