<?php

declare(strict_types=1);

namespace Rasuvaeff\Schema\Type;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\Schema\JsonSchemaExport;
use Rasuvaeff\Schema\NonRepresentable;
use Rasuvaeff\Schema\ParseError;
use Rasuvaeff\Schema\ParseResult;
use Rasuvaeff\Schema\SchemaException;
use Rasuvaeff\Schema\SchemaType;

/**
 * Integer with optional inclusive bounds.
 *
 * @extends SchemaType<int>
 *
 * @api
 */
final class IntSchema extends SchemaType
{
    /**
     * Canonical JSON integer strings only: an optional minus, no leading
     * zeros, no plus, no whitespace — and no silent overflow: a string
     * outside the int range fails the round trip through `(int)` and is
     * rejected.
     */
    private const string INT_STRING = '(^-?(0|[1-9][0-9]*)\z)';

    public function __construct(
        public readonly ?int $min = null,
        public readonly ?int $max = null,
        public readonly bool $coerce = false,
    ) {}

    public function min(int $value): self
    {
        if ($this->max !== null && $value > $this->max) {
            throw new \InvalidArgumentException('Minimum must not exceed the maximum');
        }

        return new self(min: $value, max: $this->max, coerce: $this->coerce);
    }

    public function max(int $value): self
    {
        if ($this->min !== null && $value < $this->min) {
            throw new \InvalidArgumentException('Maximum must not be below the minimum');
        }

        return new self(min: $this->min, max: $value, coerce: $this->coerce);
    }

    /**
     * Accept canonical integer strings too (`"5"`, `"-3"`) — the
     * query-string and form world — and normalize them to int. Bounds
     * apply after the coercion; the generator's domain stays int, and
     * the export keeps `type: integer` with a `NonRepresentable` `coerce`
     * entry: the fragment cannot say "strings carrying an integer are
     * fine too".
     */
    public function coerce(): self
    {
        return new self(min: $this->min, max: $this->max, coerce: true);
    }

    /**
     * `>= 1` — the named intent of every entity id, a thin wrapper over
     * `min(1)` with identical parse, generator and JSON Schema export.
     */
    public function positive(): self
    {
        return $this->min(1);
    }

    /**
     * `>= 0` — the named intent of counters and quantities, a thin wrapper
     * over `min(0)`.
     */
    public function nonNegative(): self
    {
        return $this->min(0);
    }

    #[\Override]
    public function parse(mixed $input): ParseResult
    {
        if (\is_string($input) && $this->coerce && \preg_match(self::INT_STRING, $input) === 1) {
            $coerced = (int) $input;

            if ((string) $coerced === $input) {
                $input = $coerced;
            }
        }

        if (!\is_int($input)) {
            return ParseResult::failed([
                new ParseError([], \sprintf('Expected int, got %s', \get_debug_type($input))),
            ]);
        }

        if ($this->min !== null && $input < $this->min) {
            return ParseResult::failed([
                new ParseError([], \sprintf('Int must be at least %d, got %d', $this->min, $input)),
            ]);
        }

        if ($this->max !== null && $input > $this->max) {
            return ParseResult::failed([
                new ParseError([], \sprintf('Int must be at most %d, got %d', $this->max, $input)),
            ]);
        }

        return ParseResult::ok($input);
    }
    #[\Override]
    public function parseOrThrow(mixed $input): int
    {
        $result = $this->parse($input);

        if ($result->ok) {
            /** @var int $value */
            $value = $result->value;

            return $value;
        }

        throw new SchemaException($result->errors);
    }

    #[\Override]
    public function arbitrary(): ArbitraryInterface
    {
        $min = $this->min ?? -1000;
        $max = $this->max ?? \max($min, 1000);

        return Gen::intBetween($min, $max);
    }

    #[\Override]
    public function toJsonSchema(bool $lenient = false): JsonSchemaExport
    {
        $schema = ['type' => 'integer'];

        if ($this->min !== null) {
            $schema['minimum'] = $this->min;
        }

        if ($this->max !== null) {
            $schema['maximum'] = $this->max;
        }

        if (!$this->coerce) {
            return JsonSchemaExport::of($schema);
        }

        // The fragment describes the canonical wire form; the
        // string-source leniency of parse is not representable — the
        // refine precedent: an entry, never a strict-mode throw.
        return JsonSchemaExport::of($schema, [new NonRepresentable([], 'coerce')]);
    }
}
