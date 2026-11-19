<?php

use App\Http\Controllers\Auth\GrandpaSsonLoginController;
use App\Http\Controllers\Public\StatusPageController;
use Illuminate\Support\Facades\Route;

Route::get('/auth/grandpasson/login/{provider}', [GrandpaSsonLoginController::class, 'redirect']);
Route::get('/auth/grandpasson/callback', [GrandpaSsonLoginController::class, 'callback']);

// Sign in with Google (#244). Every route answers 404 unless GOOGLE_LOGIN_ENABLED and the client are set.
Route::controller(\App\Http\Controllers\Auth\GoogleLoginController::class)->group(function () {
    Route::get('/auth/google/redirect', 'redirect')->middleware('throttle:google-login')->name('google.redirect');
    Route::get('/auth/google/callback', 'callback')->middleware('throttle:google-login')->name('google.callback');
    Route::get('/cadastro/google', 'signupForm')->name('signup.google');
    Route::post('/cadastro/google', 'signup')->middleware('throttle:signup');
});

// Renamed pages answer 301 from their old slugs (#248).
Route::middleware(['public.status.throttle', 'status.slug_redirect'])->group(function () {
    Route::get('/status/{slug}.json', [StatusPageController::class, 'json'])
        ->where('slug', '[a-z0-9-]+');
    Route::get('/status/{slug}/history', [StatusPageController::class, 'history'])
        ->where('slug', '[a-z0-9-]+');
    Route::get('/status/{slug}/feed', [StatusPageController::class, 'feed'])
        ->where('slug', '[a-z0-9-]+');
    Route::get('/status/{slug}/logo', [StatusPageController::class, 'logo'])
        ->where('slug', '[a-z0-9-]+');
    Route::get('/status/{slug}/calendar', [StatusPageController::class, 'calendar'])
        ->where('slug', '[a-z0-9-]+');
    Route::get('/status/{slug}/components/{componentId}', [StatusPageController::class, 'component'])
        ->where('slug', '[a-z0-9-]+')
        ->whereNumber('componentId');
    Route::get('/status/{slug}', [StatusPageController::class, 'show'])
        ->where('slug', '[a-z0-9-]+');
});

// Application-level per-IP/per-token limiting already happens inside
// HeartbeatPingService::recordPing() (60 hits/60s each, DB-backed). This
// middleware-layer throttle uses the same per-minute ceiling but rejects
// a flood via the (file-backed) cache before it ever reaches that DB
// round-trip — defense in depth, not a replacement for it.
Route::match(['get', 'post'], '/ping/{token}', [\App\Http\Controllers\Public\PingController::class, 'ping'])
    ->middleware('throttle:60,1');

// Agent metric ingestion (agent-ingestion addendum §4). No CSRF: a token in
// the path authenticates, like /ping/{token}. Rate limits are DB-backed in the service.
Route::post('/ingest/{token}', [\App\Http\Controllers\Public\AgentIngestController::class, 'lineProtocol'])
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class, \Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class])
    ->where('token', '[A-Za-z0-9_:-]{1,128}');
Route::post('/ingest/{token}/v1/metrics', [\App\Http\Controllers\Public\AgentIngestController::class, 'otlp'])
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class, \Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class])
    ->where('token', '[A-Za-z0-9_:-]{1,128}');

// Telegram bot updates (#190). No CSRF: the webhook secret in the path and in
// the X-Telegram-Bot-Api-Secret-Token header authenticates the request.
Route::post('/telegram/webhook/{secret}', [\App\Http\Controllers\Public\TelegramWebhookController::class, 'handle'])
    ->middleware('throttle:telegram-webhook')
    ->where('secret', '[A-Za-z0-9_-]{1,256}');

// Status page email subscriptions (#92). The subscribe form is also posted from custom domains and
// from cached pages, and the unsubscribe POST is the RFC 8058 one-click target, so neither carries a CSRF token.
Route::controller(\App\Http\Controllers\Public\StatusPageSubscriptionController::class)
    ->prefix('/status/{slug}')
    ->where(['slug' => '[a-z0-9-]+'])
    ->group(function () {
        Route::post('/subscribe', 'subscribe')
            ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class, \Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class])
            ->middleware('throttle:status-subscribe')->name('status.subscribe');
        Route::get('/subscriptions/{subscriber}/confirm', 'confirmForm')->middleware('throttle:status-subscription-link')->name('status.subscription.confirm');
        Route::post('/subscriptions/{subscriber}/confirm', 'confirm')->middleware('throttle:status-subscription-link');
        Route::get('/subscriptions/{subscriber}/unsubscribe/{token}', 'unsubscribeForm')->middleware('throttle:status-subscription-link');
        Route::post('/subscriptions/{subscriber}/unsubscribe/{token}', 'unsubscribe')
            ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class, \Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class])
            ->middleware('throttle:status-subscription-link');
    });

// Account pages (#120): sign-up, email verification, password reset.
Route::controller(\App\Http\Controllers\Account\AccountController::class)->group(function () {
    Route::get('/cadastro', 'signupForm')->name('signup');
    Route::post('/cadastro', 'signup')->middleware('throttle:signup');
    Route::get('/cadastro/enviado', 'signupSent')->name('signup.sent');
    Route::get('/cadastro/verificar/{user}/{hash}', 'verify')->middleware('throttle:signup-verify')->name('signup.verify');
    Route::post('/cadastro/reenviar', 'resend')->middleware('throttle:signup-resend')->name('signup.resend');
    Route::get('/esqueci-senha', 'forgotForm')->name('password.request');
    Route::post('/esqueci-senha', 'forgot')->middleware('throttle:password-email')->name('password.email');
    Route::get('/redefinir-senha/{token}', 'resetForm')->name('password.reset');
    Route::post('/redefinir-senha', 'reset')->middleware('throttle:password-reset')->name('password.update');
});

Route::get('/app/{any?}', function () {
    $path = public_path('app/index.html');
    if (file_exists($path)) {
        return response()->file($path);
    }
    return response('Operator SPA not built yet.', 404);
})->where('any', '.*');

// Public site (#122): landing page and legal pages. The JSON health check is at /v1/platform/health.
Route::get('/', [\App\Http\Controllers\Public\SiteController::class, 'landing']);
Route::get('/en', [\App\Http\Controllers\Public\SiteController::class, 'landingEnglish']);
Route::get('/termos', [\App\Http\Controllers\Public\SiteController::class, 'terms']);
Route::get('/privacidade', [\App\Http\Controllers\Public\SiteController::class, 'privacy']);
// English versions (#244); the Portuguese texts prevail.
Route::get('/terms', [\App\Http\Controllers\Public\SiteController::class, 'termsEnglish']);
Route::get('/privacy', [\App\Http\Controllers\Public\SiteController::class, 'privacyEnglish']);
// Public API reference (#151).
Route::get('/api', [\App\Http\Controllers\Public\ApiReferenceController::class, 'index']);
Route::get('/api/openapi.json', [\App\Http\Controllers\Public\ApiReferenceController::class, 'document']);
