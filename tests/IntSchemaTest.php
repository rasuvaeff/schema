<?php

declare(strict_types=1);

namespace Rasuvaeff\Schema\Tests;

use Rasuvaeff\PropertyTesting\Random;
use Rasuvaeff\Schema\Schema;
use Rasuvaeff\Schema\Type\IntSchema;
use Testo\Assert;
use Testo\Assert\ExpectException;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(IntSchema::class)]
final class IntSchemaTest
{
    #[DataProvider('rejectionProvider')]
    public function rejectsInvalidInput(mixed $input, string $expected): void
    {
        $schema = Schema::int()->min(2)->max(10);

        $result = $schema->parse($input);

        Assert::false($result->ok);
        Assert::same($result->errors[0]->message, $expected);
    }

    public static function rejectionProvider(): iterable
    {
        yield 'not an int' => [
            '5',
            'Expected int, got string',
        ];

        yield 'bool is not an int' => [
            true,
            'Expected int, got bool',
        ];

        yield 'below min' => [
            1,
            'Int must be at least 2, got 1',
        ];

        yield 'above max' => [
            11,
            'Int must be at most 10, got 11',
        ];
    }

    public function parsesWithinBounds(): void
    {
        Assert::same(Schema::int()->min(2)->max(10)->parseOrThrow(5), 5);
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
            static fn(): IntSchema => Schema::int()->max(2)->min(3),
        ];

        yield 'max below min via max' => [
            static fn(): IntSchema => Schema::int()->min(3)->max(2),
        ];
    }

    public function generatedValuesAlwaysParse(): void
    {
        $schema = Schema::int()->min(-3)->max(7);

        $random = new Random(11);

        for ($i = 0; $i < 25; $i++) {
            Assert::true($schema->parse($schema->arbitrary()->generate($random)->value)->ok);
        }
    }

    public function equalBoundsAllowExactlyOneValue(): void
    {
        $schema = Schema::int()->min(5)->max(5);

        Assert::true($schema->parse(5)->ok);
        Assert::false($schema->parse(4)->ok);
        Assert::false($schema->parse(6)->ok);
    }

    public function generatorRespectsAHighMinWithoutAMax(): void
    {
        $schema = Schema::int()->min(5000);

        $random = new Random(3);

        for ($i = 0; $i < 25; $i++) {
            Assert::true($schema->parse($schema->arbitrary()->generate($random)->value)->ok);
        }
    }

    public function positiveMeansAtLeastOne(): void
    {
        $schema = Schema::int()->positive();

        Assert::true($schema->parse(1)->ok);
        Assert::false($schema->parse(0)->ok);
        Assert::same($schema->parse(0)->errors[0]->message, 'Int must be at least 1, got 0');
        Assert::false($schema->parse(-5)->ok);
        Assert::same($schema->min, 1);
    }

    public function nonNegativeMeansAtLeastZero(): void
    {
        $schema = Schema::int()->nonNegative();

        Assert::true($schema->parse(0)->ok);
        Assert::false($schema->parse(-1)->ok);
        Assert::same($schema->parse(-1)->errors[0]->message, 'Int must be at least 0, got -1');
        Assert::same($schema->min, 0);
    }

    public function shortcutsChainWithMaxAndExportIdenticallyToMin(): void
    {
        $positive = Schema::int()->positive()->max(100);

        Assert::same($positive->min, 1);
        Assert::same($positive->max, 100);
        Assert::same($positive->toJsonSchema()->schema, ['type' => 'integer', 'minimum' => 1, 'maximum' => 100]);

        $nonNegative = Schema::int()->nonNegative();

        Assert::same($nonNegative->toJsonSchema()->schema, ['type' => 'integer', 'minimum' => 0]);
    }

    public function generatorHonorsPositive(): void
    {
        $schema = Schema::int()->positive();

        $random = new Random(19);

        for ($i = 0; $i < 25; $i++) {
            Assert::true($schema->parse($schema->arbitrary()->generate($random)->value)->ok);
        }
    }

    public function equalBoundsInEitherSetterOrder(): void
    {
        $viaMin = Schema::int()->max(2)->min(2);
        $viaMax = Schema::int()->min(-1)->max(-1);

        Assert::same($viaMin->min, 2);
        Assert::same($viaMin->max, 2);
        Assert::same($viaMax->min, -1);
        Assert::same($viaMax->max, -1);
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function positiveConflictsWithALowerMaxEagerly(): void
    {
        Schema::int()->max(0)->positive();
    }

    #[DataProvider('coercionProvider')]
    public function coerceNormalizesCanonicalIntStrings(string $input, int $expected): void
    {
        $result = Schema::int()->coerce()->parse($input);

        Assert::true($result->ok);
        Assert::same($result->value, $expected);
    }

    public static function coercionProvider(): iterable
    {
        yield 'positive' => ['5', 5];

        yield 'negative' => ['-3', -3];

        yield 'zero' => ['0', 0];
    }

    #[DataProvider('coercionRejectionProvider')]
    public function coerceRejectsNonCanonicalIntStrings(string $input): void
    {
        $result = Schema::int()->coerce()->parse($input);

        Assert::false($result->ok);
        Assert::same($result->errors[0]->message, 'Expected int, got string');
    }

    public static function coercionRejectionProvider(): iterable
    {
        yield 'leading zeros' => ['007'];

        yield 'float string' => ['5.0'];

        yield 'plus sign' => ['+5'];

        yield 'whitespace' => [' 5'];

        yield 'beyond the int range' => ['9223372036854775808'];

        yield 'not a number' => ['five'];
    }

    public function coerceAppliesTheBoundsAfterNormalization(): void
    {
        $result = Schema::int()->positive()->coerce()->parse('0');

        Assert::false($result->ok);
        Assert::same($result->errors[0]->message, 'Int must be at least 1, got 0');
        Assert::true(Schema::int()->positive()->coerce()->parse('2')->ok);
    }

    public function coerceKeepsAcceptingRealIntsAndChainsThroughBounds(): void
    {
        $schema = Schema::int()->min(0)->max(10)->coerce();

        Assert::same($schema->min, 0);
        Assert::same($schema->max, 10);
        Assert::true($schema->coerce);
        Assert::true($schema->parse(5)->ok);
        Assert::same($schema->parse('7')->value, 7);
    }

    public function coerceIsRecordedInTheExport(): void
    {
        $export = Schema::int()->coerce()->toJsonSchema();

        Assert::same($export->schema, ['type' => 'integer']);
        Assert::same($export->nonRepresentable[0]->construct, 'coerce');
        Assert::same(Schema::int()->toJsonSchema()->nonRepresentable, []);
    }
}
