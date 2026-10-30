<?php

namespace Tests;

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Disable CSRF verification only, leaving authentication and permission
     * middleware in place.
     *
     * The `api-v1` routes sit in the `web` group, so every write needs a
     * token a test client does not carry. Two CSRF middleware run on those
     * routes — Laravel 11's `ValidateCsrfToken` from the web group and the
     * explicitly appended `VerifyCsrfToken` — so both are replaced.
     *
     * `withoutMiddleware()` is not used because it disables every middleware,
     * which would hide the authorisation assertions these tests rely on.
     */
    protected function withoutCsrf(): static
    {
        $passThrough = new class
        {
            public function handle($request, $next)
            {
                return $next($request);
            }
        };

        $this->app->instance(ValidateCsrfToken::class, $passThrough);
        $this->app->instance(VerifyCsrfToken::class, $passThrough);

        return $this;
    }
}
