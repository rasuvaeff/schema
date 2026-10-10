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
    private const array LEAVES = ['string', 'int', 'bool', 'literal', 'enum', 'json', 'refine'];

    private const array TRANSFORM_LEAVES = ['transform'];

    private const array NODES = ['array', 'map', 'object', 'nullable', 'union'];

    /**
     * @return ArbitraryInterface<SchemaInterface<mixed>>
     */
    public static function tree(int $depth, bool $withTransforms = false): ArbitraryInterface
    {
        return Gen::composite(function () use ($depth, $withTransforms): SchemaInterface {
            $leaves = $withTransforms
                ? [...self::LEAVES, ...self::TRANSFORM_LEAVES]
                : self::LEAVES;
            $kinds = $depth <= 0 ? $leaves : [...$leaves, ...self::NODES];
            $kind = Gen::draw(Gen::elements($kinds));

            return match ($kind) {
                'string' => self::string(),
                'int' => self::int(),
                'bool' => Schema::bool(),
                'literal' => Schema::literal(Gen::draw(Gen::elements(['a', 'b', 1, true]))),
                'enum' => Schema::enum(Gen::draw(Gen::elements([Role::class, Suit::class]))),
                'json' => Schema::json(),
                'transform' => Schema::int()->min(-4)->max(4)->transform(
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

        return Schema::int()->min($min)->max($max);
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
