<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Transport;

use EarthlingOllie\SystemOneClassification\Exceptions\HostUnavailable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use SensitiveParameter;
use SensitiveParameterValue;

use function is_string;

/**
 * POSTs JSON to one System One host (laya-serve, hosted Laya or Jev) with Laravel's HTTP client.
 *
 * When the host cannot be reached or answers with a 5xx, the request is sent again as the
 * RetryPolicy says, waiting its backoff delays in between; a 4xx is returned at once.
 *
 * The API key goes out only as the bearer token. It is held as a SensitiveParameterValue, so
 * var_dump(), print_r(), var_export() and Laravel's dump() of the transport (or of the client
 * around it) never show it, serialize() refuses it, and a stack trace never prints it. The key
 * and the URL's secrets are masked in the body of any response that is not a 2xx, since a
 * server may echo them into the message that reaches an exception.
 */
final readonly class HttpTransport implements Transport
{
    private ?SensitiveParameterValue $apiKey;

    /** @param  ?string  $apiKey  sent as the bearer token; null or empty sends none */
    public function __construct(
        private Factory $http,
        private string $baseUrl,
        #[SensitiveParameter] ?string $apiKey = null,
        private float $timeout = 30.0,
        private RetryPolicy $retry = new RetryPolicy,
        private Sleeper $sleeper = new SystemSleeper,
    ) {
        $this->apiKey = $apiKey === null || $apiKey === '' ? null : new SensitiveParameterValue($apiKey);
    }

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
                $redactor = $this->redactor();

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

        $apiKey = $this->apiKey();

        if ($apiKey !== null) {
            $request = $request->withToken($apiKey);
        }

        try {
            $response = $request->post($path, $body);
        } catch (ConnectionException $exception) {
            return $exception;
        }

        $status = $response->status();
        $successful = $status >= 200 && $status < 300;

        return new Response($status, $successful ? $response->body() : $this->redactor()->redactBody($response->body()));
    }

    private function apiKey(): ?string
    {
        $apiKey = $this->apiKey?->getValue();

        return is_string($apiKey) ? $apiKey : null;
    }

    private function redactor(): Redactor
    {
        return Redactor::forConnection($this->baseUrl, $this->apiKey());
    }
}
