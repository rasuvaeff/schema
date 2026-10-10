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
 * Integer with optional inclusive bounds.
 *
 * @extends SchemaType<int>
 *
 * @api
 */
final class IntSchema extends SchemaType
{
    public function __construct(
        public readonly ?int $min = null,
        public readonly ?int $max = null,
    ) {}

    public function min(int $value): self
    {
        if ($this->max !== null && $value > $this->max) {
            throw new \InvalidArgumentException('Minimum must not exceed the maximum');
        }

        return new self(min: $value, max: $this->max);
    }

    public function max(int $value): self
    {
        if ($this->min !== null && $value < $this->min) {
            throw new \InvalidArgumentException('Maximum must not be below the minimum');
        }

        return new self(min: $this->min, max: $value);
    }

    #[\Override]
    public function parse(mixed $input): ParseResult
    {
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

        return JsonSchemaExport::of($schema);
    }
}
