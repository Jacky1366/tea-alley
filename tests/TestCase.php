<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;
use Stripe\ApiRequestor;
use Tests\Support\RefusingStripeHttpClient;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // No test ever reaches Stripe: the SDK's HTTP client refuses every request unless the
        // test installs a FakeStripeHttpClient. Static in the SDK, so it's reset for each test.
        ApiRequestor::setHttpClient(new RefusingStripeHttpClient);
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
