<?php

namespace Tests\Support;

use LogicException;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Stripe\Util\CaseInsensitiveArray;

/**
 * Stands in for the Stripe SDK's HTTP layer, so tests run the real StripeClient (request
 * building, response parsing) without touching the network. Each request takes the next
 * queued response and is recorded for assertions. A request with nothing queued fails the
 * test.
 */
final class FakeStripeHttpClient implements ClientInterface
{
    /** @var list<array{method: string, path: string, params: array<mixed>, headers: list<string>}> */
    public array $requests = [];

    /** @var list<array{0: int, 1: array<string, mixed>}> */
    private array $responses = [];

    /**
     * Install a fake as the SDK's HTTP client and return it.
     */
    public static function install(): self
    {
        $fake = new self;
        ApiRequestor::setHttpClient($fake);

        return $fake;
    }

    /**
     * Queue a response: an HTTP status and the JSON body Stripe would send.
     *
     * @param  array<string, mixed>  $body
     */
    public function respond(int $status, array $body): self
    {
        $this->responses[] = [$status, $body];

        return $this;
    }

    /**
     * @return array{0: string, 1: int, 2: CaseInsensitiveArray}
     */
    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null): array
    {
        $this->requests[] = [
            'method' => strtoupper($method),
            'path' => (string) parse_url($absUrl, PHP_URL_PATH),
            'params' => $params,
            'headers' => $headers,
        ];

        $next = array_shift($this->responses)
            ?? throw new LogicException("Unexpected Stripe request: {$method} {$absUrl}");

        return [json_encode($next[1], JSON_THROW_ON_ERROR), $next[0], new CaseInsensitiveArray([])];
    }

    /**
     * The recorded requests as "METHOD /path".
     *
     * @return list<string>
     */
    public function calls(): array
    {
        return array_map(fn (array $r) => $r['method'].' '.$r['path'], $this->requests);
    }
}
