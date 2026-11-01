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
 * One of a fixed set of constants, compared strictly (`1` does not match
 * `'1'`). The set is part of the schema, not of the input, so error
 * messages may name it.
 *
 * @template TValue of string|int|float|bool|null
 *
 * @extends SchemaType<TValue>
 *
 * @api
 */
final class LiteralsSchema extends SchemaType
{
    /** @var non-empty-list<TValue> */
    public readonly array $values;

    /**
     * @param list<TValue> $values non-empty, distinct (strictly)
     */
    public function __construct(array $values)
    {
        if ($values === []) {
            throw new \InvalidArgumentException('Literals require at least one value');
        }

        foreach ($values as $index => $value) {
            if (\in_array($value, \array_slice($values, 0, $index), strict: true)) {
                throw new \InvalidArgumentException('Literal values must be distinct');
            }

            if (\is_float($value) && !\is_finite($value)) {
                throw new \InvalidArgumentException('Literal values must be finite');
            }
        }

        $this->values = $values;
    }

    #[\Override]
    public function parse(mixed $input): ParseResult
    {
        foreach ($this->values as $value) {
            if ($input === $value) {
                return ParseResult::ok($value);
            }
        }

        return ParseResult::failed([
            new ParseError([], \sprintf('Expected one of: %s', $this->allowedList())),
        ]);
    }

    #[\Override]
    public function parseOrThrow(mixed $input): string|int|float|bool|null
    {
        $result = $this->parse($input);

        if ($result->ok) {
            /** @var TValue $value */
            $value = $result->value;

            return $value;
        }

        throw new SchemaException($result->errors);
    }

    #[\Override]
    public function arbitrary(): ArbitraryInterface
    {
        return Gen::elements($this->values);
    }

    #[\Override]
    public function toJsonSchema(bool $lenient = false): JsonSchemaExport
    {
        return JsonSchemaExport::of(['enum' => $this->values]);
    }

    private function allowedList(): string
    {
        return \implode(', ', \array_map(
            static fn(string|int|float|bool|null $value): string => \var_export($value, return: true),
            $this->values,
        ));
    }
}
