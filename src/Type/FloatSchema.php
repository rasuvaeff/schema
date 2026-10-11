<?php

declare(strict_types=1);

namespace Rasuvaeff\Schema\Type;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\Schema\JsonSchemaExport;
use Rasuvaeff\Schema\ParseError;
use Rasuvaeff\Schema\ParseResult;
use Rasuvaeff\Schema\SchemaException;
use Rasuvaeff\Schema\SchemaType;

/**
 * Finite float with optional inclusive bounds. NaN and both infinities
 * are rejected — they have no JSON wire form and no sane comparison
 * against the bounds. An optional `allowInt()` widens parse to whole
 * numbers (JSON's `1`), normalized to `1.0`.
 *
 * @extends SchemaType<float>
 *
 * @api
 */
final class FloatSchema extends SchemaType
{
    public function __construct(
        public readonly ?float $min = null,
        public readonly ?float $max = null,
        public readonly bool $allowInt = false,
    ) {}

    public function min(float $value): self
    {
        if (!\is_finite($value)) {
            throw new \InvalidArgumentException('Minimum must be finite');
        }

        if ($this->max !== null && $value > $this->max) {
            throw new \InvalidArgumentException('Minimum must not exceed the maximum');
        }

        return new self(min: $value, max: $this->max, allowInt: $this->allowInt);
    }

    public function max(float $value): self
    {
        if (!\is_finite($value)) {
            throw new \InvalidArgumentException('Maximum must be finite');
        }

        if ($this->min !== null && $value < $this->min) {
            throw new \InvalidArgumentException('Maximum must not be below the minimum');
        }

        return new self(min: $this->min, max: $value, allowInt: $this->allowInt);
    }

    /**
     * Accept whole numbers too (`JSON 1` vs `1.0`); the parsed value is
     * normalized to a float. The generator's domain stays float.
     */
    public function allowInt(): self
    {
        return new self(min: $this->min, max: $this->max, allowInt: true);
    }

    #[\Override]
    public function parse(mixed $input): ParseResult
    {
        if (\is_int($input) && $this->allowInt) {
            $input = (float) $input;
        }

        if (!\is_float($input)) {
            return ParseResult::failed([
                new ParseError([], \sprintf('Expected float, got %s', \get_debug_type($input))),
            ]);
        }

        if (!\is_finite($input)) {
            return ParseResult::failed([
                new ParseError([], \sprintf('Float must be finite, got %s', $input)),
            ]);
        }

        if ($this->min !== null && $input < $this->min) {
            return ParseResult::failed([
                new ParseError([], \sprintf('Float must be at least %s, got %s', $this->min, $input)),
            ]);
        }

        if ($this->max !== null && $input > $this->max) {
            return ParseResult::failed([
                new ParseError([], \sprintf('Float must be at most %s, got %s', $this->max, $input)),
            ]);
        }

        return ParseResult::ok($input);
    }

    #[\Override]
    public function parseOrThrow(mixed $input): float
    {
        $result = $this->parse($input);

        if ($result->ok) {
            /** @var float $value */
            $value = $result->value;

            return $value;
        }

        throw new SchemaException($result->errors);
    }

    #[\Override]
    public function arbitrary(): ArbitraryInterface
    {
        $min = $this->min ?? -1000.0;
        $max = $this->max ?? \max($min, 1000.0);

        // The core's float range is half-open [min, max); both parse
        // bounds are inclusive, so every generated value parses.
        return Gen::floatBetween($min, $max);
    }

    #[\Override]
    public function toJsonSchema(bool $lenient = false): JsonSchemaExport
    {
        $schema = ['type' => 'number'];

        if ($this->min !== null) {
            $schema['minimum'] = $this->min;
        }

        if ($this->max !== null) {
            $schema['maximum'] = $this->max;
        }

        return JsonSchemaExport::of($schema);
    }
}
