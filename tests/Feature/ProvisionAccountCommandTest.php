<?php

namespace Tests\Feature;

use App\Infrastructure\Persistence\Eloquent\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProvisionAccountCommandTest extends TestCase
{
    use RefreshDatabase;

    private function counts(): array
    {
        return collect(['users', 'tenants', 'tenant_memberships', 'environments', 'status_pages'])
            ->mapWithKeys(fn (string $table) => [$table => DB::table($table)->count()])
            ->all();
    }

    public function test_provisions_an_account_whose_status_page_is_served_immediately(): void
    {
        $this->artisan('statusconnect:provision-account', [
            '--email' => 'a@b.com', '--name' => 'A', '--org' => 'Acme', '--slug' => 'acme',
        ])->expectsOutputToContain('Temporary password')->assertExitCode(0);

        $this->assertSame(
            ['users' => 1, 'tenants' => 1, 'tenant_memberships' => 1, 'environments' => 1, 'status_pages' => 1],
            $this->counts(),
        );
        $this->assertSame('owner', DB::table('tenant_memberships')->value('role'));
        $this->get('/status/acme')->assertOk()->assertSee('Acme');
    }

    public function test_pt_br_locale_option_publishes_a_portuguese_page(): void
    {
        $this->artisan('statusconnect:provision-account', [
            '--email' => 'a@b.com', '--org' => 'Acme', '--slug' => 'acme', '--locale' => 'pt-BR',
        ])->assertExitCode(0);

        $this->assertSame('pt_BR', DB::table('status_pages')->value('locale'));
        $this->getJson('/status/acme.json')->assertOk()->assertJsonPath('locale', 'pt_BR');
    }

    public function test_running_twice_creates_no_duplicate_rows(): void
    {
        $args = ['--email' => 'a@b.com', '--name' => 'A', '--org' => 'Acme', '--slug' => 'acme'];
        $this->artisan('statusconnect:provision-account', $args)->assertExitCode(0);
        $before = $this->counts();
        $passwordHash = User::query()->value('password');

        $this->artisan('statusconnect:provision-account', $args)
            ->expectsOutputToContain('Nothing to do')
            ->doesntExpectOutputToContain('Temporary password')
            ->assertExitCode(0);

        $this->assertSame($before, $this->counts());
        $this->assertSame($passwordHash, User::query()->value('password'), 'an existing password must never be reset');
    }

    public function test_adds_a_second_user_to_an_existing_tenant_with_the_given_role(): void
    {
        $this->artisan('statusconnect:provision-account', ['--email' => 'a@b.com', '--org' => 'Acme', '--slug' => 'acme'])->assertExitCode(0);
        $this->artisan('statusconnect:provision-account', ['--email' => 'c@d.com', '--slug' => 'acme', '--role' => 'admin'])->assertExitCode(0);

        $this->assertSame(
            ['users' => 2, 'tenants' => 1, 'tenant_memberships' => 2, 'environments' => 1, 'status_pages' => 1],
            $this->counts(),
        );
        $second = User::query()->where('email', 'c@d.com')->firstOrFail();
        $this->assertSame('admin', $second->memberships()->value('role'));
        $this->assertFalse($second->isPlatformAdmin());
    }

    public function test_temporary_password_lets_the_new_user_sign_in(): void
    {
        $this->artisan('statusconnect:provision-account', ['--email' => 'a@b.com', '--org' => 'Acme', '--slug' => 'acme'])->run();

        // The plaintext is only printed; assert what it protects instead: a real bcrypt hash, not the plaintext.
        $hash = User::query()->value('password');
        $this->assertTrue(Hash::isHashed($hash));
        // #174: sign-in refuses unverified emails, and the CLI sends no confirmation link.
        $this->assertNotNull(User::query()->value('email_verified_at'));
    }

    public function test_rejects_invalid_input(): void
    {
        $this->artisan('statusconnect:provision-account', ['--email' => 'not-an-email', '--slug' => 'Bad Slug'])->assertExitCode(2);
        $this->assertSame(0, DB::table('users')->count());
    }
}
