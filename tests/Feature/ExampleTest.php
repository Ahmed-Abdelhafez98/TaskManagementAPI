<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * Test that the API health check endpoint works.
     */
    public function test_api_health_check_returns_successful_response(): void
    {
        $response = $this->get('/api/health');

        $response->assertStatus(200)
                 ->assertJson([
                     'status' => 'ok',
                     'service' => 'Task Management API'
                 ]);
    }
}
