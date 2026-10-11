<?php

declare(strict_types=1);

namespace Rasuvaeff\Schema\Tests;

use Rasuvaeff\PropertyTesting\Random;
use Rasuvaeff\Schema\Schema;
use Rasuvaeff\Schema\Type\FloatSchema;
use Testo\Assert;
use Testo\Assert\ExpectException;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(FloatSchema::class)]
final class FloatSchemaTest
{
    public function parsesAValidFloat(): void
    {
        $schema = Schema::float()->min(-1.5)->max(2.5);

        $result = $schema->parse(0.5);

        Assert::true($result->ok);
        Assert::same($result->value, 0.5);
    }

    public function parseOrThrowReturnsTheFloat(): void
    {
        Assert::same(Schema::float()->parseOrThrow(1.25), 1.25);
    }

    #[DataProvider('rejectionProvider')]
    public function rejectsInvalidInput(mixed $input, string $expected): void
    {
        $schema = Schema::float()->min(-2.0)->max(4.0);

        $result = $schema->parse($input);

        Assert::false($result->ok);
        Assert::same($result->errors[0]->message, $expected);
    }

    public static function rejectionProvider(): iterable
    {
        yield 'not a float' => [
            '0.5',
            'Expected float, got string',
        ];

        yield 'int without allowInt' => [
            5,
            'Expected float, got int',
        ];

        yield 'bool' => [
            true,
            'Expected float, got bool',
        ];

        yield 'nan' => [
            \NAN,
            'Float must be finite, got NAN',
        ];

        yield 'infinity' => [
            \INF,
            'Float must be finite, got INF',
        ];

        yield 'negative infinity' => [
            -\INF,
            'Float must be finite, got -INF',
        ];

        yield 'below min' => [
            -2.5,
            'Float must be at least -2, got -2.5',
        ];

        yield 'above max' => [
            4.5,
            'Float must be at most 4, got 4.5',
        ];
    }

    public function allowIntNormalizesWholeNumbers(): void
    {
        $schema = Schema::float()->allowInt();

        $result = $schema->parse(5);

        Assert::true($result->ok);
        Assert::same($result->value, 5.0);
        Assert::true($schema->parse(5.5)->ok);
        Assert::same($schema->parse(5.5)->value, 5.5);
    }

    public function allowIntStillAppliesTheBounds(): void
    {
        $schema = Schema::float()->min(0.5)->max(3.5)->allowInt();

        Assert::false($schema->parse(4)->ok);
        Assert::same($schema->parse(4)->errors[0]->message, 'Float must be at most 3.5, got 4');
        Assert::false($schema->parse(0)->ok);
    }

    public function equalBoundsAllowExactlyOneValue(): void
    {
        $schema = Schema::float()->min(1.5)->max(1.5);

        Assert::true($schema->parse(1.5)->ok);
        Assert::false($schema->parse(1.4)->ok);
        Assert::false($schema->parse(1.6)->ok);
    }

    public function equalBoundsInEitherSetterOrder(): void
    {
        $viaMin = Schema::float()->max(2.5)->min(2.5);
        $viaMax = Schema::float()->min(-1.0)->max(-1.0);

        Assert::same($viaMin->min, 2.5);
        Assert::same($viaMin->max, 2.5);
        Assert::same($viaMax->min, -1.0);
        Assert::same($viaMax->max, -1.0);
    }

    #[DataProvider('invalidBoundProvider')]
    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsInvalidBounds(callable $build): void
    {
        $build();
    }

    public static function invalidBoundProvider(): iterable
    {
        yield 'min above max via min' => [
            static fn(): FloatSchema => Schema::float()->max(2.0)->min(3.0),
        ];

        yield 'max below min via max' => [
            static fn(): FloatSchema => Schema::float()->min(3.0)->max(2.0),
        ];

        yield 'non-finite min' => [
            static fn(): FloatSchema => Schema::float()->min(\NAN),
        ];

        yield 'non-finite max' => [
            static fn(): FloatSchema => Schema::float()->max(\INF),
        ];
    }

