<?php

namespace Tests\Feature;

use Tests\TestCase;

class OperatorSpaRouteTest extends TestCase
{
    public function test_app_route_returns_spa_index_or_built_asset(): void
    {
        $response = $this->get('/app');

        $response->assertStatus(200);
    }
}
