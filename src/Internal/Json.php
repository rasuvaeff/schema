<?php

declare(strict_types=1);

namespace Rasuvaeff\Schema\Internal;

use Rasuvaeff\Schema\ParseError;

/**
 * @internal
 */
final class Json
{
    /**
     * @return array{0: mixed}|ParseError the decoded value boxed (so a
     *     decoded `null` stays distinguishable), or the root error
     */
    public static function decode(string $json): array|ParseError
    {
        try {
            return [\json_decode($json, associative: true, flags: \JSON_THROW_ON_ERROR)];
        } catch (\JsonException $exception) {
            // json_last_error messages name the problem kind, not the input.
            return new ParseError([], \sprintf('Invalid JSON (%s)', $exception->getMessage()));
        }
    }
}
