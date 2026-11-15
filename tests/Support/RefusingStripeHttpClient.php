<?php

namespace Tests\Support;

use LogicException;
use Stripe\HttpClient\ClientInterface;

/**
 * The Stripe SDK's HTTP client in every test unless a test installs FakeStripeHttpClient:
 * any request fails the test. It throws a LogicException, not a Stripe exception, so the
 * app can't catch it and carry on as if Stripe were merely unavailable.
 */
final class RefusingStripeHttpClient implements ClientInterface
{
    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null): never
    {
        throw new LogicException("A test tried to reach Stripe: {$method} {$absUrl}. Install FakeStripeHttpClient.");
    }
}
