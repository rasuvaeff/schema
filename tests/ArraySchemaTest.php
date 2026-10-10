<?php

declare(strict_types=1);

namespace Rasuvaeff\Schema\Tests;

use Rasuvaeff\PropertyTesting\Random;
use Rasuvaeff\Schema\Schema;
use Rasuvaeff\Schema\Type\ArraySchema;
use Testo\Assert;
use Testo\Assert\ExpectException;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(ArraySchema::class)]
final class ArraySchemaTest
{
    public function parsesEveryElement(): void
    {
        $schema = Schema::arrayOf(Schema::int()->min(0));

        $result = $schema->parse([1, 2, 3]);

        Assert::true($result->ok);
        Assert::same($result->value, [1, 2, 3]);
    }

    #[DataProvider('rejectionProvider')]
    public function rejectsInvalidInput(mixed $input, string $expected): void
    {
        $schema = Schema::arrayOf(Schema::int())->minCount(1)->maxCount(3);

        $result = $schema->parse($input);

        Assert::false($result->ok);
        Assert::same($result->errors[0]->message, $expected);
    }

    public static function rejectionProvider(): iterable
    {
        yield 'not an array' => [
            'nope',
            'Expected list, got string',
        ];

        yield 'map instead of list' => [
            ['a' => 1],
            'Expected a sequential list, got a map',
        ];

        yield 'too few items' => [
            [],
            'List must contain at least 1 items, got 0',
        ];

        yield 'too many items' => [
            [1, 2, 3, 4],
            'List must contain at most 3 items, got 4',
        ];
    }

    public function elementErrorsCarryTheItemIndex(): void
    {
        $schema = Schema::arrayOf(Schema::int()->min(0));

        $result = $schema->parse([1, -5, -7]);

        Assert::false($result->ok);
        Assert::same(\count($result->errors), 2);
        Assert::same($result->errors[0]->toPath(), '$[1]');
        Assert::same($result->errors[1]->toPath(), '$[2]');
    }

    #[DataProvider('invalidBoundProvider')]
    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsInvalidBounds(callable $build): void
    {
        $build();
    }

    public static function invalidBoundProvider(): iterable
    {
        yield 'negative min' => [
            static fn(): ArraySchema => Schema::arrayOf(Schema::int())->minCount(-1),
        ];

        yield 'negative max' => [
            static fn(): ArraySchema => Schema::arrayOf(Schema::int())->maxCount(-1),
        ];

        yield 'min above max' => [
            static fn(): ArraySchema => Schema::arrayOf(Schema::int())->maxCount(1)->minCount(2),
        ];

        yield 'max below min' => [
            static fn(): ArraySchema => Schema::arrayOf(Schema::int())->minCount(2)->maxCount(1),
        ];
    }

    public function generatedValuesAlwaysParse(): void
    {
        $schema = Schema::arrayOf(Schema::int()->min(-2)->max(2))->maxCount(4);

        $random = new Random(13);

        for ($i = 0; $i < 25; $i++) {
            Assert::true($schema->parse($schema->arbitrary()->generate($random)->value)->ok);
        }
    }
}
