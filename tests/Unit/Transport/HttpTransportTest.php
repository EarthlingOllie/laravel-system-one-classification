<?php

declare(strict_types=1);

use EarthlingOllie\SystemOneClassification\Exceptions\HostUnavailable;
use EarthlingOllie\SystemOneClassification\Tests\Support\RecordingSleeper;
use EarthlingOllie\SystemOneClassification\Transport\HttpTransport;
use EarthlingOllie\SystemOneClassification\Transport\Response;
use EarthlingOllie\SystemOneClassification\Transport\RetryPolicy;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;

it('posts the body as JSON to the path under the base URL and returns status and raw body', function (): void {
    $http = new Factory;
    $http->fake(['*' => Factory::response('{"answers":{}}', 200)]);

    $response = (new HttpTransport($http, 'http://laya.test:8000'))->post('/v1/systemone', ['state' => 'x', 'questions' => []]);

    expect($response)->toEqual(new Response(200, '{"answers":{}}'));
    $http->assertSent(fn (Request $request): bool => $request->url() === 'http://laya.test:8000/v1/systemone'
        && $request->method() === 'POST'
        && $request->body() === '{"state":"x","questions":[]}'
        && $request->hasHeader('Content-Type', 'application/json')
        && $request->hasHeader('Accept', 'application/json')
        && ! $request->hasHeader('Authorization'));
});

it('joins a base URL with a trailing slash or a path prefix', function (string $base, string $url): void {
    $http = new Factory;
    $http->fake(['*' => Factory::response('{}')]);

    (new HttpTransport($http, $base))->post('/v1/systemone', []);

    $http->assertSent(fn (Request $request): bool => $request->url() === $url);
})->with([
    ['http://laya.test/', 'http://laya.test/v1/systemone'],
    ['https://proxy.test/laya', 'https://proxy.test/laya/v1/systemone'],
]);

it('sends the API key as a bearer token when one is configured', function (): void {
    $http = new Factory;
    $http->fake(['*' => Factory::response('{}')]);

    (new HttpTransport($http, 'http://laya.test', 'secret-key'))->post('/v1/systemone', []);

    $http->assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer secret-key'));
});

it('sends no Authorization header for an empty key', function (): void {
    $http = new Factory;
    $http->fake(['*' => Factory::response('{}')]);

    (new HttpTransport($http, 'http://laya.test', ''))->post('/v1/systemone', []);

    $http->assertSent(fn (Request $request): bool => ! $request->hasHeader('Authorization'));
});

it('returns error statuses as responses instead of throwing', function (int $status): void {
    $http = new Factory;
    $http->fake(['*' => Factory::response('{"detail":"no"}', $status)]);

    expect((new HttpTransport($http, 'http://laya.test'))->post('/v1/systemone', []))
        ->toEqual(new Response($status, '{"detail":"no"}'));
})->with([401, 422, 500, 503]);

it('raises HostUnavailable when the server cannot be reached', function (): void {
    $http = new Factory;
    $http->fake(['*' => Factory::failedConnection('Connection refused')]);

    expect(fn () => (new HttpTransport($http, 'http://laya.test:8000'))->post('/v1/systemone', []))
        ->toThrow(HostUnavailable::class, 'The model server at http://laya.test:8000 could not be reached: Connection refused');
});

it('keeps the URL credentials, query secrets and API key out of HostUnavailable', function (): void {
    $http = new Factory;
    // Guzzle 7 appends the request URI, query string included, to a connection error.
    $http->fake(['*' => Factory::failedConnection('cURL error 7: refused for http://***@laya.test/laya?api_key=q-secret/v1/systemone (key sk-bearer)')]);
    $transport = new HttpTransport($http, 'http://ollie:s3cret-pass@laya.test/laya?api_key=q-secret', 'sk-bearer');

    try {
        $transport->post('/v1/systemone', []);
        $this->fail('Expected HostUnavailable.');
    } catch (HostUnavailable $unavailable) {
        expect($unavailable->getMessage())->toBe('The model server at http://***@laya.test/laya?api_key=*** could not be reached: cURL error 7: refused for http://***@laya.test/laya?api_key=*** (key ***)')
            ->and($unavailable->getPrevious())->toBeNull()
            ->and((string) $unavailable)->not->toContain('s3cret-pass')->not->toContain('q-secret')->not->toContain('sk-bearer')->not->toContain('ollie');
    }
});

