<?php

declare(strict_types=1);

namespace Rasuvaeff\Schema\Type;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\Schema\JsonSchemaExport;
use Rasuvaeff\Schema\ParseError;
use Rasuvaeff\Schema\ParseResult;
use Rasuvaeff\Schema\SchemaException;
use Rasuvaeff\Schema\SchemaInterface;
use Rasuvaeff\Schema\SchemaType;

/**
 * A string-keyed map: every value is parsed by the value schema. Keys are
 * generated as short non-empty strings; value errors are prefixed with
 * the key.
 *
 * @template T
 *
 * @extends SchemaType<array<string, T>>
 *
 * @api
 */
final class MapSchema extends SchemaType
{
    public function __construct(
        public readonly SchemaInterface $value,
        public readonly ?int $minCount = null,
        public readonly ?int $maxCount = null,
    ) {}

    public function minCount(int $count): self
    {
        if ($count < 0) {
            throw new \InvalidArgumentException('Minimum count must not be negative');
        }

        if ($this->maxCount !== null && $count > $this->maxCount) {
            throw new \InvalidArgumentException('Minimum count must not exceed the maximum');
        }

        return new self(value: $this->value, minCount: $count, maxCount: $this->maxCount);
    }

    public function maxCount(int $count): self
    {
        if ($count < 0) {
            throw new \InvalidArgumentException('Maximum count must not be negative');
        }

        if ($this->minCount !== null && $count < $this->minCount) {
            throw new \InvalidArgumentException('Maximum count must not be below the minimum');
        }

        return new self(value: $this->value, minCount: $this->minCount, maxCount: $count);
    }

    #[\Override]
    public function parse(mixed $input): ParseResult
    {
        if (!\is_array($input)) {
            return ParseResult::failed([
                new ParseError([], \sprintf('Expected map, got %s', \get_debug_type($input))),
            ]);
        }

        foreach (\array_keys($input) as $key) {
            if (!\is_string($key)) {
                return ParseResult::failed([
                    new ParseError([], 'Map keys must be strings'),
                ]);
            }
        }

        /** @var list<string> $keys all keys verified strings above */
        $keys = \array_keys($input);

        $count = \count($input);

        if ($this->minCount !== null && $count < $this->minCount) {
            return ParseResult::failed([
                new ParseError([], \sprintf('Map must contain at least %d entries, got %d', $this->minCount, $count)),
            ]);
        }

        if ($this->maxCount !== null && $count > $this->maxCount) {
            return ParseResult::failed([
                new ParseError([], \sprintf('Map must contain at most %d entries, got %d', $this->maxCount, $count)),
            ]);
        }

        $values = [];
        $errors = [];

        /** @var array<string, T> $values */
        foreach ($keys as $key) {
            $result = $this->value->parse($input[$key]);

            if ($result->ok) {
                /** @var T $parsed */
                $parsed = $result->value;
                $values[$key] = $parsed;
            } else {
                foreach ($result->errors as $error) {
                    $errors[] = $error->withSegment($key);
                }
            }
        }

        if ($errors !== []) {
            return ParseResult::failed($errors);
        }

        return ParseResult::ok($values);
    }

    #[\Override]
    public function parseOrThrow(mixed $input): array
    {
        $result = $this->parse($input);

        if ($result->ok) {
            /** @var array<string, T> $value */
            $value = $result->value;

            return $value;
        }

        throw new SchemaException($result->errors);
    }

    #[\Override]
    public function arbitrary(): ArbitraryInterface
    {
        if ($this->maxCount === 0) {
            return Gen::constant([]);
        }

        return Gen::dictOf(
            key: Gen::stringOf(1, 12),
            value: $this->value->arbitrary(),
            minSize: $this->minCount ?? 0,
            maxSize: $this->maxCount ?? 20,
        );
    }

    #[\Override]
    public function toJsonSchema(bool $lenient = false): JsonSchemaExport
    {
        $child = $this->value->toJsonSchema($lenient);

        $schema = [
            'type' => 'object',
            'additionalProperties' => $child->schema,
        ];

        if ($this->minCount !== null) {
            $schema['minProperties'] = $this->minCount;
        }

        if ($this->maxCount !== null) {
            $schema['maxProperties'] = $this->maxCount;
        }

        return JsonSchemaExport::of($schema, $child->nonRepresentable);
    }
}
