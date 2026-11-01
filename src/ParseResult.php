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
     * @param TValue|null $value the parsed value when `ok`, `null` otherwise
     * @param list<ParseError> $errors
     */
    private function __construct(
        public bool $ok,
        public mixed $value,
        public array $errors,
    ) {}

    /**
     * The parsed value, or `$default` when the parse failed — typed access
     * without an exception: `$user = $result->valueOr(null); if ($user ===
     * null) { … }` leaves `$user` with the exact shape the Psalm plugin
     * inferred. (The property `ok` cannot narrow `value` for Psalm.) For a
     * schema whose own values may equal `$default` — `nullable()` with a
     * `null` default — check `ok` instead.
     *
     * @template TDefault
     *
     * @param TDefault $default
     *
     * @return TValue|TDefault
     */
    public function valueOr(mixed $default): mixed
    {
        if (!$this->ok) {
            return $default;
        }

        /** @var TValue $value */
        $value = $this->value;

        return $value;
    }

    /**
     * The parsed value, or a {@see SchemaException} carrying every error —
     * `$schema->parseJson($body)->orThrow()`.
     *
     * @return TValue
     *
     * @throws SchemaException when the parse failed
     */
    public function orThrow(): mixed
    {
        if (!$this->ok) {
            throw new SchemaException($this->errors);
        }

        /** @var TValue $value */
        $value = $this->value;

        return $value;
    }

    /**
     * One line for logs and exception messages: the first `$limit` errors
     * as `$.path message`, joined by `$separator`, plus `(+N more)` when
     * some were cut. Empty for a successful parse. Like every message of
     * the package it carries paths and kinds, never input values.
     *
     * @param positive-int $limit
     */
    public function summary(int $limit = 3, string $separator = '; '): string
    {
        $lines = [];

        foreach (\array_slice($this->errors, 0, $limit) as $error) {
            $lines[] = $error->__toString();
        }

        $more = \count($this->errors) - \count($lines);

        if ($more > 0) {
            $lines[] = \sprintf('(+%d more)', $more);
        }

        return \implode($separator, $lines);
    }

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
