<?php

declare(strict_types=1);

namespace Rasuvaeff\Schema;

/**
 * The outcome of {@see SchemaInterface::parse()}: either the parsed value
 * or a list of errors — never an exception for a validation failure.
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
     * @param non-empty-list<ParseError> $errors
     *
     * @return self<mixed>
     */
    public static function failed(array $errors): self
    {
        return new self(ok: false, value: null, errors: $errors);
    }
}
