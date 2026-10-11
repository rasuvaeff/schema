<?php

declare(strict_types=1);

namespace Rasuvaeff\Schema\Tests\Support;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\Schema\Format;
use Rasuvaeff\Schema\Schema;
use Rasuvaeff\Schema\SchemaInterface;
use Rasuvaeff\Schema\Type\ObjectSchema;

/**
 * Random schema trees for the parity law: whatever shapes these
 * generators can build, everything those shapes generate must parse.
 */
final class SchemaTrees
{
    private const array LEAVES = ['string', 'int', 'float', 'bool', 'literal', 'enum', 'json', 'refine'];

    /**
     * Leaves whose generator lives in the target space (like a bare
     * transform's): law 1 does not apply to them, law 2 does.
     */
    private const array TRANSFORM_LEAVES = ['transform', 'dateTime'];

    private const array NODES = ['array', 'map', 'object', 'nullable', 'union', 'tuple', 'discriminated'];

    /**
     * @return ArbitraryInterface<SchemaInterface<mixed>>
     */
    public static function tree(int $depth, bool $withTransforms = false, bool $invertibleTransforms = false): ArbitraryInterface
    {
        return Gen::composite(function () use ($depth, $withTransforms, $invertibleTransforms): SchemaInterface {
            $leaves = $withTransforms
                ? [...self::LEAVES, ...self::TRANSFORM_LEAVES]
                : self::LEAVES;
            $kinds = $depth <= 0 ? $leaves : [...$leaves, ...self::NODES];
            $kind = Gen::draw(Gen::elements($kinds));

            return match ($kind) {
                'string' => self::string(),
                'int' => self::int(),
                'float' => self::float(),
                'dateTime' => self::dateTime(),
                'bool' => Gen::draw(Gen::bool()) ? Schema::bool() : Schema::bool()->coerce(),
                'literal' => Schema::literal(Gen::draw(Gen::elements(['a', 'b', 1, true]))),
                'enum' => Schema::enum(Gen::draw(Gen::elements([Role::class, Suit::class]))),
                'json' => Schema::json(),
                'transform' => $invertibleTransforms
                    ? Schema::int()->min(-4)->max(4)->transform(
                        static fn(int $value): string => (string) $value,
                        wire: static fn(string $value): int => (int) $value,
                    )
                    : Schema::int()->min(-4)->max(4)->transform(
                        static fn(int $value): string => (string) $value,
                        jsonSchema: ['type' => 'string'],
                    ),
                'refine' => Schema::int()->min(-4)->max(4)->refine(
                    static fn(int $value): bool => $value !== 999,
                    'never 999',
                ),
                'array' => Schema::arrayOf(Gen::draw(self::tree($depth - 1)))
                    ->maxCount(Gen::draw(Gen::intBetween(0, 4))),
                'map' => Schema::mapOf(Gen::draw(self::tree($depth - 1)))
                    ->maxCount(Gen::draw(Gen::intBetween(0, 3))),
                'object' => self::object($depth),
                'nullable' => Schema::nullable(Gen::draw(self::tree($depth - 1))),
                'union' => Schema::union([
                    Gen::draw(self::tree($depth - 1)),
                    Gen::draw(self::tree($depth - 1)),
                ]),
                'tuple' => Schema::tuple([
                    Gen::draw(self::tree($depth - 1)),
                    Gen::draw(self::tree($depth - 1)),
                ]),
                'discriminated' => self::discriminated($depth),
                default => throw new \LogicException('Unreachable kind branch'),
            };
        });
    }

    private static function string(): SchemaInterface
    {
        $format = Gen::draw(Gen::elements([null, Format::Email, Format::Uuid, Format::Url, Format::DateTime, Format::Ipv4]));

        if ($format !== null) {
            // Formats come without length bounds: a tight bound over a
            // fixed-shape format can empty the value set, and an empty set
            // fails generation loudly by design. The bounds-plus-format
            // combination is pinned by FormatTest with a satisfiable pair.
            return Schema::string()->format($format);
        }

        $min = Gen::draw(Gen::intBetween(0, 3));
        $max = Gen::draw(Gen::intBetween($min, 8));

        return Schema::string()->min($min)->max($max);
    }

    private static function int(): SchemaInterface
    {
        $min = Gen::draw(Gen::intBetween(-5, 0));
        $max = Gen::draw(Gen::intBetween(0, 5));

        $schema = Schema::int()->min($min)->max($max);

        return Gen::draw(Gen::bool()) ? $schema : $schema->coerce();
    }

    private static function float(): SchemaInterface
    {
        if (Gen::draw(Gen::bool())) {
            return Schema::float();
        }

        // min <= 0 <= max by construction, so the pair is always valid;
        // allowInt/coerce occasionally ride along for constructor coverage.
        $min = Gen::draw(Gen::floatBetween(-5.0, 0.0));
        $max = Gen::draw(Gen::floatBetween(0.0, 5.0));

        $schema = Schema::float()->min($min)->max($max);

        return match (Gen::draw(Gen::intBetween(0, 2))) {
            1 => $schema->allowInt(),
            2 => $schema->coerce(),
            default => $schema,
        };
    }

    private static function dateTime(): SchemaInterface
    {
        if (Gen::draw(Gen::bool())) {
            return Schema::dateTime();
        }

        // Capped a second short of the generator's default ceiling, so the
        // max drawn a millisecond above it always has room.
        $min = Gen::draw(Gen::datetime(max: new \DateTimeImmutable('2099-12-31T23:59:59+00:00')));

        // At least a millisecond apart: bounds inside one millisecond admit
        // no wire value and are rejected at construction.
        return Schema::dateTime()->min($min)->max(Gen::draw(Gen::datetime(min: $min->modify('+1000 usec'))));
    }

    private static function discriminated(int $depth): SchemaInterface
    {
        $branches = [];

        foreach (['a', 'b'] as $tag) {
            $branches[$tag] = Schema::object([
                'kind' => Schema::literal($tag),
                'payload' => Gen::draw(self::tree($depth - 1)),
            ]);
        }

        return Schema::discriminated('kind', $branches);
    }

    private static function object(int $depth): ObjectSchema
    {
        $properties = [];
        $count = Gen::draw(Gen::intBetween(1, 3));

        for ($i = 0; $i < $count; $i++) {
            $name = 'p' . $i;
            $schema = Gen::draw(self::tree($depth - 1));
            $style = Gen::draw(Gen::elements(['required', 'optional', 'default']));

            $properties[$name] = match ($style) {
                'optional' => $schema->optional(),
                'default' => $schema->default(Gen::draw($schema->arbitrary())),
                default => $schema,
            };
        }

        return Schema::object($properties, Gen::draw(Gen::bool()));
    }
}
