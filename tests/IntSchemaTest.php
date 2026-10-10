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
            static fn (): IntSchema => Schema::int()->max(2)->min(3),
        ];

        yield 'max below min via max' => [
            static fn (): IntSchema => Schema::int()->min(3)->max(2),
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

    public function generatorRespectsAHighMinWithoutAMax(): void
    {
        $schema = Schema::int()->min(5000);

        $random = new Random(3);

        for ($i = 0; $i < 25; $i++) {
            Assert::true($schema->parse($schema->arbitrary()->generate($random)->value)->ok);
        }
    }
}
