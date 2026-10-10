<?php

declare(strict_types=1);

namespace Rasuvaeff\Schema\Tests;

use Rasuvaeff\PropertyTesting\Random;
use Rasuvaeff\Schema\Schema;
use Testo\Assert;
use Testo\Assert\ExpectException;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(StringSchema::class)]
final class StringSchemaTest
{
    public function parsesAValidString(): void
    {
        $schema = Schema::string()->min(2)->max(5);

        $result = $schema->parse('abc');

        Assert::true($result->ok);
        Assert::same($result->value, 'abc');
    }

    public function parseOrThrowReturnsTheString(): void
    {
        Assert::same(Schema::string()->parseOrThrow('abc'), 'abc');
    }

    #[DataProvider('rejectionProvider')]
    public function rejectsInvalidInput(mixed $input, string $expected): void
    {
        $schema = Schema::string()->min(2)->max(4)->regex('/^[a-z]+\z/');

        $result = $schema->parse($input);

        Assert::false($result->ok);
        Assert::same($result->errors[0]->message, $expected);
    }

    public static function rejectionProvider(): iterable
    {
        yield 'not a string' => [
            42,
            'Expected string, got int',
        ];

        yield 'too short' => [
            'a',
            'String length must be at least 2, got 1',
        ];

        yield 'too long' => [
            'abcde',
            'String length must be at most 4, got 5',
        ];

        yield 'pattern mismatch' => [
            'ab1',
            'String does not match the required pattern',
        ];
    }

    public function lengthIsMeasuredInCodePoints(): void
    {
        $schema = Schema::string()->min(3)->max(3);

        Assert::true($schema->parse('ёюя')->ok);
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
            static fn (): StringSchema => Schema::string()->min(-1),
        ];

        yield 'negative max' => [
            static fn (): StringSchema => Schema::string()->max(-1),
        ];

        yield 'min above max via min' => [
            static fn (): StringSchema => Schema::string()->max(2)->min(3),
        ];

        yield 'max below min via max' => [
            static fn (): StringSchema => Schema::string()->min(3)->max(2),
        ];
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsAnInvalidPattern(): void
    {
        Schema::string()->regex('/[a-');
    }

    public function generatedValuesAlwaysParse(): void
    {
        $schema = Schema::string()->min(2)->max(6);

        $random = new Random(42);

        for ($i = 0; $i < 25; $i++) {
            $value = $schema->arbitrary()->generate($random)->value;

            Assert::true($schema->parse($value)->ok, \sprintf('value #%d failed to parse', $i));
        }
    }

    public function generatedPatternValuesAlwaysParse(): void
    {
        $schema = Schema::string()->regex('/^[a-c]{2,4}\z')->min(2)->max(4);

        $random = new Random(7);

        for ($i = 0; $i < 25; $i++) {
            $value = $schema->arbitrary()->generate($random)->value;

            Assert::true($schema->parse($value)->ok, \sprintf('value #%d failed to parse', $i));
        }
    }
}
