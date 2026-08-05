<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Sanctum's RequestGuard caches the resolved user for the lifetime of the guard
     * instance, so switching Bearer tokens mid-test (e.g. admin -> teacher) within the
     * same test method won't re-resolve unless the guard cache is cleared first.
     */
    protected function as(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}");
    }
}
