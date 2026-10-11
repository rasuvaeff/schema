<?php

declare(strict_types=1);

namespace Rasuvaeff\Schema\Tests;

use Opis\JsonSchema\Validator;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Classify;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Rasuvaeff\Schema\Combinator\RefineSchema;
use Rasuvaeff\Schema\Combinator\TransformSchema;
use Rasuvaeff\Schema\Internal\Wire;
use Rasuvaeff\Schema\SchemaInterface;
use Rasuvaeff\Schema\Tests\Support\SchemaTrees;
use Rasuvaeff\Schema\Type\ArraySchema;
use Rasuvaeff\Schema\Type\DateTimeSchema;
use Rasuvaeff\Schema\Type\LazySchema;
use Rasuvaeff\Schema\Type\MapSchema;
use Rasuvaeff\Schema\Type\NullableSchema;
use Rasuvaeff\Schema\Type\ObjectSchema;
use Rasuvaeff\Schema\Type\UnionSchema;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

/**
 * Law 2, the wire side: for any schema, the wire projection of a
 * generated value validates against the exported JSON Schema fragment —
 * through opis, the same validator family the outside world uses.
 *
 * opis reads PHP arrays as JSON arrays and stdClass as objects, so both
 * sides go through schema-aware conversion: a map is a stdClass even when
 * empty, a list stays a list, and the empty fragment is `{}`, not `[]`.
 */
#[Test]
#[Covers(Wire::class)]
final class SchemaJsonSchemaParityPropertyTest
{
    #[Property(runs: 300)]
    public function wireOfGeneratedValidatesAgainstExport(int $depth): void
    {
        $schema = Gen::draw(SchemaTrees::tree($depth, withTransforms: true));
        $value = Gen::draw($schema->arbitrary());

        $export = $schema->toJsonSchema();

        Classify::when($export->nonRepresentable !== [], 'dropped a refinement');
        Classify::when(\is_array($value), 'structured');

        $validator = new Validator();

        $data = self::toJson($schema, $schema->toWire($value));
        $fragment = self::toSchema($export->schema);

        Assert::true($validator->validate($data, $fragment)->isValid());
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function wireOfGeneratedValidatesAgainstExportGenerators(): array
    {
        return [
            'depth' => Gen::intBetween(1, 3),
        ];
    }

    /**
     * @return iterable<string, list<int>>
     */
    public static function wireOfGeneratedValidatesAgainstExportExamples(): iterable
    {
        yield 'shallow trees' => [1];

        yield 'deep trees' => [3];
    }

    /**
     * Schema-aware data conversion: mirrors {@see Wire}'s walk so the PHP
     * value lands in the JSON shape the schema dictates — maps and objects
     * become stdClass (an empty map is `{}`, never `[]`), lists stay lists.
     */
    private static function toJson(SchemaInterface $schema, mixed $value): mixed
    {
        if ($schema instanceof DateTimeSchema && $value instanceof \DateTimeImmutable) {
            return $value->format(\DATE_RFC3339_EXTENDED);
        }

        if ($schema instanceof ArraySchema && \is_array($value)) {
            $items = [];

            foreach (\array_keys($value) as $index) {
                $items[] = self::toJson($schema->element, $value[$index]);
            }

            return $items;
        }

        if ($schema instanceof MapSchema && \is_array($value)) {
            $object = new \stdClass();

            foreach ($value as $key => $item) {
                $object->{$key} = self::toJson($schema->value, $item);
            }

            return $object;
        }

        if ($schema instanceof ObjectSchema && \is_array($value)) {
            $object = new \stdClass();

            foreach ($schema->properties as $name => $property) {
                if (\array_key_exists($name, $value)) {
                    $object->{$name} = self::toJson($property->schema, $value[$name]);
                }
            }

            return $object;
        }

        if ($schema instanceof NullableSchema) {
            return $value === null ? null : self::toJson($schema->inner, $value);
        }

        if ($schema instanceof UnionSchema) {
            foreach ($schema->members as $member) {
                if ($member->parse($value)->ok) {
                    return self::toJson($member, $value);
                }
            }

            return $value;
        }

        if ($schema instanceof RefineSchema) {
            return self::toJson($schema->inner, $value);
        }

        if ($schema instanceof LazySchema) {
            return self::toJson($schema->resolve(), $value);
        }

        if ($schema instanceof TransformSchema && \is_array($value)) {
            // The target space has no schema to walk; structural conversion.
            return self::toSchema($value);
        }

        return $value;
    }

    /**
     * Fragment conversion: string-keyed maps (and the empty fragment)
     * become stdClass, lists stay arrays, scalars pass through.
     *
     * @param array<array-key, mixed> $value
     */
    private static function toSchema(array $value): \stdClass|array
    {
        if ($value === []) {
            return new \stdClass();
        }

        if (\array_is_list($value)) {
            return \array_map(
                static fn(mixed $item): mixed => \is_array($item) ? self::toSchema($item) : $item,
                $value,
            );
        }

        $object = new \stdClass();

        foreach ($value as $key => $item) {
            $object->{$key} = \is_array($item) ? self::toSchema($item) : $item;
        }

        return $object;
    }
}
