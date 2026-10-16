<?php

declare(strict_types=1);

use EarthlingOllie\SystemOneClassification\Exceptions\HostUnavailable;
use EarthlingOllie\SystemOneClassification\Transport\HttpTransport;
use EarthlingOllie\SystemOneClassification\Transport\Response;
use Illuminate\Http\Client\ConnectionException;
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

it('raises HostUnavailable for a real refused connection, with the cause attached', function (): void {
    // Port 9 (discard) on loopback: nothing listens there in a test environment.
    $transport = new HttpTransport(new Factory, 'http://127.0.0.1:9', timeout: 2.0);

    try {
        $transport->post('/v1/systemone', []);
        $this->fail('Expected HostUnavailable.');
    } catch (HostUnavailable $unavailable) {
        expect($unavailable->status)->toBeNull()
            ->and($unavailable->getPrevious())->toBeInstanceOf(ConnectionException::class);
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