it('raises HostUnavailable for a real refused connection, with no secret anywhere in it', function (): void {
    // Port 9 (discard) on loopback: nothing listens there in a test environment.
    $transport = new HttpTransport(new Factory, 'http://ollie:s3cret-pass@127.0.0.1:9/laya?api_key=q-secret', 'sk-bearer', 2.0);

    try {
        $transport->post('/v1/systemone', []);
        $this->fail('Expected HostUnavailable.');
    } catch (HostUnavailable $unavailable) {
        expect($unavailable->status)->toBeNull()
            ->and($unavailable->getMessage())->toStartWith('The model server at http://***@127.0.0.1:9/laya?api_key=*** could not be reached: cURL error 7: ')
            ->and($unavailable->getPrevious())->toBeNull()
            ->and((string) $unavailable)->not->toContain('s3cret-pass')->not->toContain('q-secret')->not->toContain('sk-bearer')->not->toContain('ollie');
    }
});

it('applies the configured timeout', function (): void {
    $http = new Factory;
    $options = null;
    $http->fake(function (Request $request, array $sent) use (&$options) {
        $options = $sent;

        return Factory::response('{}');
    });

    (new HttpTransport($http, 'http://laya.test', timeout: 12.5))->post('/v1/systemone', []);

    expect($options)->toBeArray()->toHaveKey('timeout', 12.5);
});

it('defaults to a 30 second timeout', function (): void {
    $http = new Factory;
    $options = null;
    $http->fake(function (Request $request, array $sent) use (&$options) {
        $options = $sent;

        return Factory::response('{}');
    });

    (new HttpTransport($http, 'http://laya.test'))->post('/v1/systemone', []);

    expect($options)->toBeArray()->toHaveKey('timeout', 30.0);
});

it('tries a 5xx again after each backoff delay, and returns the last response once out of retries', function (int $status): void {
    $http = new Factory;
    $http->fake(['*' => $http->sequence()
        ->push('{"detail":"one"}', $status)
        ->push('{"detail":"two"}', $status)
        ->push('{"detail":"three"}', $status)]);
    $sleeper = new RecordingSleeper;

    $response = (new HttpTransport($http, 'http://laya.test', retry: new RetryPolicy(2, 100), sleeper: $sleeper))->post('/v1/systemone', ['state' => 'x']);

    expect($response)->toEqual(new Response($status, '{"detail":"three"}'))
        ->and($sleeper->waits())->toBe([100_000, 200_000]);
    $http->assertSentCount(3);
    $http->assertSent(fn (Request $request): bool => $request->body() === '{"state":"x"}');
})->with([500, 502, 503, 504, 599]);

it('returns the first answer that is not a 5xx, without waiting again', function (int $status): void {
    $http = new Factory;
    $http->fake(['*' => $http->sequence()
        ->push('{"detail":"server busy"}', 503)
        ->push('{"detail":"later"}', $status)
        ->push('{"detail":"never asked"}', 503)]);
    $sleeper = new RecordingSleeper;

    $response = (new HttpTransport($http, 'http://laya.test', retry: new RetryPolicy(5, 40), sleeper: $sleeper))->post('/v1/systemone', []);

    expect($response)->toEqual(new Response($status, '{"detail":"later"}'))
        ->and($sleeper->waits())->toBe([40_000]);
    $http->assertSentCount(2);
})->with([200, 401, 422, 499]);

