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
 * A sequential list of values, each parsed by the element schema. Element
 * errors are prefixed with the item index.
 *
 * @template T
 *
 * @extends SchemaType<list<T>>
 *
 * @api
 */
final class ArraySchema extends SchemaType
{
    public function __construct(
        public readonly SchemaInterface $element,
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

        return new self(element: $this->element, minCount: $count, maxCount: $this->maxCount);
    }

    public function maxCount(int $count): self
    {
        if ($count < 0) {
            throw new \InvalidArgumentException('Maximum count must not be negative');
        }

        if ($this->minCount !== null && $count < $this->minCount) {
            throw new \InvalidArgumentException('Maximum count must not be below the minimum');
        }

        return new self(element: $this->element, minCount: $this->minCount, maxCount: $count);
    }

    #[\Override]
    public function parse(mixed $input): ParseResult
    {
        if (!\is_array($input)) {
            return ParseResult::failed([
                new ParseError([], \sprintf('Expected list, got %s', \get_debug_type($input))),
            ]);
        }

        if (!\array_is_list($input)) {
            return ParseResult::failed([
                new ParseError([], 'Expected a sequential list, got a map'),
            ]);
        }

        $count = \count($input);

        if ($this->minCount !== null && $count < $this->minCount) {
            return ParseResult::failed([
                new ParseError([], \sprintf('List must contain at least %d items, got %d', $this->minCount, $count)),
            ]);
        }

        if ($this->maxCount !== null && $count > $this->maxCount) {
            return ParseResult::failed([
                new ParseError([], \sprintf('List must contain at most %d items, got %d', $this->maxCount, $count)),
            ]);
        }

        $values = [];
        $errors = [];

        /** @var list<T> $values */
        foreach (\array_keys($input) as $index) {
            $result = $this->element->parse($input[$index]);

            if ($result->ok) {
                /** @var T $parsed */
                $parsed = $result->value;
                $values[] = $parsed;
            } else {
                foreach ($result->errors as $error) {
                    $errors[] = $error->withSegment($index);
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
            /** @var list<T> $value */
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

        return Gen::arrayOf($this->element->arbitrary(), $this->minCount ?? 0, $this->maxCount ?? 20);
    }

    #[\Override]
    public function toJsonSchema(bool $lenient = false): JsonSchemaExport
    {
        $child = $this->element->toJsonSchema($lenient);

        $schema = [
            'type' => 'array',
            'items' => $child->schema,
        ];

        if ($this->minCount !== null) {
            $schema['minItems'] = $this->minCount;
        }

        if ($this->maxCount !== null) {
            $schema['maxItems'] = $this->maxCount;
        }

        return JsonSchemaExport::of($schema, $child->nonRepresentable);
    }
}