    public function generatedValuesAlwaysParse(): void
    {
        $schema = Schema::float()->min(-2.5)->max(2.5);

        $random = new Random(23);

        for ($i = 0; $i < 25; $i++) {
            $value = $schema->arbitrary()->generate($random)->value;

            Assert::true($schema->parse($value)->ok, \sprintf('value #%d failed to parse', $i));
        }
    }

    public function generatorRespectsAHighMinWithoutAMax(): void
    {
        $schema = Schema::float()->min(4000.0);

        $random = new Random(5);

        for ($i = 0; $i < 25; $i++) {
            Assert::true($schema->parse($schema->arbitrary()->generate($random)->value)->ok);
        }
    }

    public function generatedValuesIncludeTheInclusiveLowerBound(): void
    {
        $schema = Schema::float()->min(0.0)->max(0.0);

        Assert::true($schema->parse($schema->arbitrary()->generate(new Random(1))->value)->ok);
    }

    public function exportsNumberWithBounds(): void
    {
        Assert::same(Schema::float()->toJsonSchema()->schema, ['type' => 'number']);

        $bounded = Schema::float()->min(-0.5)->max(0.5)->toJsonSchema();

        Assert::same($bounded->schema, ['type' => 'number', 'minimum' => -0.5, 'maximum' => 0.5]);
        Assert::same($bounded->nonRepresentable, []);
        $bounded->assertFullyRepresentable();
    }

    public function wirePassesFloatsThrough(): void
    {
        Assert::same(Schema::float()->min(0.0)->max(1.0)->toWire(0.25), 0.25);

        $nested = Schema::object(['ratio' => Schema::float()->min(0.0)->max(1.0)]);

        Assert::same($nested->toWire(['ratio' => 0.25]), ['ratio' => 0.25]);
    }

    public function allowIntIsVisibleOnTheInstance(): void
    {
        $schema = Schema::float()->allowInt();

        Assert::true($schema->allowInt);
        Assert::false(Schema::float()->allowInt);
        Assert::true(Schema::float()->allowInt()->parse(3)->ok);
    }

    #[DataProvider('coercionProvider')]
    public function coerceNormalizesCanonicalNumberStrings(string $input, float $expected): void
    {
        $result = Schema::float()->coerce()->parse($input);

        Assert::true($result->ok);
        Assert::same($result->value, $expected);
    }

    public static function coercionProvider(): iterable
    {
        yield 'fraction' => ['1.5', 1.5];

        yield 'whole' => ['2', 2.0];

        yield 'negative exponent' => ['-3e2', -300.0];

        yield 'exponent with sign' => ['1.5e+2', 150.0];
    }

    #[DataProvider('coercionRejectionProvider')]
    public function coerceRejectsNonCanonicalNumberStrings(string $input): void
    {
        $result = Schema::float()->coerce()->parse($input);

        Assert::false($result->ok);
        Assert::same($result->errors[0]->message, 'Expected float, got string');
    }

    public static function coercionRejectionProvider(): iterable
    {
        yield 'leading zeros' => ['01'];

        yield 'whitespace' => [' 1'];

        yield 'hex' => ['0x1A'];

        yield 'infinity' => ['INF'];

        yield 'not a number' => ['half'];
    }

    public function coerceAppliesTheBoundsAfterNormalization(): void
    {
        $schema = Schema::float()->min(0.0)->max(1.0)->coerce();

        Assert::true($schema->parse('0.5')->ok);
        Assert::same(
            $schema->parse('1.5')->errors[0]->message,
            'Float must be at most 1, got 1.5',
        );
    }

    public function coerceCombinesWithAllowInt(): void
    {
        $schema = Schema::float()->allowInt()->coerce();

        Assert::true($schema->parse(5)->ok);
        Assert::same($schema->parse('2.5')->value, 2.5);
        Assert::same($schema->parse('2')->value, 2.0);
    }

    public function coerceIsRecordedInTheExport(): void
    {
        $export = Schema::float()->coerce()->toJsonSchema();

        Assert::same($export->schema, ['type' => 'number']);
        Assert::same($export->nonRepresentable[0]->construct, 'coerce');
        Assert::same(Schema::float()->toJsonSchema()->nonRepresentable, []);
    }
}
