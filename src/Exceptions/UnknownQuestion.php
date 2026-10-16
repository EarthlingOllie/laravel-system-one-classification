<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Exceptions;

use OutOfBoundsException;

/** Result::get() was given a question the decision did not ask. */
final class UnknownQuestion extends OutOfBoundsException implements SystemOneException
{
    /**
     * @param  list<string>  $asked
     */
    public static function notAsked(string $key, array $asked): self
    {
        $keys = $asked === [] ? 'none' : "'".implode("', '", $asked)."'";

        return new self("The result has no answer for the question '{$key}': it was not part of the decision. Asked: {$keys}.");
    }

    public static function otherType(string $key, string $held, string $expected): self
    {
        return new self("The answer to '{$key}' is a {$held}, not the {$expected} this question expects: it was not part of the decision.");
    }
}
