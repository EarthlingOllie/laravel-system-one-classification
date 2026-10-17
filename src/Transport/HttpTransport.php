<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Transport;

use EarthlingOllie\SystemOneClassification\Exceptions\HostUnavailable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;

/**
 * POSTs JSON to one System One host (laya-serve, hosted Laya or Jev) with Laravel's HTTP client.
 *
 * When the host cannot be reached or answers with a 5xx, the request is sent again as the
 * RetryPolicy says, waiting its backoff delays in between; a 4xx is returned at once.
 */
final readonly class HttpTransport implements Transport
{
    public function __construct(
        private Factory $http,
        private string $baseUrl,
        private ?string $apiKey = null,
        private float $timeout = 30.0,
        private RetryPolicy $retry = new RetryPolicy,
        private Sleeper $sleeper = new SystemSleeper,
    ) {}

    public function post(string $path, array $body): Response
    {
        $delays = $this->retry->delays();

        for ($attempt = 1; ; $attempt++) {
            $outcome = $this->send($path, $body);
            $delay = $delays[$attempt - 1] ?? null;

            if ($outcome instanceof Response && ($outcome->status < 500 || $delay === null)) {
                return $outcome;
            }

            if ($outcome instanceof ConnectionException && $delay === null) {
                $redactor = Redactor::forConnection($this->baseUrl, $this->apiKey);

                throw HostUnavailable::unreachable($redactor->redact($this->baseUrl), $redactor->redact($outcome->getMessage()), $attempt);
            }

            $this->sleeper->sleep($delay * 1000);
        }
    }

    /**
     * One attempt: the response, whatever its status, or why nothing came back.
     *
     * @param  array<string, mixed>  $body
     */
    private function send(string $path, array $body): Response|ConnectionException
    {
        $request = $this->http->createPendingRequest()
            ->baseUrl($this->baseUrl)
            ->acceptJson()
            ->asJson()
            ->timeout($this->timeout);

        if ($this->apiKey !== null && $this->apiKey !== '') {
            $request = $request->withToken($this->apiKey);
        }

        try {
            $response = $request->post($path, $body);
        } catch (ConnectionException $exception) {
            return $exception;
        }

        return new Response($response->status(), $response->body());
    }
}
