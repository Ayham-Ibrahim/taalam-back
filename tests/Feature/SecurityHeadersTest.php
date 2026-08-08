<?php

namespace Tests\Feature;

use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    public function test_api_responses_carry_standard_security_headers(): void
    {
        $response = $this->getJson('/api/meta/stats');

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeader('Cross-Origin-Opener-Policy', 'same-origin');
        $response->assertHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");
    }
}
