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
 * A fixed-length list of heterogeneous elements: position 0, 1, … each
 * validated by its own schema. The wire form is a JSON array of exactly
 * that length — `prefixItems` plus `items: false` in draft 2020-12.
 *
 * @extends SchemaType<list<mixed>>
 *
 * @api
 */
final class TupleSchema extends SchemaType
{
    /**
     * @param list<SchemaInterface<mixed>> $elements position i is
     *     validated by `$elements[i]`
     */
    public function __construct(
        public readonly array $elements,
    ) {
        if ($elements === []) {
            throw new \InvalidArgumentException('Tuple requires at least one element schema');
        }
    }

    #[\Override]
    public function parse(mixed $input): ParseResult
    {
        if (!\is_array($input) || !\array_is_list($input)) {
            return ParseResult::failed([
                new ParseError([], \sprintf('Expected a list, got %s', \get_debug_type($input))),
            ]);
        }

        if (\count($input) !== \count($this->elements)) {
            return ParseResult::failed([
                new ParseError(
                    [],
                    \sprintf(
                        'Expected a list of exactly %d elements, got %d',
                        \count($this->elements),
                        \count($input),
                    ),
                ),
            ]);
        }

        /** @var list<mixed> $values */
        $values = [];
        $errors = [];

        foreach ($this->elements as $index => $element) {
            $result = $element->parse($input[$index]);

            if ($result->ok) {
                $values[] = $result->value;
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
            /** @var list<mixed> $value */
            $value = $result->value;

            return $value;
        }

        throw new SchemaException($result->errors);
    }

    #[\Override]
    public function arbitrary(): ArbitraryInterface
    {
        return Gen::tuple(...\array_map(
            static fn(SchemaInterface $element): ArbitraryInterface => $element->arbitrary(),
            $this->elements,
        ));
    }

    #[\Override]
    public function toJsonSchema(bool $lenient = false): JsonSchemaExport
    {
        $prefixItems = [];
        $nonRepresentable = [];

        foreach ($this->elements as $index => $element) {
            $child = $element->toJsonSchema($lenient);

            $prefixItems[] = $child->schema;

            foreach ($child->nonRepresentable as $entry) {
                $nonRepresentable[] = $entry->withSegment($index);
            }
        }

        return JsonSchemaExport::of([
            'type' => 'array',
            'prefixItems' => $prefixItems,
            'items' => false,
        ], $nonRepresentable);
    }
}
