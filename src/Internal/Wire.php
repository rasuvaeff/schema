<?php

declare(strict_types=1);

namespace Rasuvaeff\Schema\Internal;

use Rasuvaeff\Schema\Combinator\RefineSchema;
use Rasuvaeff\Schema\Combinator\TransformSchema;
use Rasuvaeff\Schema\SchemaInterface;
use Rasuvaeff\Schema\Type\ArraySchema;
use Rasuvaeff\Schema\Type\EnumSchema;
use Rasuvaeff\Schema\Type\LazySchema;
use Rasuvaeff\Schema\Type\MapSchema;
use Rasuvaeff\Schema\Type\NullableSchema;
use Rasuvaeff\Schema\Type\ObjectSchema;
use Rasuvaeff\Schema\Type\UnionSchema;

/**
 * The wire seam: converts a generated PHP value into its JSON form by
 * walking the schema that produced it — an enum instance becomes its
 * backed value, containers convert recursively, a transform's declared
 * `wire` callback is applied, everything else passes through unchanged.
 *
 * Internal by design: the public surface stays in the PHP domain;
 * publishing a `serialize()` port is an RFC for after the first consumer
 * pilot.
 */
final class Wire
{
    /**
     * @param mixed $value a value produced by (or parsed through) $schema
     *
     * @return mixed the JSON-shaped value
     */
    public static function value(SchemaInterface $schema, mixed $value): mixed
    {
        if ($schema instanceof EnumSchema && $value instanceof \BackedEnum) {
            return $value->value;
        }

        if ($schema instanceof TransformSchema) {
            if ($schema->wire !== null) {
                $wire = $schema->wire;

                return $wire($value);
            }

            return $value;
        }

        if ($schema instanceof ArraySchema && \is_array($value)) {
            $converted = [];

            foreach (\array_keys($value) as $index) {
                $converted[] = self::value($schema->element, $value[$index]);
            }

            return $converted;
        }

        if ($schema instanceof MapSchema && \is_array($value)) {
            $converted = [];

            foreach (\array_keys($value) as $key) {
                $converted[$key] = self::value($schema->value, $value[$key]);
            }

            return $converted;
        }

        if ($schema instanceof ObjectSchema && \is_array($value)) {
            $converted = [];

            foreach ($schema->properties as $name => $property) {
                if (\array_key_exists($name, $value)) {
                    $converted[$name] = self::value($property->schema, $value[$name]);
                }
            }

            return $converted;
        }

        if ($schema instanceof NullableSchema) {
            if ($value === null) {
                return null;
            }

            return self::value($schema->inner, $value);
        }

        if ($schema instanceof UnionSchema) {
            // The same order parse uses: the first member that accepts the
            // value defines its wire form.
            foreach ($schema->members as $member) {
                if ($member->parse($value)->ok) {
                    return self::value($member, $value);
                }
            }

            return $value;
        }

        if ($schema instanceof RefineSchema) {
            return self::value($schema->inner, $value);
        }

        if ($schema instanceof LazySchema) {
            return self::value($schema->resolve(), $value);
        }

        return $value;
    }
}
