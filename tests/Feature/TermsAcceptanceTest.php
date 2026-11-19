<?php

namespace Tests\Feature;

use App\Application\Tenancy\TenantService;
use App\Infrastructure\Persistence\Eloquent\Tenant;
use App\Infrastructure\Persistence\Eloquent\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Per-user acceptance of the Terms of Use and Privacy Policy.
 */
class TermsAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config([
            'legal.require_acceptance' => true,
            'legal.updated_at' => '2026-11-09',
            'legal.company_name' => 'Empresa Exemplo Ltda',
        ]);
        $this->user = User::factory()->create();
        $this->tenant = app(TenantService::class)->createTenant('Acme', 'acme', $this->user);
    }

    private function accept(string $version = '2026-11-09')
    {
        return $this->actingAs($this->user)->withHeaders(['User-Agent' => 'TestBrowser/1.0'])
            ->postJson('/v1/me/terms-acceptance', ['accept' => true, 'version' => $version]);
    }

    public function test_off_by_default(): void
    {
        config(['legal.require_acceptance' => false]);

        $this->actingAs($this->user)->getJson('/v1/me')->assertOk()->assertJsonPath('data.terms.required', false);
        $this->actingAs($this->user)->getJson("/v1/tenants/{$this->tenant->public_id}/members")->assertOk();
    }

    public function test_existing_user_must_accept_before_using_the_api(): void
    {
        $this->actingAs($this->user)->getJson('/v1/me')->assertOk()
            ->assertJsonPath('data.terms.required', true)
            ->assertJsonPath('data.terms.version', '2026-11-09');
        $this->actingAs($this->user)->getJson("/v1/tenants/{$this->tenant->public_id}/members")
            ->assertForbidden()->assertJsonPath('code', 'terms_acceptance_required');
        $this->actingAs($this->user)->getJson('/v1/me/memberships')->assertOk();

        $this->actingAs($this->user)->postJson('/v1/me/terms-acceptance', ['version' => '2026-11-09'])->assertStatus(422);
        $this->accept('2020-01-01')->assertStatus(422)->assertJsonValidationErrors('version');
        $this->accept()->assertOk()->assertJsonPath('data.required', false);
        $this->accept()->assertOk();

        $this->actingAs($this->user)->getJson("/v1/tenants/{$this->tenant->public_id}/members")->assertOk();

        $row = DB::table('terms_acceptances')->first();
        $this->assertSame(1, DB::table('terms_acceptances')->count());
        $this->assertSame($this->user->email, $row->email);
        $this->assertSame('prompt', $row->method);
        $this->assertSame('TestBrowser/1.0', $row->user_agent);
        $this->assertNotNull($row->ip_address);
        $version = DB::table('legal_document_versions')->find($row->legal_document_version_id);
        $this->assertStringContainsString('Empresa Exemplo Ltda', $version->terms_html);
        $this->assertSame(hash('sha256', $version->privacy_html), $version->privacy_hash);
    }

    public function test_new_version_asks_again_and_keeps_history(): void
    {
        $this->accept()->assertOk();

        config(['legal.updated_at' => '2027-01-15', 'legal.company_name' => 'Empresa Nova Ltda']);
        $this->actingAs($this->user)->getJson("/v1/tenants/{$this->tenant->public_id}/members")->assertForbidden();
        $this->accept('2027-01-15')->assertOk();

        $this->assertSame(['2026-11-09', '2027-01-15'], DB::table('terms_acceptances')->orderBy('id')->pluck('version')->all());
        $this->assertSame(2, DB::table('legal_document_versions')->count());
    }

    public function test_api_keys_are_not_asked(): void
    {
        $env = $this->tenant->environments()->firstOrFail();
        $key = app(\App\Application\ApiKeys\ApiKeyService::class)->create(tenant: $this->tenant, actor: $this->user, name: 'k', permissions: ['*'], environment: $env);

        $this->withHeader('Authorization', 'Bearer '.$key['plaintext'])
            ->getJson("/v1/tenants/{$this->tenant->public_id}/environments/{$env->public_id}/monitors")->assertOk();
    }

    public function test_signup_records_acceptance_and_platform_console_shows_it(): void
    {
        Mail::fake();
        config(['signup.mode' => 'open']);
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);

        $this->post('/cadastro', [
            'name' => 'Ana', 'email' => 'ana@acme.com.br', 'organization' => 'Acme Ltda',
            'password' => 'uma-senha-longa', 'password_confirmation' => 'uma-senha-longa', 'accept_terms' => '1',
        ])->assertRedirect('/cadastro/enviado');

        $row = DB::table('terms_acceptances')->where('email', 'ana@acme.com.br')->first();
        $this->assertSame('signup', $row->method);
        $this->assertSame('2026-11-09', $row->version);

        $admin = User::factory()->create(['is_platform_admin' => true]);
        $this->actingAs($admin)->postJson('/v1/me/terms-acceptance', ['accept' => true, 'version' => '2026-11-09'])->assertOk();
        $users = collect($this->actingAs($admin)->getJson('/v1/platform/users?q=ana')->assertOk()->json('data'));
        $this->assertSame('2026-11-09', $users->firstWhere('email', 'ana@acme.com.br')['terms_accepted']['version']);
    }

    public function test_acceptance_survives_user_deletion(): void
    {
        $this->accept()->assertOk();
        $this->user->delete();

        $row = DB::table('terms_acceptances')->first();
        $this->assertNull($row->user_id);
        $this->assertNotEmpty($row->email);
    }
}
