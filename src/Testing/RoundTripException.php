<?php

declare(strict_types=1);

namespace Rasuvaeff\Schema\Testing;

use Rasuvaeff\Schema\ParseError;

/**
 * Thrown by {@see RoundTrip} when a parity law is violated. Carries the
 * failing stage and — when parse errors caused it — their structured
 * list, rendered exactly like a {@see \Rasuvaeff\Schema\SchemaException}
 * message. Never the values themselves.
 *
 * @api
 */
final class RoundTripException extends \RuntimeException
{
    /**
     * @param list<ParseError> $errors never empty by construction
     */
    public static function stage(string $stage, array $errors): self
    {
        $lines = [];

        foreach ($errors as $error) {
            $lines[] = $error->toPath() . ' ' . $error->message;
        }

        return new self($stage . ': ' . \implode("\n", $lines));
    }
}
