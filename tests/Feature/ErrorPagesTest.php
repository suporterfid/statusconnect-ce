<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * HTML error pages follow the public design system (#149): pt-BR by default,
 * English for /en paths or English browsers, no Laravel default template.
 */
final class ErrorPagesTest extends TestCase
{
    public function test_not_found_page_uses_the_site_design_in_portuguese(): void
    {
        $this->get('/pagina-que-nao-existe', ['Accept-Language' => 'pt-BR'])
            ->assertNotFound()
            ->assertSee('Página não encontrada')
            ->assertSee('Erro 404')
            ->assertSee('/css/site.css', false)
            ->assertSee('roundel is-slow', false)
            ->assertSee('<meta name="robots" content="noindex">', false)
            ->assertDontSee('Not Found');
    }

    public function test_english_for_en_paths_and_english_browsers(): void
    {
        $this->get('/en/missing')->assertNotFound()->assertSee('Page not found');
        $this->get('/missing', ['Accept-Language' => 'en-US,en;q=0.9'])->assertNotFound()->assertSee('Page not found');
    }

    public function test_server_error_offers_retry_and_shows_the_struck_roundel(): void
    {
        config(['app.debug' => false]);
        Route::get('/_boom', fn () => throw new \RuntimeException('boom'));

        $this->get('/_boom', ['Accept-Language' => 'pt-BR'])
            ->assertStatus(500)
            ->assertSee('Algo deu errado do nosso lado')
            ->assertSee('roundel is-down', false)
            ->assertSee('href="/_boom"', false)
            ->assertDontSee('boom</', false);
    }

    public function test_unlisted_client_status_falls_back_to_the_generic_page(): void
    {
        Route::get('/_teapot', fn () => abort(418));

        $this->get('/_teapot', ['Accept-Language' => 'pt-BR'])
            ->assertStatus(418)
            ->assertSee('Erro 418')
            ->assertSee('Não foi possível atender este pedido');
    }

    public function test_api_errors_stay_json(): void
    {
        $this->getJson('/v1/does-not-exist')->assertNotFound()->assertHeader('Content-Type', 'application/json');
    }
}
