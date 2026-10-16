<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Exceptions;

use LogicException;

use function json_encode;

/** A ResultSet read at a position it has no result for, or written to. */
final class InvalidResultSetAccess extends LogicException implements SystemOneException
{
    public static function readOnly(): self
    {
        return new self('A result set is read-only.');
    }

    public static function noResultAt(mixed $offset, int $count): self
    {
        $holds = $count === 0 ? 'no results' : "{$count} results (0 to ".($count - 1).')';

        return new self('The result set has no result at '.json_encode($offset, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."; it holds {$holds}.");
    }
}
