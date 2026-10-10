<?php

declare(strict_types=1);

namespace Rasuvaeff\Schema\Tests;

use Rasuvaeff\PropertyTesting\Random;
use Rasuvaeff\Schema\Schema;
use Rasuvaeff\Schema\Type\JsonValueSchema;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(JsonValueSchema::class)]
final class JsonValueSchemaTest
{
    #[DataProvider('jsonValueProvider')]
    public function acceptsEverythingJsonDecodeCanProduce(mixed $input): void
    {
        Assert::true(Schema::json()->parse($input)->ok);
    }

    public static function jsonValueProvider(): iterable
    {
        yield 'null' => [null];

        yield 'int' => [7];

        yield 'float' => [1.5];

        yield 'string' => ['hello'];

        yield 'bool' => [false];

        yield 'list' => [[1, 'two', null]];

        yield 'nested map' => [['a' => ['b' => [true, 3.5]]]];
    }

    #[DataProvider('nonJsonProvider')]
    public function rejectsValuesOutsideTheJsonDomain(mixed $input): void
    {
        $result = Schema::json()->parse($input);

        Assert::false($result->ok);
        Assert::same($result->errors[0]->message, \sprintf('Expected a JSON value, got %s', \get_debug_type($input)));
    }

    public static function nonJsonProvider(): iterable
    {
        yield 'NAN' => [\NAN];

        yield 'INF' => [\INF];

        yield 'object' => [new \stdClass()];

        yield 'resource' => [\fopen('php://memory', 'rb')];

        yield 'mixed keys' => [['a' => 1, 0 => 2]];
    }

    public function generatedValuesAlwaysParse(): void
    {
        $schema = Schema::json();

        $random = new Random(9);

        for ($i = 0; $i < 25; $i++) {
            Assert::true($schema->parse($schema->arbitrary()->generate($random)->value)->ok);
        }
    }
}
