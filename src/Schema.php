<?php

declare(strict_types=1);

namespace Rasuvaeff\Schema;

use Closure;
use Rasuvaeff\Schema\Type\ArraySchema;
use Rasuvaeff\Schema\Type\BoolSchema;
use Rasuvaeff\Schema\Type\DateTimeSchema;
use Rasuvaeff\Schema\Type\EnumSchema;
use Rasuvaeff\Schema\Type\FloatSchema;
use Rasuvaeff\Schema\Type\IntSchema;
use Rasuvaeff\Schema\Type\JsonValueSchema;
use Rasuvaeff\Schema\Type\LazySchema;
use Rasuvaeff\Schema\Type\LiteralSchema;
use Rasuvaeff\Schema\Type\MapSchema;
use Rasuvaeff\Schema\Type\NullableSchema;
use Rasuvaeff\Schema\Type\ObjectProperty;
use Rasuvaeff\Schema\Type\ObjectSchema;
use Rasuvaeff\Schema\Type\StringSchema;
use Rasuvaeff\Schema\Type\UnionSchema;

/**
 * The single entry point: one semantic static factory per type, chained
 * constraints on the instances.
 *
 * ```php
 * $user = Schema::object([
 *     'email' => Schema::string()->min(3)->max(254),
 *     'age' => Schema::int()->min(18),
 *     'role' => Schema::enum(Role::class),
 *     'tags' => Schema::arrayOf(Schema::string()->min(1))->maxCount(20),
 *     'bio' => Schema::string()->max(1000)->default(''),
 * ]);
 *
 * $user->parse($payload);   // ParseResult: errors carry $.tags[2]-style paths
 * $user->arbitrary();       // generator over the exact same set
 * ```
 *
 * @api
 */
final class Schema
{
    private function __construct() {}

    public static function string(): StringSchema
    {
        return new StringSchema();
    }

    public static function int(): IntSchema
    {
        return new IntSchema();
    }

    /**
     * Finite float with optional inclusive bounds; NaN/INF rejected,
     * `allowInt()` widens parse to whole numbers (normalized to float).
     * JSON Schema export: `type: number`.
     */
    public static function float(): FloatSchema
    {
        return new FloatSchema();
    }

    /**
     * RFC 3339 string on the wire, `DateTimeImmutable` in the PHP domain,
     * with optional inclusive `min()`/`max()` bounds.
     */
    public static function dateTime(): DateTimeSchema
    {
        return new DateTimeSchema();
    }

    public static function bool(): BoolSchema
    {
        return new BoolSchema();
    }

    /**
     * @template TValue of string|int|float|bool|null
     *
     * @param TValue $value
     *
     * @return LiteralSchema<TValue>
     */
    public static function literal(string|int|float|bool|null $value): LiteralSchema
    {
        return new LiteralSchema($value);
    }

    /**
     * @template TEnum of \BackedEnum
     *
     * @param class-string<TEnum> $enum
     *
     * @return EnumSchema<TEnum>
     */
    public static function enum(string $enum): EnumSchema
    {
        return new EnumSchema($enum);
    }

    /**
     * Any JSON value: null, a finite scalar, a list or a string-keyed map
     * of such values — the domain of `json_decode`.
     */
    public static function json(): JsonValueSchema
    {
        return new JsonValueSchema();
    }

    /**
     * @template T
     *
     * @param SchemaInterface<T> $element
     *
     * @return ArraySchema<T>
     */
    public static function arrayOf(SchemaInterface $element): ArraySchema
    {
        return new ArraySchema($element);
    }

    /**
     * @template T
     *
     * @param SchemaInterface<T> $value
     *
     * @return MapSchema<T>
     */
    public static function mapOf(SchemaInterface $value): MapSchema
    {
        return new MapSchema($value);
    }

    /**
     * @param array<string, SchemaInterface> $properties bare schemas are
     *     required properties; `->optional()`/`->default()` wrap into
     *     {@see ObjectProperty}
     */
    public static function object(array $properties, bool $additionalProperties = false): ObjectSchema
    {
        return new ObjectSchema($properties, $additionalProperties);
    }

    /**
     * @param list<SchemaInterface> $members tried in declaration
     *     order, first success wins; overlapping members are an authoring
     *     error the parser will silently forgive — the first member wins
     */
    public static function union(array $members): UnionSchema
    {
        return new UnionSchema($members);
    }

    /**
     * @template T
     *
     * @param SchemaInterface<T> $inner
     *
     * @return NullableSchema<T>
     */
    public static function nullable(SchemaInterface $inner): NullableSchema
    {
        return new NullableSchema($inner);
    }

    /**
     * A deferred schema for recursive definitions; the factory runs once,
     * on first use.
     *
     * @param Closure(): SchemaInterface $factory
     */
    public static function lazy(Closure $factory): LazySchema
    {
        return new LazySchema($factory);
    }
}
