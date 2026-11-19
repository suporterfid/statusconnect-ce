<?php

namespace Tests\Feature;

use Tests\TestCase;

class ScaffoldTest extends TestCase
{
    public function test_home_route_serves_the_landing_page(): void
    {
        $this->get('/')
            ->assertStatus(200)
            ->assertHeader('Content-Type', 'text/html; charset=UTF-8')
            ->assertSee('StatusConnect');
    }

    public function test_api_health_route_returns_ok(): void
    {
        $response = $this->get('/v1/platform/health');

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'ok',
            ]);
    }
}
