<?php

declare(strict_types=1);

namespace Rasuvaeff\Schema\Internal;

use Rasuvaeff\Schema\Combinator\IntoSchema;
use Rasuvaeff\Schema\Combinator\RefineSchema;
use Rasuvaeff\Schema\Combinator\TransformSchema;
use Rasuvaeff\Schema\SchemaInterface;
use Rasuvaeff\Schema\Type\ArraySchema;
use Rasuvaeff\Schema\Type\DateTimeSchema;
use Rasuvaeff\Schema\Type\DiscriminatedSchema;
use Rasuvaeff\Schema\Type\EnumSchema;
use Rasuvaeff\Schema\Type\LazySchema;
use Rasuvaeff\Schema\Type\MapSchema;
use Rasuvaeff\Schema\Type\NullableSchema;
use Rasuvaeff\Schema\Type\ObjectSchema;
use Rasuvaeff\Schema\Type\TupleSchema;
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
 *
 * @internal
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
            if ($schema->wire instanceof \Closure) {
                $wire = $schema->wire;

                return $wire($value);
            }

            return $value;
        }

        if ($schema instanceof DateTimeSchema && $value instanceof \DateTimeImmutable) {
            return $value->format(\DATE_RFC3339_EXTENDED);
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

            foreach ($value as $key => $item) {
                $property = $schema->properties[(string) $key] ?? null;

                if ($property !== null) {
                    $converted[$key] = self::value($property->schema, $item);
                } elseif (!$schema->strip) {
                    // Pass-through mode keeps unknown keys in their raw
                    // shape; strip drops them (a parsed value never
                    // carries them — defensive for hand-built values).
                    $converted[$key] = $item;
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

        if ($schema instanceof TupleSchema && \is_array($value)) {
            $converted = [];

            foreach ($schema->elements as $index => $element) {
                if (\array_key_exists($index, $value)) {
                    $converted[] = self::value($element, $value[$index]);
                }
            }

            return $converted;
        }

        if ($schema instanceof DiscriminatedSchema) {
            // Disjoint by construction; the first accepting branch is the
            // only one that can accept.
            foreach ($schema->branches as $branch) {
                if ($branch->parse($value)->ok) {
                    return self::value($branch, $value);
                }
            }

            return $value;
        }

        if ($schema instanceof IntoSchema) {
            return self::value($schema->object, \is_object($value) ? \get_object_vars($value) : $value);
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
