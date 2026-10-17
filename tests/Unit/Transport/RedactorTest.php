<?php

declare(strict_types=1);

use EarthlingOllie\SystemOneClassification\Transport\Redactor;

it('masks the userinfo of any URL in the text', function (string $text, string $redacted): void {
    expect((new Redactor)->redact($text))->toBe($redacted);
})->with([
    'user and password' => ['at http://user:s3cret@laya.test:8000/v1 now', 'at http://***@laya.test:8000/v1 now'],
    'token as user' => ['https://tok3n@laya.test', 'https://***@laya.test'],
    'unencoded @ in the password' => ['http://u:p@ss@laya.test/x', 'http://***@laya.test/x'],
    'already masked by Guzzle' => ['for http://***@laya.test/v1', 'for http://***@laya.test/v1'],
    'two URLs' => ['http://a:b@one.test and https://c:d@two.test', 'http://***@one.test and https://***@two.test'],
]);

it('masks every query value of a URL in the text, whatever the parameter is called', function (string $text, string $redacted): void {
    expect((new Redactor)->redact($text))->toBe($redacted);
})->with([
    'api_key' => ['http://laya.test/v1?api_key=q-secret', 'http://laya.test/v1?api_key=***'],
    'several, with a fragment' => ['http://laya.test/?a=1&sig=xyz#top', 'http://laya.test/?a=***&sig=***#top'],
    'followed by text, up to the next space' => ['for http://laya.test/?x-auth=abc refused', 'for http://laya.test/?x-auth=*** refused'],
    'a bare flag' => ['http://laya.test/?debug&token=t', 'http://laya.test/?debug&token=***'],
]);

it('masks secret-looking key=value pairs outside URLs', function (string $text, string $redacted): void {
    expect((new Redactor)->redact($text))->toBe($redacted);
})->with([
    ['sent api_key=abc123 to the host', 'sent api_key=*** to the host'],
    ['API-KEY=abc', 'API-KEY=***'],
    ['access_token=abc&x=1', 'access_token=***&x=1'],
    ['password=hunter2', 'password=***'],
]);

it('masks bearer tokens', function (): void {
    expect((new Redactor)->redact('Authorization: Bearer abc.def-123 sent'))->toBe('Authorization: Bearer *** sent')
        ->and((new Redactor)->redact('"bearer abc"'))->toBe('"bearer ***"');
});

it('masks the connection secrets wherever they appear, raw or URL-encoded', function (): void {
    $redactor = Redactor::forConnection('http://ollie:p%40ss word@laya.test', 'sk-live/key+1');

    expect($redactor->redact('key sk-live/key+1, encoded sk-live%2Fkey%2B1, user ollie, password p@ss word / p%40ss word / p%40ss%20word'))
        ->toBe('key ***, encoded ***, user ***, password *** / *** / ***');
});

it('ignores an empty key and a URL without userinfo', function (?string $key): void {
    $text = 'The model server at http://laya.test:8000 could not be reached: Connection refused';

    expect(Redactor::forConnection('http://laya.test:8000', $key)->redact($text))->toBe($text);
})->with([null, '']);

it('masks the longest secret first so a shorter one cannot split it', function (): void {
    expect((new Redactor('abc', 'abcdef'))->redact('x abcdef y abc'))->toBe('x *** y ***');
});

it('leaves text without secrets alone', function (): void {
    $text = 'cURL error 7: Failed to connect to 127.0.0.1 port 9 (see https://curl.se/libcurl/c/libcurl-errors.html) for http://127.0.0.1:9/v1/systemone';

    expect((new Redactor)->redact($text))->toBe($text);
});

it('masks the whole text when a pattern cannot run', function (): void {
    $jit = ini_get('pcre.jit');
    $limit = ini_get('pcre.backtrack_limit');
    ini_set('pcre.jit', '0');
    ini_set('pcre.backtrack_limit', '1');

    try {
        expect((new Redactor)->redact('see http://user:secret@laya.test/?api_key=k'))->toBe('***');
    } finally {
        ini_set('pcre.jit', (string) $jit);
        ini_set('pcre.backtrack_limit', (string) $limit);
    }
});

it('masks every string inside a JSON body and keeps it valid JSON', function (): void {
    $body = '{"detail":"bad key sk-1 at http://u:p@laya.test/?sig=abc","errors":[{"msg":"token=t0k","loc":["body",0]}],"n":1.0,"ok":false,"none":null,"empty":{},"list":[]}';

    expect(Redactor::forConnection('http://laya.test', 'sk-1')->redactBody($body))
        ->toBe('{"detail":"bad key *** at http://***@laya.test/?sig=***","errors":[{"msg":"token=***","loc":["body",0]}],"n":1.0,"ok":false,"none":null,"empty":{},"list":[]}');
});

it('keeps slashes and non-ASCII text in a JSON body as they were', function (): void {
    expect((new Redactor('k'))->redactBody('{"detail":"Schlüssel k für /v1/systemone"}'))
        ->toBe('{"detail":"Schlüssel *** für /v1/systemone"}');
});

it('keeps the body JSON when masking a key splits a character of the text', function (): void {
    // The key is one byte of "é": masking it leaves invalid UTF-8, substituted, not an error.
    expect((new Redactor("\xA9"))->redactBody('{"detail":"café"}'))->toBe("{\"detail\":\"caf\u{FFFD}***\"}");
});

it('masks a JSON string body and leaves other JSON scalars alone', function (string $body, string $redacted): void {
    expect((new Redactor('k3y'))->redactBody($body))->toBe($redacted);
})->with([
    ['"no k3y"', '"no ***"'],
    ['42', '42'],
    ['null', 'null'],
]);

it('masks a body that is not JSON as text', function (string $body, string $redacted): void {
    expect((new Redactor('k3y'))->redactBody($body))->toBe($redacted);
})->with([
    ['<html>Unauthorized: k3y</html>', '<html>Unauthorized: ***</html>'],
    ['{"detail": "k3y"', '{"detail": "***"'],
    ['', ''],
]);
