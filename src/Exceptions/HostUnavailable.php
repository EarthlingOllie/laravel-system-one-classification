<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Exceptions;

use RuntimeException;
use Throwable;

/** The model server could not be reached, or answered with a 5xx. */
final class HostUnavailable extends RuntimeException implements SystemOneException
{
    private function __construct(
        string $message,
        public readonly ?int $status = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function unreachable(string $url, Throwable $previous): self
    {
        return new self("The model server at {$url} could not be reached: {$previous->getMessage()}", null, $previous);
    }

    public static function serverError(int $status, ?string $detail): self
    {
        return new self("The model server is unavailable (HTTP {$status})".($detail === null ? '.' : ": {$detail}"), $status);
    }
}
