<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Domain;

/**
 * The piece of text a decision is about (the wire's `state`).
 *
 * Only plain text for now; structured fields (`Context::fields([...])`) come later.
 */
final readonly class Context
{
    private function __construct(public string $text) {}

    public static function text(string $text): self
    {
        return new self($text);
    }
}
