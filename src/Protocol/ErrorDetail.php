<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Protocol;

use function array_filter;
use function array_is_list;
use function implode;
use function is_array;
use function is_int;
use function is_string;
use function json_decode;
use function json_validate;
use function mb_strlen;
use function mb_substr;
use function preg_replace;
use function str_starts_with;
use function trim;

/**
 * The message a server put in an error response, for RejectedByModel and HostUnavailable.
 *
 * laya-serve and Jev answer `{"detail": "..."}`. The other shapes a host or a gateway in front
 * of it may use are read too: FastAPI's validation errors (`{"detail": [{"loc": [...], "msg":
 * "..."}]}`, read as `loc: msg` pairs, leaving out the `input` they echo, which may hold the
 * context), `{"message": "..."}`, `{"error": "..."}` and `{"error": {"message": "..."}}`. A body
 * that is not JSON is the message itself, its whitespace collapsed and cut to MAX_TEXT_LENGTH
 * characters, unless it is an HTML page.
 */
final readonly class ErrorDetail
{
    public const int MAX_TEXT_LENGTH = 200;

    /** The message in the raw body, or null when it holds none. */
    public static function of(string $body): ?string
    {
        if (! json_validate($body)) {
            return self::text($body);
        }

        $decoded = json_decode($body, true);
        $fields = is_array($decoded) ? $decoded : [];

        return self::message($fields['detail'] ?? null)
            ?? self::message($fields['message'] ?? null)
            ?? self::message($fields['error'] ?? null);
    }

    private static function message(mixed $value): ?string
    {
        if (is_string($value)) {
            $value = trim($value);

            return $value === '' ? null : $value;
        }

        if (! is_array($value)) {
            return null;
        }

        return array_is_list($value) ? self::validationErrors($value) : self::message($value['message'] ?? null);
    }

    /** @param  list<mixed>  $errors */
    private static function validationErrors(array $errors): ?string
    {
        $reasons = [];

        foreach ($errors as $error) {
            $message = is_array($error) ? ($error['msg'] ?? null) : null;

            if (! is_string($message)) {
                continue;
            }

            $location = $error['loc'] ?? [];
            $path = implode('.', array_filter(is_array($location) ? $location : [], static fn (mixed $part): bool => is_string($part) || is_int($part)));
            $reasons[] = $path === '' ? $message : "{$path}: {$message}";
        }

        return $reasons === [] ? null : implode('; ', $reasons);
    }

    private static function text(string $body): ?string
    {
        $text = trim((string) preg_replace('/\s+/', ' ', $body));

        if ($text === '' || str_starts_with($text, '<')) {
            return null;
        }

        return mb_strlen($text) > self::MAX_TEXT_LENGTH ? mb_substr($text, 0, self::MAX_TEXT_LENGTH).'…' : $text;
    }
}
