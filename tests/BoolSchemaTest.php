<?php

declare(strict_types=1);

namespace Rasuvaeff\Schema\Tests;

use Rasuvaeff\PropertyTesting\Random;
use Rasuvaeff\Schema\Schema;
use Rasuvaeff\Schema\Type\BoolSchema;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(BoolSchema::class)]
final class BoolSchemaTest
{
    public function acceptsBothBooleans(): void
    {
        Assert::true(Schema::bool()->parse(input: true)->ok);
        Assert::same(Schema::bool()->parseOrThrow(input: false), expected: false);
    }

    public function rejectsAnIntegerEvenThoughPhpIsLenient(): void
    {
        $result = Schema::bool()->parse(1);

        Assert::false($result->ok);
        Assert::same($result->errors[0]->message, 'Expected bool, got int');
    }

    public function generatedValuesAlwaysParse(): void
    {
        Assert::true(Schema::bool()->parse(Schema::bool()->arbitrary()->generate(new Random(1))->value)->ok);
    }

    #[DataProvider('coercionProvider')]
    public function coerceNormalizesUnambiguousBoolStrings(string $input, bool $expected): void
    {
        $result = Schema::bool()->coerce()->parse($input);

        Assert::true($result->ok);
        Assert::same($result->value, $expected);
    }

    public static function coercionProvider(): iterable
    {
        yield 'true' => ['true', true];

        yield 'false' => ['false', false];

        yield 'one' => ['1', true];

        yield 'zero' => ['0', false];
    }

    #[DataProvider('coercionRejectionProvider')]
    public function coerceRejectsAmbiguousStrings(string $input): void
    {
        $result = Schema::bool()->coerce()->parse($input);

        Assert::false($result->ok);
        Assert::same($result->errors[0]->message, 'Expected bool, got string');
    }

    public static function coercionRejectionProvider(): iterable
    {
        yield 'yes' => ['yes'];

        yield 'TRUE' => ['TRUE'];

        yield 'empty' => [''];

        yield 'two' => ['2'];
    }

    public function coerceStillAcceptsRealBooleans(): void
    {
        Assert::true(Schema::bool()->coerce()->parse(input: true)->ok);
        Assert::same(Schema::bool()->coerce()->parse(input: false)->value, expected: false);
    }

    public function coerceIsRecordedInTheExport(): void
    {
        $export = Schema::bool()->coerce()->toJsonSchema();

        Assert::same($export->schema, ['type' => 'boolean']);
        Assert::same($export->nonRepresentable[0]->construct, 'coerce');
        Assert::same(Schema::bool()->toJsonSchema()->schema, ['type' => 'boolean']);
        Assert::same(Schema::bool()->toJsonSchema()->nonRepresentable, []);
    }

    public function plainBoolStillRejectsBoolStrings(): void
    {
        $result = Schema::bool()->parse('true');

        Assert::false($result->ok);
        Assert::same($result->errors[0]->message, 'Expected bool, got string');
    }
}
