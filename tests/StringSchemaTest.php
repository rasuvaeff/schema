<?php

declare(strict_types=1);

namespace Rasuvaeff\Schema\Tests;

use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Random;
use Rasuvaeff\Schema\Schema;
use Rasuvaeff\Schema\Type\StringSchema;
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
        $schema = Schema::string()->min(2)->max(4)->regex('^[a-z]+$');

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
            static fn(): StringSchema => Schema::string()->min(-1),
        ];

        yield 'negative max' => [
            static fn(): StringSchema => Schema::string()->max(-1),
        ];

        yield 'min above max via min' => [
            static fn(): StringSchema => Schema::string()->max(2)->min(3),
        ];

        yield 'max below min via max' => [
            static fn(): StringSchema => Schema::string()->min(3)->max(2),
        ];
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsAnInvalidPattern(): void
    {
        Schema::string()->regex('[a-');
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsAPatternTheGeneratorCannotCompile(): void
    {
        Schema::string()->regex('^[a-z]+\z');
    }

    public function regexUncheckedAcceptsPatternsOutsideTheSubset(): void
    {
        $pattern = '^a+\z';
        $generator = Gen::map(Gen::intBetween(1, 8), static fn(int $length): string => \str_repeat('a', $length));

        $schema = Schema::string()->regexUnchecked($pattern, $generator);

        Assert::same($schema->pattern, $pattern);

        $random = new Random(71);

        for ($i = 0; $i < 25; $i++) {
            $value = $schema->arbitrary()->generate($random)->value;

            Assert::true($schema->parse($value)->ok, \sprintf('value #%d failed to parse', $i));
        }
    }

    public function regexUncheckedStillEnforcesThePatternOnParse(): void
    {
        $schema = Schema::string()->regexUnchecked('^a+\z', Gen::map(Gen::intBetween(1, 8), static fn(int $length): string => \str_repeat('a', $length)));

        Assert::false($schema->parse('bbb')->ok);
        Assert::same($schema->parse('bbb')->errors[0]->message, 'String does not match the required pattern');
        Assert::same($schema->parse('aaa')->ok, expected: true);
    }

    public function regexUncheckedKeepsLengthBounds(): void
    {
        $schema = Schema::string()->regexUnchecked('^a*\z', Gen::map(Gen::intBetween(0, 8), static fn(int $length): string => \str_repeat('a', $length)))->min(2)->max(5);

        $random = new Random(73);

        for ($i = 0; $i < 25; $i++) {
            $value = $schema->arbitrary()->generate($random)->value;

            Assert::true($schema->parse($value)->ok, \sprintf('value #%d failed to parse', $i));
        }
    }

    public function regexUncheckedExportsThePatternAsIs(): void
    {
        $schema = Schema::string()->regexUnchecked('^a+\z', Gen::map(Gen::intBetween(1, 8), static fn(int $length): string => \str_repeat('a', $length)));

        $export = $schema->toJsonSchema();

        Assert::same($export->schema, ['type' => 'string', 'pattern' => '^a+\z']);
        Assert::same(\count($export->nonRepresentable), 1);
        Assert::same($export->nonRepresentable[0]->construct, 'regexUnchecked');
        Assert::same(Schema::string()->regex('^a+$')->toJsonSchema()->nonRepresentable, []);
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function regexUncheckedStillRejectsInvalidPcre(): void
    {
        Schema::string()->regexUnchecked('[a-', Gen::string());
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function regexUncheckedRejectsAnEmptyPattern(): void
    {
        Schema::string()->regexUnchecked('', Gen::string());
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function aPatternGeneratorWithoutAPatternIsRejected(): void
    {
        new StringSchema(patternGenerator: Gen::string());
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
        $schema = Schema::string()->regex('^[a-c]{2,4}$')->min(2)->max(4);

        $random = new Random(7);

        for ($i = 0; $i < 25; $i++) {
            $value = $schema->arbitrary()->generate($random)->value;

            Assert::true($schema->parse($value)->ok, \sprintf('value #%d failed to parse', $i));
        }
    }

    #[DataProvider('invalidUtf8Provider')]
    public function rejectsInvalidUtf8BeforeAnyConstraint(string $input): void
    {
        $result = Schema::string()->min(1)->regex('^.+$')->parse($input);

        Assert::false($result->ok);
        Assert::same($result->errors[0]->message, 'String is not valid UTF-8');
        Assert::same($result->errors[0]->toPath(), '$');
    }

    public static function invalidUtf8Provider(): iterable
    {
        yield 'invalid two-byte sequence' => ["\xC3\x28"];

        yield 'stray continuation byte' => ["a\xB1b"];

        yield 'truncated three-byte sequence' => ["\xE2\x82"];

        yield 'overlong encoding' => ["\xC0\xAF"];

        yield 'encoded lone surrogate half' => ["\xED\xA0\x80"];

        yield 'trailing invalid byte after valid text' => ["hello\xFF"];
    }

    public function acceptsValidUtf8IncludingAstral(): void
    {
        $schema = Schema::string()->min(3);

        Assert::true($schema->parse('ёжик 🦔')->ok);
    }

    public function reportsAnEngineFailureDistinctlyFromAMismatch(): void
    {
        // A pattern fed through the constructor bypasses regex()'s eager
        // probe, so its compilation fails only at match time — the one
        // engine failure a modern PCRE (10.43+ minimizes catastrophic
        // backtracking) still hands us deterministically.
        $schema = new StringSchema(pattern: '[a-');

        \set_error_handler(static fn(int $severity, string $message): bool => true);

        try {
            $result = $schema->parse('abc');
        } finally {
            \restore_error_handler();
        }

        Assert::false($result->ok);
        Assert::string($result->errors[0]->message)->contains('PCRE engine error');
        Assert::string($result->errors[0]->message)->contains('Internal error');
    }

    public function engineFailureIsCheckedAfterEncoding(): void
    {
        // Invalid UTF-8 plus an uncompilable pattern: the encoding error
        // wins, so the engine verdict cannot disagree about bytes.
        $schema = new StringSchema(pattern: '[a-');

        \set_error_handler(static fn(int $severity, string $message): bool => true);

        try {
            $result = $schema->parse("\xFF");
        } finally {
            \restore_error_handler();
        }

        Assert::false($result->ok);
        Assert::same($result->errors[0]->message, 'String is not valid UTF-8');
    }
}
