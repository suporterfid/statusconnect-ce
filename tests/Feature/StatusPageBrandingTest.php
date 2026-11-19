<?php

namespace Tests\Feature;

use App\Application\Tenancy\TenantService;
use App\Infrastructure\Persistence\Eloquent\TenantMembership;
use App\Infrastructure\Persistence\Eloquent\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StatusPageBrandingTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private string $base;

    private int $tenantId;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->owner = User::factory()->create();
        $tenant = app(TenantService::class)->createTenant('Acme', 'acme', $this->owner);
        $this->tenantId = $tenant->id;
        $environment = $tenant->environments()->firstOrFail();
        $this->base = "/v1/tenants/{$tenant->public_id}/environments/{$environment->public_id}/status-page";
        $this->actingAs($this->owner)->putJson($this->base, ['slug' => 'acme-status', 'title' => 'Acme Status', 'locale' => 'en'])->assertCreated();
    }

    public function test_accent_colour_footer_links_and_powered_by_are_applied(): void
    {
        $this->actingAs($this->owner)->putJson($this->base, [
            'accent_color' => '#0B3D91',
            'website_url' => 'https://acme.example.com',
            'privacy_url' => 'https://acme.example.com/privacy',
            'show_powered_by' => false,
        ])->assertOk()
            ->assertJsonPath('data.accent_color', '#0b3d91')
            ->assertJsonPath('data.show_powered_by', false);

        $this->get('/status/acme-status.json')
            ->assertJsonPath('branding.accent_color', '#0b3d91')
            ->assertJsonPath('branding.accent_text', '#ffffff');

        $this->get('/status/acme-status')
            ->assertOk()
            ->assertSee('--header-bg: #0b3d91; --header-text: #ffffff;', false)
            ->assertSee('href="https://acme.example.com"', false)
            ->assertSee('href="https://acme.example.com/privacy"', false)
            ->assertDontSee('Status page by');

        $this->actingAs($this->owner)->putJson($this->base, ['accent_color' => null, 'show_powered_by' => true])->assertOk();
        $this->get('/status/acme-status')->assertDontSee('--header-bg: #0b3d91', false)->assertSee('Status page by')->assertDontSee('github.com', false);
    }

    public function test_platform_legal_links_appear_only_when_the_operator_is_configured(): void
    {
        config()->set('app.url', 'https://app.statusconnect.test');
        config()->set('legal.company_name', '');
        $this->get('/status/acme-status')->assertOk()->assertDontSee('/termos')->assertDontSee('/terms"', false);

        // This page is in English, so it links the English translations (#244).
        config()->set('legal.company_name', 'Exemplo Ltda');
        $this->get('/status/acme-status')
            ->assertOk()
            ->assertSee('href="https://app.statusconnect.test/terms"', false)
            ->assertSee('href="https://app.statusconnect.test/privacy"', false);
    }

    public function test_invalid_colour_and_non_http_links_are_rejected(): void
    {
        $this->actingAs($this->owner)->putJson($this->base, [
            'accent_color' => 'red;background:url(x)',
            'website_url' => 'javascript:alert(1)',
            'privacy_url' => 'ftp://example.com',
        ])->assertUnprocessable()->assertJsonValidationErrors(['accent_color', 'website_url', 'privacy_url']);
    }

    public function test_logo_is_reencoded_scaled_and_served_with_safe_headers(): void
    {
        $data = $this->actingAs($this->owner)->post("{$this->base}/logo", [
            'logo' => UploadedFile::fake()->image('logo.jpg', 2000, 1000),
        ], ['Accept' => 'application/json'])->assertOk()->json('data');

        $this->assertStringContainsString('/status/acme-status/logo?v=', $data['logo_url']);
        $path = DB::table('status_pages')->value('logo_path');
        $this->assertMatchesRegularExpression('#^status-logos/\d+-[0-9a-f]{16}\.png$#', $path);
        [$width, $height] = getimagesizefromstring(Storage::disk('local')->get($path));
        $this->assertSame([400, 200], [$width, $height]);

        $this->get('/status/acme-status')->assertSee($data['logo_url'], false)->assertSee('alt="Acme Status"', false);

        auth()->logout();
        $response = $this->get('/status/acme-status/logo');
        $response->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString("default-src 'none'", (string) $response->headers->get('Content-Security-Policy'));

        $this->actingAs($this->owner)->post("{$this->base}/logo", [
            'logo' => UploadedFile::fake()->image('other.png', 300, 100),
        ], ['Accept' => 'application/json'])->assertOk();
        Storage::disk('local')->assertMissing($path);

        $this->actingAs($this->owner)->deleteJson("{$this->base}/logo")->assertOk()->assertJsonPath('data.logo_url', null);
        $this->assertSame([], Storage::disk('local')->allFiles('status-logos'));
        $this->get('/status/acme-status/logo')->assertNotFound();
    }

    public function test_non_images_and_viewers_are_refused(): void
    {
        $this->actingAs($this->owner)->post("{$this->base}/logo", [
            'logo' => UploadedFile::fake()->createWithContent('logo.png', '<svg onload="alert(1)"></svg>'),
        ], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->assertSame([], Storage::disk('local')->allFiles());

        $viewer = User::factory()->create();
        TenantMembership::query()->create(['tenant_id' => $this->tenantId, 'user_id' => $viewer->id, 'role' => 'viewer']);
        $this->actingAs($viewer)->post("{$this->base}/logo", [
            'logo' => UploadedFile::fake()->image('logo.png', 100, 50),
        ], ['Accept' => 'application/json'])->assertForbidden();
    }

    public function test_logo_of_a_private_page_needs_a_member(): void
    {
        $this->actingAs($this->owner)->post("{$this->base}/logo", [
            'logo' => UploadedFile::fake()->image('logo.png', 100, 50),
        ], ['Accept' => 'application/json'])->assertOk();
        DB::table('status_pages')->update(['visibility' => 'private']);

        $this->get('/status/acme-status/logo')->assertOk();
        auth()->logout();
        $this->get('/status/acme-status/logo')->assertNotFound();
    }
}
