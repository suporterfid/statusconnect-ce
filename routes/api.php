<?php

use App\Http\Controllers\Api\V1\ApiKeyController;
use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\DashboardSummaryController;
use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\GrandpaSsonTenantMappingController;
use App\Http\Controllers\Api\V1\IncidentController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\MeMembershipsController;
use App\Http\Controllers\Api\V1\MemberController;
use App\Http\Controllers\Api\V1\PlatformAdminController;
use App\Http\Controllers\Api\V1\PlatformTenantController;
use App\Http\Controllers\Api\V1\PlatformUserController;
use App\Http\Controllers\Api\V1\MonitorController;
use App\Http\Controllers\Api\V1\Auth\PasswordController;
use App\Http\Controllers\Api\V1\SetupController;
use App\Http\Controllers\Api\V1\StatusPageController;
use App\Http\Controllers\Api\V1\UserPreferencesController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::middleware(['web', 'throttle:10,1'])->post('/auth/login', LoginController::class);
    Route::middleware(['web', 'throttle:30,1'])->get('/setup', [SetupController::class, 'show']);
    Route::middleware(['web', 'throttle:5,1'])->post('/setup', [SetupController::class, 'store']);

    Route::middleware(['web', 'auth.api_or_sanctum', 'locale.from_user'])->group(function () {
        Route::post('/auth/logout', LogoutController::class);
        Route::get('/me', MeController::class);
        Route::get('/me/memberships', MeMembershipsController::class);
        Route::patch('/me/preferences', UserPreferencesController::class);
        Route::middleware('throttle:10,1')->put('/me/password', PasswordController::class);
        Route::middleware('throttle:10,1')->post('/me/terms-acceptance', \App\Http\Controllers\Api\V1\TermsAcceptanceController::class);

        Route::prefix('/platform')->middleware('terms.accepted')->group(function () {
            Route::get('/users', [PlatformAdminController::class, 'users']);
            Route::middleware('throttle:10,1')->post('/users/{userId}/reset-password', [PlatformAdminController::class, 'resetPassword']);
            Route::get('/tenants', [PlatformAdminController::class, 'tenants']);
            Route::post('/tenants', [PlatformAdminController::class, 'storeTenant']);
            Route::get('/checker', [PlatformAdminController::class, 'checker']);

            // Managing one user or organization (#248). Writes share a per-admin rate limit.
            Route::get('/users/{userId}', [PlatformUserController::class, 'show']);
            Route::get('/tenants/{tenantPublicId}', [PlatformTenantController::class, 'show']);
            Route::middleware('throttle:platform-sensitive')->group(function () {
                Route::post('/users', [PlatformUserController::class, 'store']);
                Route::patch('/users/{userId}', [PlatformUserController::class, 'update']);
                Route::post('/users/{userId}/block', [PlatformUserController::class, 'block']);
                Route::post('/users/{userId}/unblock', [PlatformUserController::class, 'unblock']);
                Route::delete('/users/{userId}/sessions', [PlatformUserController::class, 'endSessions']);
                Route::patch('/tenants/{tenantPublicId}', [PlatformTenantController::class, 'update']);
                Route::post('/tenants/{tenantPublicId}/members', [PlatformTenantController::class, 'storeMember']);
                Route::patch('/tenants/{tenantPublicId}/members/{membershipId}', [PlatformTenantController::class, 'updateMember']);
                Route::delete('/tenants/{tenantPublicId}/members/{membershipId}', [PlatformTenantController::class, 'destroyMember']);
                Route::post('/tenants/{tenantPublicId}/transfer-ownership', [PlatformTenantController::class, 'transferOwnership']);
            });
        });
    });

    // MCP server for AI clients (#184): environment API keys only, authenticated in the controller.
    // No sessions here: Sanctum's stateful middleware would demand a CSRF token from same-origin callers.
    Route::middleware('throttle:mcp')->post('/mcp', [\App\Http\Controllers\Api\V1\McpController::class, 'handle'])
        ->withoutMiddleware(\Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class);
    Route::match(['get', 'delete'], '/mcp', [\App\Http\Controllers\Api\V1\McpController::class, 'methodNotAllowed']);

    // Liveness of the app; it does not touch the database. With ?checker=1 it
    // also reports the minute checker and answers 503 when it has not run for
    // scheduler.gap_minutes, for an external monitor to watch (#199): the
    // checker cannot report its own absence. It also answers 503 while the
    // checker is saturated (#214).
    Route::get('/platform/health', function (\Illuminate\Http\Request $request) {
        $body = ['status' => 'ok', 'timestamp' => now()->toIso8601String()];
        if (! $request->boolean('checker')) {
            return response()->json($body);
        }

        try {
            $last = \App\Application\Scheduling\CheckerGapRecorder::lastTickAt();
        } catch (\Throwable) {
            $last = null;
        }
        $age = $last === null ? null : max(0, now()->getTimestamp() - $last->getTimestamp());
        $running = $age !== null && $age < \App\Application\Scheduling\CheckerGapRecorder::thresholdMinutes() * 60;
        try {
            $saturation = \App\Application\Scheduling\CheckerSaturation::status();
        } catch (\Throwable) {
            $saturation = ['saturated' => false, 'consecutive_ticks' => 0, 'since' => null];
        }
        $ok = $running && ! $saturation['saturated'];

        return response()->json([
            ...$body,
            'status' => ! $running ? 'checker_stopped' : ($saturation['saturated'] ? 'checker_saturated' : 'ok'),
            'checker' => ['ok' => $ok, 'last_tick_at' => $last?->format(DATE_ATOM), 'age_seconds' => $age, 'saturation' => $saturation],
        ], $ok ? 200 : 503);
    });

    Route::middleware(['web', 'auth.api_or_sanctum', 'locale.from_user', 'tenant.context', 'grandpasson.workspace_aud', 'terms.accepted', 'tenant.permission'])->group(function () {
        Route::get('/tenants/{tenantId}/environments/{environmentId}/ping', function () {
            return response()->json(['status' => 'pong']);
        });

        Route::get('/tenants/{tenantId}/members', [MemberController::class, 'index']);
        Route::post('/tenants/{tenantId}/members', [MemberController::class, 'store']);
        Route::patch('/tenants/{tenantId}/members/{membershipId}', [MemberController::class, 'update']);
        Route::delete('/tenants/{tenantId}/members/{membershipId}', [MemberController::class, 'destroy']);

        Route::prefix('/tenants/{tenantId}/environments/{environmentId}')->group(function () {
            Route::get('/dashboard-summary', DashboardSummaryController::class);
            Route::get('/incidents', [IncidentController::class, 'index']);
            Route::post('/incidents', [IncidentController::class, 'store']);
            Route::get('/incidents/{incidentId}', [IncidentController::class, 'show']);
            Route::post('/incidents/{incidentId}/updates', [IncidentController::class, 'storeUpdate']);
            Route::post('/incidents/{incidentId}/resolve', [IncidentController::class, 'resolve']);
            Route::get('/monitors', [MonitorController::class, 'index']);
            Route::post('/monitors', [MonitorController::class, 'store']);
            Route::get('/monitors/{monitorId}', [MonitorController::class, 'show']);
            Route::get('/monitors/{monitorId}/check-results', [MonitorController::class, 'checkResults']);
            Route::put('/monitors/{monitorId}', [MonitorController::class, 'update']);
            Route::delete('/monitors/{monitorId}', [MonitorController::class, 'destroy']);
            Route::post('/monitors/{monitorId}/rotate-heartbeat-token', [MonitorController::class, 'rotateHeartbeatToken']);
            Route::post('/monitors/{monitorId}/rotate-agent-token', [MonitorController::class, 'rotateAgentToken']);
            Route::get('/monitors/{monitorId}/agent-metrics', [\App\Http\Controllers\Api\V1\AgentMonitorController::class, 'metrics']);
            Route::get('/monitors/{monitorId}/agent-rules', [\App\Http\Controllers\Api\V1\AgentMonitorController::class, 'rules']);
            Route::post('/monitors/{monitorId}/agent-rules', [\App\Http\Controllers\Api\V1\AgentMonitorController::class, 'storeRule']);
            Route::put('/monitors/{monitorId}/agent-rules/{ruleId}', [\App\Http\Controllers\Api\V1\AgentMonitorController::class, 'updateRule']);
            Route::delete('/monitors/{monitorId}/agent-rules/{ruleId}', [\App\Http\Controllers\Api\V1\AgentMonitorController::class, 'destroyRule']);

            Route::get('/status-page', [StatusPageController::class, 'show']);
            Route::put('/status-page', [StatusPageController::class, 'update']);
            Route::get('/status-page/components', [StatusPageController::class, 'components']);
            Route::post('/status-page/components', [StatusPageController::class, 'storeComponent']);
            Route::put('/status-page/components/{componentId}', [StatusPageController::class, 'updateComponent'])->whereNumber('componentId');
            Route::delete('/status-page/components/{componentId}', [StatusPageController::class, 'destroyComponent'])->whereNumber('componentId');
            Route::post('/status-page/logo', [StatusPageController::class, 'storeLogo']);
            Route::delete('/status-page/logo', [StatusPageController::class, 'destroyLogo']);
            Route::get('/status-page/updates', [StatusPageController::class, 'updates']);
            Route::post('/status-page/updates', [StatusPageController::class, 'storeUpdate']);
            Route::put('/status-page/updates/{updateId}', [StatusPageController::class, 'updateUpdate']);
            Route::delete('/status-page/updates/{updateId}', [StatusPageController::class, 'destroyUpdate']);

            Route::get('/api-keys', [ApiKeyController::class, 'index']);
            Route::post('/api-keys', [ApiKeyController::class, 'store']);
            Route::delete('/api-keys/{apiKeyId}', [ApiKeyController::class, 'destroy']);

            Route::get('/notification-channels', [\App\Http\Controllers\Api\V1\NotificationChannelController::class, 'index']);
            Route::post('/notification-channels', [\App\Http\Controllers\Api\V1\NotificationChannelController::class, 'store']);
            // Telegram linking (#190); declared before {channelId} for readability, the paths do not overlap.
            Route::post('/notification-channels/telegram-links', [\App\Http\Controllers\Api\V1\TelegramLinkController::class, 'store']);
            Route::get('/notification-channels/telegram-links/{linkId}', [\App\Http\Controllers\Api\V1\TelegramLinkController::class, 'show']);
            Route::get('/notification-channels/{channelId}', [\App\Http\Controllers\Api\V1\NotificationChannelController::class, 'show']);
            Route::put('/notification-channels/{channelId}', [\App\Http\Controllers\Api\V1\NotificationChannelController::class, 'update']);
            Route::delete('/notification-channels/{channelId}', [\App\Http\Controllers\Api\V1\NotificationChannelController::class, 'destroy']);
            Route::post('/notification-channels/{channelId}/test', [\App\Http\Controllers\Api\V1\NotificationChannelController::class, 'test']);

            Route::get('/maintenance-windows', [\App\Http\Controllers\Api\V1\MaintenanceWindowController::class, 'index']);
            Route::post('/maintenance-windows', [\App\Http\Controllers\Api\V1\MaintenanceWindowController::class, 'store']);
            Route::get('/maintenance-windows/{windowId}', [\App\Http\Controllers\Api\V1\MaintenanceWindowController::class, 'show']);
            Route::put('/maintenance-windows/{windowId}', [\App\Http\Controllers\Api\V1\MaintenanceWindowController::class, 'update']);
            Route::delete('/maintenance-windows/{windowId}', [\App\Http\Controllers\Api\V1\MaintenanceWindowController::class, 'destroy']);
        });
    });

    Route::middleware(['web', 'auth.api_or_sanctum', 'locale.from_user'])->post('/platform/grandpasson/tenant-mappings', [GrandpaSsonTenantMappingController::class, 'store']);
});
