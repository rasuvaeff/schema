<?php

declare(strict_types=1);

namespace Rasuvaeff\Schema\Tests;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Classify;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Rasuvaeff\Schema\Schema;
use Rasuvaeff\Schema\Tests\Support\SchemaTrees;
use Rasuvaeff\Schema\Type\ArraySchema;
use Rasuvaeff\Schema\Type\BoolSchema;
use Rasuvaeff\Schema\Type\EnumSchema;
use Rasuvaeff\Schema\Type\IntSchema;
use Rasuvaeff\Schema\Type\JsonValueSchema;
use Rasuvaeff\Schema\Type\LiteralSchema;
use Rasuvaeff\Schema\Type\MapSchema;
use Rasuvaeff\Schema\Type\NullableSchema;
use Rasuvaeff\Schema\Type\ObjectSchema;
use Rasuvaeff\Schema\Type\StringSchema;
use Rasuvaeff\Schema\Type\UnionSchema;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

/**
 * The parity law — the package's own product, tested by itself: for any
 * schema, everything its generator produces must pass its parser.
 */
#[Test]
#[Covers(Schema::class)]
#[Covers(StringSchema::class)]
#[Covers(IntSchema::class)]
#[Covers(BoolSchema::class)]
#[Covers(LiteralSchema::class)]
#[Covers(EnumSchema::class)]
#[Covers(JsonValueSchema::class)]
#[Covers(ArraySchema::class)]
#[Covers(MapSchema::class)]
#[Covers(ObjectSchema::class)]
#[Covers(NullableSchema::class)]
#[Covers(UnionSchema::class)]
final class SchemaParityPropertyTest
{
    #[Property(runs: 300)]
    public function everythingTheGeneratorProducesParses(int $depth): void
    {
        $schema = Gen::draw(SchemaTrees::tree($depth));
        $value = Gen::draw($schema->arbitrary());

        Classify::when(\is_array($value), 'structured');
        Classify::when($value === null, 'null');
        Classify::cover(\is_array($value), 'structured', 5);

        Assert::true($schema->parse($value)->ok);
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function everythingTheGeneratorProducesParsesGenerators(): array
    {
        return [
            'depth' => Gen::intBetween(1, 3),
        ];
    }

    /**
     * @return iterable<string, list<int>>
     */
    public static function everythingTheGeneratorProducesParsesExamples(): iterable
    {
        yield 'shallow trees' => [1];

        yield 'deep trees' => [3];
    }
}
