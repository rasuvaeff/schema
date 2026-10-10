<?php

declare(strict_types=1);

namespace Rasuvaeff\Schema\Tests;

use Rasuvaeff\PropertyTesting\Random;
use Rasuvaeff\Schema\Schema;
use Rasuvaeff\Schema\Type\MapSchema;
use Testo\Assert;
use Testo\Assert\ExpectException;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(MapSchema::class)]
final class MapSchemaTest
{
    public function parsesEveryEntry(): void
    {
        $schema = Schema::mapOf(Schema::int());

        $result = $schema->parse(['a' => 1, 'b' => 2]);

        Assert::true($result->ok);
        Assert::same($result->value, ['a' => 1, 'b' => 2]);
    }

    #[DataProvider('rejectionProvider')]
    public function rejectsInvalidInput(mixed $input, string $expected): void
    {
        $schema = Schema::mapOf(Schema::int())->minCount(1)->maxCount(3);

        $result = $schema->parse($input);

        Assert::false($result->ok);
        Assert::same($result->errors[0]->message, $expected);
    }

    public static function rejectionProvider(): iterable
    {
        yield 'not an array' => [
            5,
            'Expected map, got int',
        ];

        yield 'integer key' => [
            ['a' => 1, 0 => 2],
            'Map keys must be strings',
        ];

        yield 'too few entries' => [
            [],
            'Map must contain at least 1 entries, got 0',
        ];

        yield 'too many entries' => [
            ['a' => 1, 'b' => 2, 'c' => 3, 'd' => 4],
            'Map must contain at most 3 entries, got 4',
        ];
    }

    public function valueErrorsCarryTheKey(): void
    {
        $schema = Schema::mapOf(Schema::int()->min(0));

        $result = $schema->parse(['ok' => 1, 'bad' => -1]);

        Assert::false($result->ok);
        Assert::same($result->errors[0]->toPath(), '$.bad');
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
            static fn(): MapSchema => Schema::mapOf(Schema::int())->minCount(-1),
        ];

        yield 'negative max' => [
            static fn(): MapSchema => Schema::mapOf(Schema::int())->maxCount(-1),
        ];

        yield 'min above max' => [
            static fn(): MapSchema => Schema::mapOf(Schema::int())->maxCount(1)->minCount(2),
        ];

        yield 'max below min' => [
            static fn(): MapSchema => Schema::mapOf(Schema::int())->minCount(2)->maxCount(1),
        ];
    }

    public function zeroCountAndEqualBoundsAreLegal(): void
    {
        $zero = Schema::mapOf(Schema::int())->minCount(0)->maxCount(0);
        $equal = Schema::mapOf(Schema::int())->minCount(2)->maxCount(2);

        Assert::true($zero->parse([])->ok);
        Assert::true($equal->parse(['a' => 1, 'b' => 2])->ok);
        Assert::false($equal->parse(['a' => 1])->ok);
        Assert::false($equal->parse(['a' => 1, 'b' => 2, 'c' => 3])->ok);
    }

    public function generatedValuesAlwaysParse(): void
    {
        $schema = Schema::mapOf(Schema::int()->min(-2)->max(2))->maxCount(4);

        $random = new Random(17);

        for ($i = 0; $i < 25; $i++) {
            Assert::true($schema->parse($schema->arbitrary()->generate($random)->value)->ok);
        }
    }
}
