<?php

declare(strict_types=1);

namespace Rasuvaeff\Schema\Type;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\Schema\JsonSchemaExport;
use Rasuvaeff\Schema\ParseResult;
use Rasuvaeff\Schema\SchemaException;
use Rasuvaeff\Schema\SchemaInterface;
use Rasuvaeff\Schema\SchemaType;

/**
 * The inner schema or null. Nullable is about the value; an optional key
 * is about the property — different levels, both exist.
 *
 * @template T
 *
 * @extends SchemaType<T|null>
 *
 * @api
 */
final class NullableSchema extends SchemaType
{
    /**
     * @param SchemaInterface<T> $inner
     */
    public function __construct(
        public readonly SchemaInterface $inner,
    ) {}

    #[\Override]
    public function parse(mixed $input): ParseResult
    {
        if ($input === null) {
            return ParseResult::ok(null);
        }

        return $this->inner->parse($input);
    }

    #[\Override]
    public function parseOrThrow(mixed $input): mixed
    {
        $result = $this->parse($input);

        if ($result->ok) {
            /** @var T|null $value */
            $value = $result->value;

            return $value;
        }

        throw new SchemaException($result->errors);
    }

    #[\Override]
    public function arbitrary(): ArbitraryInterface
    {
        return Gen::nullable($this->inner->arbitrary());
    }

    #[\Override]
    public function toJsonSchema(bool $lenient = false): JsonSchemaExport
    {
        $child = $this->inner->toJsonSchema($lenient);

        return JsonSchemaExport::of(
            [
                'anyOf' => [
                    ['type' => 'null'],
                    $child->schema,
                ],
            ],
            $child->nonRepresentable,
        );
    }
}
