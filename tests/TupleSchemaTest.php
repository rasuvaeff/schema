<?php

declare(strict_types=1);

namespace Rasuvaeff\Schema\Tests;

use Rasuvaeff\PropertyTesting\Random;
use Rasuvaeff\Schema\Schema;
use Rasuvaeff\Schema\Tests\Support\Status;
use Rasuvaeff\Schema\Type\TupleSchema;
use Testo\Assert;
use Testo\Assert\ExpectException;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(TupleSchema::class)]
final class TupleSchemaTest
{
    public function parsesAFixedHeterogeneousList(): void
    {
        $schema = Schema::tuple([
            Schema::string()->min(1),
            Schema::int(),
            Schema::bool(),
        ]);

        $result = $schema->parse(['yes', 7, false]);

        Assert::true($result->ok);
        Assert::same($result->value, ['yes', 7, false]);
    }

    #[DataProvider('rejectionProvider')]
    public function rejectsInvalidInput(mixed $input, string $path, string $expected): void
    {
        $schema = Schema::tuple([
            Schema::string()->min(1),
            Schema::int(),
        ]);

        $result = $schema->parse($input);

        Assert::false($result->ok);
        Assert::same($result->errors[0]->toPath(), $path);
        Assert::same($result->errors[0]->message, $expected);
    }

    public static function rejectionProvider(): iterable
    {
        yield 'not a list' => [
            'yes',
            '$',
            'Expected a list, got string',
        ];

        yield 'assoc array is not a list' => [
            ['name' => 'yes', 'count' => 7],
            '$',
            'Expected a list, got array',
        ];

        yield 'too short' => [
            ['yes'],
            '$',
            'Expected a list of exactly 2 elements, got 1',
        ];

        yield 'too long' => [
            ['yes', 7, true],
            '$',
            'Expected a list of exactly 2 elements, got 3',
        ];

        yield 'element error carries its index' => [
            ['', 7],
            '$[0]',
            'String length must be at least 1, got 0',
        ];
    }

    public function everyElementErrorIsReported(): void
    {
        $schema = Schema::tuple([
            Schema::int(),
            Schema::int(),
        ]);

        $result = $schema->parse(['a', 'b']);

        Assert::false($result->ok);
        Assert::same(\count($result->errors), 2);
        Assert::same($result->errors[0]->toPath(), '$[0]');
        Assert::same($result->errors[1]->toPath(), '$[1]');
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsAnEmptyElementList(): void
    {
        Schema::tuple([]);
    }

    public function generatedValuesAlwaysParse(): void
    {
        $schema = Schema::tuple([
            Schema::int()->min(-2)->max(2),
            Schema::string()->min(1)->max(4),
        ]);

        $random = new Random(53);

        for ($i = 0; $i < 25; $i++) {
            Assert::true($schema->parse($schema->arbitrary()->generate($random)->value)->ok);
        }
    }

    public function exportsPrefixItemsAndForbidsTheRest(): void
    {
        $export = Schema::tuple([
            Schema::string(),
            Schema::int(),
        ])->toJsonSchema();

        Assert::same(
            $export->schema,
            [
                'type' => 'array',
                'prefixItems' => [
                    ['type' => 'string'],
                    ['type' => 'integer'],
                ],
                'items' => false,
            ],
        );
        Assert::same($export->nonRepresentable, []);
    }

    public function wireWalksTheElements(): void
    {
        $schema = Schema::tuple([
            Schema::enum(Status::class),
            Schema::int(),
        ]);

        $wire = $schema->toWire([Status::Active, 3]);

        Assert::same($wire, ['active', 3]);
    }
}
