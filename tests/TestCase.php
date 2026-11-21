<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
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

        // No test reaches the internet through Laravel's HTTP client either. Password rules
        // ask Have I Been Pwned (Password::uncompromised()); by default it answers "not
        // breached". Tests that need another answer fake the same URL again.
        Http::preventStrayRequests();
        Http::fake(['api.pwnedpasswords.com/*' => Http::response('')]);

        // Symfony keeps the trusted host patterns in a static. A test that switches to the
        // production environment makes TrustHosts set them (from APP_URL), and without this the
        // next test inherits them: middleware that reads the host before TrustHosts runs then
        // rejects that test's host with a 400. Real requests start with none (#67).
        Request::setTrustedHosts([]);
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
