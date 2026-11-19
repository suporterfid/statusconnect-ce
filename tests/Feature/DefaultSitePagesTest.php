<?php

namespace Tests\Feature;

use App\Application\Legal\LegalDocuments;
use App\Http\Views\DefaultLandingPage;
use App\Http\Views\LandingPage;
use Tests\TestCase;

/**
 * The core's own home page and legal texts (#250): what an installation shows
 * without the enterprise edition. Bound explicitly, so the test checks the same
 * pages whether or not app/Enterprise is present.
 */
class DefaultSitePagesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->singleton(LandingPage::class, DefaultLandingPage::class);
        $this->app->singleton(LegalDocuments::class, fn () => new LegalDocuments());
        config()->set('legal.company_name', '');
        config()->set('signup.mode', 'closed');
    }

    public function test_home_page_offers_sign_in_without_pricing(): void
    {
        $this->get('/')->assertOk()
            ->assertSee('/app/login', false)
            ->assertSee('Monitoramento de disponibilidade')
            ->assertDontSee('/cadastro', false)
            ->assertDontSee('#planos', false)
            ->assertDontSee('R$');

        $this->get('/en')->assertOk()->assertSee('Uptime monitoring and public status pages.');
    }

    public function test_home_page_links_sign_up_when_open(): void
    {
        config()->set('signup.mode', 'open');

        $this->get('/')->assertOk()->assertSee('/cadastro', false);
    }

    public function test_neutral_legal_pages_render_with_configured_company(): void
    {
        $this->get('/termos')->assertOk()->assertSee('Termos de Uso')->assertSee('o operador deste serviço');
        $this->get('/privacy')->assertOk()->assertSee('Privacy Policy');

        config()->set('legal.company_name', 'Acme Monitoring Ltd.');
        $this->get('/terms')->assertOk()->assertSee('Acme Monitoring Ltd.')->assertDontSee('Asaas');
        $this->get('/privacidade')->assertOk()->assertSee('Acme Monitoring Ltd.');
    }

    public function test_json_health_check_still_available(): void
    {
        $this->get('/v1/platform/health')->assertOk()->assertJson(['status' => 'ok']);
    }
}
