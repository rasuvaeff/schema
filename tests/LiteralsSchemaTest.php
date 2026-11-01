<?php

declare(strict_types=1);

namespace Rasuvaeff\Schema\Tests;

use Rasuvaeff\PropertyTesting\Random;
use Rasuvaeff\Schema\Schema;
use Rasuvaeff\Schema\SchemaException;
use Rasuvaeff\Schema\Type\LiteralsSchema;
use Testo\Assert;
use Testo\Assert\ExpectException;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(LiteralsSchema::class)]
final class LiteralsSchemaTest
{
    public function acceptsEveryListedValue(): void
    {
        $schema = Schema::literals(['pending', 'paid', 3, null]);

        foreach (['pending', 'paid', 3, null] as $value) {
            Assert::same($schema->parse($value)->value, $value);
        }
    }

    #[DataProvider('rejectedProvider')]
    public function comparesStrictly(mixed $input): void
    {
        $result = Schema::literals(['1', 2, true])->parse($input);

        Assert::false($result->ok);
        Assert::same($result->errors[0]->message, "Expected one of: '1', 2, true");
        Assert::same($result->errors[0]->toPath(), '$');
    }

    public static function rejectedProvider(): iterable
    {
        yield 'int for string' => [1];
        yield 'string for int' => ['2'];
        yield 'int for bool' => [1.0];
        yield 'absent value' => ['3'];
        yield 'null' => [null];
    }

    public function parseOrThrowReturnsTheValue(): void
    {
        Assert::same(Schema::literals(['a', 'b'])->parseOrThrow('b'), 'b');
    }

    #[ExpectException(SchemaException::class)]
    public function parseOrThrowThrowsOnAMiss(): void
    {
        Schema::literals(['a', 'b'])->parseOrThrow('c');
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsAnEmptySet(): void
    {
        Schema::literals([]);
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsDuplicates(): void
    {
        Schema::literals(['a', 'b', 'a']);
    }

    public function looseDuplicatesAreDistinct(): void
    {
        Assert::same(Schema::literals([1, '1', true])->values, [1, '1', true]);
    }

    public function acceptsFiniteFloats(): void
    {
        Assert::same(Schema::literals([1.5, -2.5])->parse(-2.5)->value, -2.5);
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsANonFiniteFloat(): void
    {
        Schema::literals([1.5, \NAN]);
    }

    public function generatesOnlyListedValuesAndReachesEach(): void
    {
        $schema = Schema::literals(['a', 'b', 'c']);
        $random = new Random(3);
        $seen = [];

        for ($i = 0; $i < 60; $i++) {
            $value = $schema->arbitrary()->generate($random)->value;
            Assert::true($schema->parse($value)->ok);
            $seen[$value] = true;
        }

        Assert::same(\count($seen), 3);
    }

    public function exportsAnEnumFragment(): void
    {
        $export = Schema::literals(['a', 1, null])->toJsonSchema();

        Assert::same($export->schema, ['enum' => ['a', 1, null]]);
        Assert::same($export->nonRepresentable, []);
    }

    public function wireIsTheIdentity(): void
    {
        Assert::same(Schema::literals(['a', 1])->toWire(1), 1);
    }
}
