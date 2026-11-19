<?php

namespace App\Providers;

use App\Application\GrandpaSson\CachedTokenClient;
use App\Application\GrandpaSson\HttpIntrospectionClient;
use App\Application\GrandpaSson\HttpTokenClient;
use App\Application\GrandpaSson\IntrospectionClientInterface;
use App\Application\GrandpaSson\TokenClientInterface;
use App\Application\Incidents\IncidentService;
use App\Domain\Incidents\FlapPolicy;
use App\Domain\Incidents\IncidentStateMachine;
use App\Domain\Outbound\DnsResolverInterface;
use App\Domain\Outbound\IpClassifier;
use App\Domain\Outbound\OutboundPolicy;
use App\Domain\Outbound\OutboundPolicyConfig;
use App\Domain\Outbound\TcpTargetValidator;
use App\Domain\Shared\Clock;
use App\Domain\Shared\SystemClock;
use App\Infrastructure\Dns\SystemDnsResolver;
use App\Infrastructure\HttpClient\CurlMultiPinnedProbe;
use App\Infrastructure\HttpClient\GuzzlePinnedHttpTransport;
use App\Infrastructure\HttpClient\MultiPinnedHttpProbe;
use App\Infrastructure\HttpClient\PinnedHttpTransport;
use App\Infrastructure\Tcp\PinnedTcpProbe;
use App\Infrastructure\Tcp\StreamSelectPinnedTcpProbe;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Clock::class, SystemClock::class);
        $this->app->bind(\App\Infrastructure\Persistence\Eloquent\AgentMetricRepository::class, fn (): \App\Infrastructure\Persistence\Eloquent\AgentMetricRepository => new \App\Infrastructure\Persistence\Eloquent\AgentMetricRepository((int) config('agent.max_metrics_per_monitor', 200)));
        $this->app->singleton(HttpIntrospectionClient::class);
        $this->app->singleton(IntrospectionClientInterface::class, HttpIntrospectionClient::class);
        $this->app->singleton(HttpTokenClient::class);
        $this->app->singleton(TokenClientInterface::class, function ($app): TokenClientInterface {
            return new CachedTokenClient($app->make(HttpTokenClient::class));
        });
        $this->app->singleton(IncidentService::class, function ($app): IncidentService {
            return new IncidentService(
                $app->make(IncidentStateMachine::class),
                $app->make(FlapPolicy::class),
                (int) config('incidents.flap_threshold', 5),
                (int) config('incidents.flap_window_minutes', 60),
            );
        });
        $this->app->singleton(DnsResolverInterface::class, SystemDnsResolver::class);
        $this->app->singleton(\App\Application\SecondLocation\SecondLocationConfirmer::class, fn ($app) => new \App\Application\SecondLocation\SecondLocationConfirmer([
            $app->make(\App\Application\SecondLocation\RemoteProbeProvider::class),
            $app->make(\App\Application\SecondLocation\GlobalpingProvider::class),
        ]));

        $this->app->singleton(OutboundPolicy::class, function ($app): OutboundPolicy {
            return OutboundPolicy::fromConfig(
                OutboundPolicyConfig::fromArray(config('outbound', [])),
                $app->make(DnsResolverInterface::class),
            );
        });
        $this->app->singleton(TcpTargetValidator::class, function ($app): TcpTargetValidator {
            return new TcpTargetValidator(
                $app->make(DnsResolverInterface::class),
                new IpClassifier((array) config('outbound.metadata_ips', [])),
                (array) config('outbound.allowed_ports', [80, 443]),
            );
        });

        $this->app->singleton(GuzzlePinnedHttpTransport::class);
        $this->app->singleton(PinnedHttpTransport::class, GuzzlePinnedHttpTransport::class);
        $this->app->singleton(CurlMultiPinnedProbe::class);
        // Checks run on this server; the enterprise edition can send them to probe regions (#222, #250).
        $this->app->singleton(MultiPinnedHttpProbe::class, CurlMultiPinnedProbe::class);
        $this->app->singleton(StreamSelectPinnedTcpProbe::class);
        $this->app->singleton(PinnedTcpProbe::class, StreamSelectPinnedTcpProbe::class);

        $this->registerExtensionPoints();

        // The enterprise edition (#250), when this copy of the source has it.
        if (class_exists(\App\Enterprise\EnterpriseServiceProvider::class)) {
            $this->app->register(\App\Enterprise\EnterpriseServiceProvider::class);
        }
    }

    /**
     * Interfaces and registries the enterprise edition extends (#250), with the
     * core's own implementations: no sign-up abuse limits, local-only checks, a
     * plain home page, the core notification channel types and features.
     */
    private function registerExtensionPoints(): void
    {
        $this->app->singleton(\App\Application\Signup\SignupLimits::class, \App\Application\Signup\NoSignupLimits::class);
        $this->app->singleton(\App\Application\Scheduling\CheckRegions::class, \App\Application\Scheduling\LocalCheckRegions::class);
        $this->app->singleton(\App\Http\Views\LandingPage::class, \App\Http\Views\DefaultLandingPage::class);
        $this->app->singleton(\App\Application\Extensions\MaintenanceTasks::class);

        $this->app->singleton(\App\Application\Notifications\NotificationTransports::class, function ($app) {
            $transports = new \App\Application\Notifications\NotificationTransports($app);
            $transports->register('email', \App\Domain\Notifications\Transports\EmailNotificationTransport::class);
            $transports->register('webhook', \App\Domain\Notifications\Transports\WebhookNotificationTransport::class);
            $transports->register('taskconnect', \App\Domain\Notifications\Transports\TaskConnectNotificationTransport::class);
            $transports->register('telegram', \App\Domain\Notifications\Transports\TelegramNotificationTransport::class);

            return $transports;
        });

        $this->app->singleton(\App\Application\Extensions\Features::class, function () {
            $features = new \App\Application\Extensions\Features();
            $features->define('agent_ingest', fn (): bool => (bool) config('agent.enabled'));
            $features->define('telegram', fn (): bool => app(\App\Application\Notifications\Telegram\TelegramLinkService::class)->available());

            return $features;
        });
    }

    public function boot(): void
    {
        // Named limiters for the public account and subscription pages (#92, #120). Plain
        // `throttle:N,M` keys every route by IP alone, so all of them would share one counter.
        foreach ([
            'signup' => [5, 10], 'signup-resend' => [3, 10], 'signup-verify' => [20, 1],
            'password-email' => [5, 10], 'password-reset' => [10, 10],
            'status-subscribe' => [5, 10], 'status-subscription-link' => [30, 1],
            // MCP clients (#184) call often; their own counter keeps them from locking out sign-in.
            'mcp' => [120, 1],
            // Telegram webhook (#190): updates arrive from Telegram's servers.
            'telegram-webhook' => [120, 1],
            // Sign in with Google (#244): start and callback.
            'google-login' => [20, 1],
        ] as $name => [$attempts, $minutes]) {
            \Illuminate\Support\Facades\RateLimiter::for($name, fn (\Illuminate\Http\Request $request) => \Illuminate\Cache\RateLimiting\Limit::perMinutes($minutes, $attempts)->by($name.'|'.$request->ip()));
        }

        // Platform administration (#248): writes that change who can sign in, per admin.
        \Illuminate\Support\Facades\RateLimiter::for('platform-sensitive', fn (\Illuminate\Http\Request $request) => \Illuminate\Cache\RateLimiting\Limit::perMinute(30)->by('platform-sensitive|'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        // Every sign-in (password, Google, GrandpaSSOn, remember-me) stamps the session
        // with the user's session version and records the time (#248).
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Auth\Events\Login::class, function (\Illuminate\Auth\Events\Login $event): void {
            if ($event->user instanceof \App\Infrastructure\Persistence\Eloquent\User) {
                \App\Application\Tenancy\UserSessions::started($event->user, request());
            }
        });

        // Hostinger shared hosting can't set the docroot to public/, so requests
        // are rewritten in at public_html/.htaccess (public/$1). That rewrite
        // makes the front controller resolve as /public/index.php, which
        // Symfony's Request::getBaseUrl() picks up and bakes a "/public"
        // prefix into every url()/route()/redirect() call. Pin the root to
        // the configured APP_URL instead of deriving it from the request.
        URL::forceRootUrl(config('app.url'));

        if (str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }
    }
}
