<?php

declare(strict_types=1);

namespace Rasuvaeff\Schema;

use Closure;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\Schema\Combinator\RefineSchema;
use Rasuvaeff\Schema\Combinator\TransformSchema;
use Rasuvaeff\Schema\Type\ObjectProperty;

/**
 * The shared combinator surface: every concrete schema inherits
 * `transform`, `refine`, `optional` and `default` exactly once, with the
 * class's own `TValue` resolving through `@extends`.
 *
 * Order rule: combinators (`transform`, `refine`) come first, property
 * modifiers (`optional`, `default`) last — a property wrapper has no
 * combinators, so `->min(1)->transform(...)->optional()` is the shape,
 * never `->optional()->transform(...)`.
 *
 * @template TValue
 *
 * @implements SchemaInterface<TValue>
 */
abstract class SchemaType implements SchemaInterface
{
    /**
     * @param mixed $input
     *
     * @return ParseResult<TValue>
     */
    #[\Override]
    abstract public function parse(mixed $input): ParseResult;

    /**
     * @param mixed $input
     *
     * @return TValue
     *
     * @throws SchemaException when `$input` does not match the schema.
     */
    #[\Override]
    abstract public function parseOrThrow(mixed $input): mixed;

    /**
     * @return ArbitraryInterface<TValue>
     */
    #[\Override]
    abstract public function arbitrary(): ArbitraryInterface;
    /**
     * Validate against the source schema, then map the parsed value
     * through `$transform`. Everything the generator produces lives in the
     * target space; shrinking stays in the source space and re-applies the
     * callback.
     *
     * @template TTarget
     *
     * @param Closure(TValue): TTarget $transform
     * @param Closure(TTarget): TValue|null $from optional inverse, needed
     *     only for round-trip laws, never for parse or generation
     *
     * @return TransformSchema<TValue, TTarget>
     */
    public function transform(Closure $transform, ?Closure $from = null): TransformSchema
    {
        return new TransformSchema($this, $transform, $from);
    }

    /**
     * Post-condition over the parsed value. Prefer a constraint over a
     * refinement: `refine` filters the generator, and an over-narrow
     * predicate burns generation budget on discards.
     *
     * @param Closure(TValue): bool $predicate
     *
     * @return RefineSchema<TValue>
     */
    public function refine(Closure $predicate, string $message): RefineSchema
    {
        return new RefineSchema($this, $predicate, $message);
    }

    /**
     * Object-property modifier: the key may be absent. Outside
     * {@see \Rasuvaeff\Schema\Type\ObjectSchema} it is meaningless and
     * rejected by the object constructor.
     *
     * @return ObjectProperty<TValue>
     */
    public function optional(): ObjectProperty
    {
        return new ObjectProperty($this, optional: true);
    }

    /**
     * Object-property modifier: fill the key with `$value` when absent.
     * The default is validated (and normalized — an enum default may be
     * given as its backed value and is stored as the instance) at
     * construction, so an invalid default is an authoring error, not a
     * runtime surprise. Implies `optional()`.
     *
     * @param TValue $value
     *
     * @return ObjectProperty<TValue>
     */
    public function default(mixed $value): ObjectProperty
    {
        return (new ObjectProperty($this))->default($value);
    }
}
