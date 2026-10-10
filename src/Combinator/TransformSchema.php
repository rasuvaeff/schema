<?php

declare(strict_types=1);

namespace Rasuvaeff\Schema\Combinator;

use Closure;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\Schema\JsonSchemaExport;
use Rasuvaeff\Schema\NonRepresentable;
use Rasuvaeff\Schema\NonRepresentableException;
use Rasuvaeff\Schema\ParseError;
use Rasuvaeff\Schema\ParseResult;
use Rasuvaeff\Schema\SchemaException;
use Rasuvaeff\Schema\SchemaInterface;
use Rasuvaeff\Schema\SchemaType;

/**
 * Validate against the source schema, then map the parsed value through
 * the callback.
 *
 * Domains, pinned: `parse` accepts the source space; `arbitrary`
 * produces the **target** space (`Gen::map` over the source generator);
 * shrinking happens in the source space and the callback is re-applied,
 * so a shrunk counterexample is still a valid target value.
 *
 * @template TSource
 * @template TTarget
 *
 * @extends SchemaType<TTarget>
 *
 * @api
 */
final class TransformSchema extends SchemaType
{
    /**
     * @param SchemaInterface<TSource> $source
     * @param Closure(TSource): TTarget $transform
     * @param Closure(TTarget): TSource|null $from optional inverse — used
     *     by round-trip laws only, never by parse or generation
     * @param Closure(TTarget): mixed|null $wire the PHP→JSON converter of
     *     the target type; without it the wire projection is the identity,
     *     legal only for JSON-native targets
     * @param array<string, mixed>|null $jsonSchema the declared wire shape
     *     of the target type — without it `toJsonSchema()` throws in
     *     strict mode; with it the fragment is exported and checked
     *     against the generator's actual output by the package's law 2
     */
    public function __construct(
        public readonly SchemaInterface $source,
        public readonly Closure $transform,
        public readonly ?Closure $from = null,
        public readonly ?Closure $wire = null,
        public readonly ?array $jsonSchema = null,
    ) {}

    #[\Override]
    public function parse(mixed $input): ParseResult
    {
        $result = $this->source->parse($input);

        if (!$result->ok) {
            return ParseResult::failed($result->errors);
        }

        /** @var TSource $source */
        $source = $result->value;
        $transform = $this->transform;

        try {
            return ParseResult::ok($transform($source));
        } catch (\Throwable $exception) {
            return ParseResult::failed([
                // The callback's own message may embed values; the class
                // name is the most an error ever carries.
                new ParseError([], \sprintf('Transform callback failed (%s)', $exception::class)),
            ]);
        }
    }

    #[\Override]
    public function parseOrThrow(mixed $input): mixed
    {
        $result = $this->parse($input);

        if ($result->ok) {
            /** @var TTarget $value */
            $value = $result->value;

            return $value;
        }

        throw new SchemaException($result->errors);
    }

    #[\Override]
    public function arbitrary(): ArbitraryInterface
    {
        return Gen::map($this->source->arbitrary(), $this->transform);
    }

    #[\Override]
    public function toJsonSchema(bool $lenient = false): JsonSchemaExport
    {
        if ($this->jsonSchema !== null) {
            return JsonSchemaExport::of($this->jsonSchema);
        }

        if (!$lenient) {
            throw new NonRepresentableException(
                'Transform without a declared jsonSchema codec — exporting the source schema would lie about the target type; declare the codec via transform(..., jsonSchema: [...]) or export lenient',
            );
        }

        return JsonSchemaExport::of([], [new NonRepresentable([], 'transform')]);
    }
}