it('never tries a 4xx again: the same request would be refused again', function (int $status): void {
    $http = new Factory;
    $http->fake(['*' => $http->sequence()->push('{"detail":"no"}', $status)->push('{}', 200)]);
    $sleeper = new RecordingSleeper;

    $response = (new HttpTransport($http, 'http://laya.test', retry: new RetryPolicy(3, 10), sleeper: $sleeper))->post('/v1/systemone', []);

    expect($response->status)->toBe($status)
        ->and($sleeper->waits())->toBe([]);
    $http->assertSentCount(1);
})->with([400, 401, 403, 404, 413, 422, 429]);

it('tries again when the host cannot be reached, and answers with the first response it gets', function (): void {
    $http = new Factory;
    $http->fake(['*' => $http->sequence()->pushFailedConnection('Connection refused')->push('{"answers":{}}', 200)]);
    $sleeper = new RecordingSleeper;

    $response = (new HttpTransport($http, 'http://laya.test', retry: new RetryPolicy(1, 75), sleeper: $sleeper))->post('/v1/systemone', []);

    expect($response)->toEqual(new Response(200, '{"answers":{}}'))
        ->and($sleeper->waits())->toBe([75_000]);
});

it('raises HostUnavailable naming the attempts once every retry failed to connect', function (): void {
    $http = new Factory;
    $http->fake(['*' => $http->sequence()
        ->pushFailedConnection('refused 1')
        ->pushFailedConnection('refused 2')
        ->pushFailedConnection('refused 3 for key sk-bearer')]);
    $sleeper = new RecordingSleeper;
    $transport = new HttpTransport($http, 'http://laya.test:8000', 'sk-bearer', retry: new RetryPolicy(2, 5), sleeper: $sleeper);

    expect(fn () => $transport->post('/v1/systemone', []))
        ->toThrow(HostUnavailable::class, 'The model server at http://laya.test:8000 could not be reached after 3 attempts: refused 3 for key ***')
        ->and($sleeper->waits())->toBe([5_000, 10_000]);
});

it('gives the last outcome when failed connections and 5xx answers alternate', function (): void {
    $sleeper = new RecordingSleeper;
    $http = new Factory;
    $http->fake(['*' => $http->sequence()->push('{"detail":"inference failed"}', 500)->pushFailedConnection('timed out')]);

    expect(fn () => (new HttpTransport($http, 'http://laya.test', retry: new RetryPolicy(1, 1), sleeper: $sleeper))->post('/v1/systemone', []))
        ->toThrow(HostUnavailable::class, 'The model server at http://laya.test could not be reached after 2 attempts: timed out');

    $http = new Factory;
    $http->fake(['*' => $http->sequence()->pushFailedConnection('timed out')->push('{"detail":"inference failed"}', 500)]);

    expect((new HttpTransport($http, 'http://laya.test', retry: new RetryPolicy(1, 1), sleeper: $sleeper))->post('/v1/systemone', []))
        ->toEqual(new Response(500, '{"detail":"inference failed"}'))
        ->and($sleeper->waits())->toBe([1_000, 1_000]);
});

it('sends once and waits for nothing without a retry policy', function (): void {
    $http = new Factory;
    $http->fake(['*' => $http->sequence()->push('{"detail":"busy"}', 503)->push('{}', 200)]);
    $sleeper = new RecordingSleeper;

    expect((new HttpTransport($http, 'http://laya.test', sleeper: $sleeper))->post('/v1/systemone', [])->status)->toBe(503)
        ->and($sleeper->waits())->toBe([]);
    $http->assertSentCount(1);
});

it('sends the same bearer token with every attempt', function (): void {
    $http = new Factory;
    $http->fake(['*' => $http->sequence()->push('{}', 502)->push('{}', 502)->push('{}', 200)]);

    (new HttpTransport($http, 'http://laya.test', 'secret-key', retry: new RetryPolicy(2), sleeper: new RecordingSleeper))->post('/v1/systemone', []);

    $headers = $http->recorded()->map(fn (array $sent): array => $sent[0]->header('Authorization'))->all();

    expect($headers)->toBe([['Bearer secret-key'], ['Bearer secret-key'], ['Bearer secret-key']]);
});
