<?php

declare(strict_types=1);

namespace Rasuvaeff\Schema;

/**
 * The outcome of {@see SchemaInterface::parse()}: either the parsed value
 * or a list of errors — never an exception for a validation failure.
 *
 * Invariant: `ok === true` implies `errors === []` and `value` is the
 * parsed (normalized) value; `ok === false` implies `value === null` and
 * `errors` is never empty by construction (every internal caller passes a
 * literal list with at least one error).
 *
 * @template-covariant TValue
 *
 * @api
 */
final readonly class ParseResult
{
    /**
     * @param list<ParseError> $errors
     */
    private function __construct(
        public bool $ok,
        public mixed $value,
        public array $errors,
    ) {}

    /**
     * @template T
     *
     * @param T $value
     *
     * @return self<T>
     */
    public static function ok(mixed $value): self
    {
        return new self(ok: true, value: $value, errors: []);
    }

    /**
     * @param list<ParseError> $errors never empty by construction
     *
     * @return self<never>
     */
    public static function failed(array $errors): self
    {
        /** @var self<never> $result */
        $result = new self(ok: false, value: null, errors: $errors);

        return $result;
    }
}
