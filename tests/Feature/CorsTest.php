<?php

namespace Tests\Feature;

use Tests\TestCase;

class CorsTest extends TestCase
{
    /** الفرونت (دومين آخر) يقرأ ترويسة Date ليحسب فرق ساعة الجهاز عن السيرفر — serverClock.js */
    public function test_api_responses_expose_the_date_header_to_an_allowed_frontend(): void
    {
        config(['cors.allowed_origins' => ['https://frontend.example']]);

        $this->getJson('/api/taxonomy/subjects', ['Origin' => 'https://frontend.example'])
            ->assertHeader('Access-Control-Allow-Origin', 'https://frontend.example')
            ->assertHeader('Access-Control-Expose-Headers', 'Date');
    }
}
