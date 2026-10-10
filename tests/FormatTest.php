<?php

declare(strict_types=1);

namespace Rasuvaeff\Schema\Tests;

use Rasuvaeff\PropertyTesting\Random;
use Rasuvaeff\Schema\Format;
use Rasuvaeff\Schema\Schema;
use Rasuvaeff\Schema\Type\StringSchema;
use Testo\Assert;
use Testo\Assert\ExpectException;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(StringSchema::class)]
final class FormatTest
{
    #[DataProvider('formatProvider')]
    public function generatedValuesAlwaysParse(Format $format): void
    {
        $schema = Schema::string()->format($format);

        $random = new Random(19);

        for ($i = 0; $i < 25; $i++) {
            $value = $schema->arbitrary()->generate($random)->value;

            Assert::true($schema->parse($value)->ok, \sprintf('%s value #%d failed to parse', $format->value, $i));
        }
    }

    public static function formatProvider(): iterable
    {
        yield 'email' => [Format::Email];

        yield 'uuid' => [Format::Uuid];

        yield 'uri' => [Format::Url];

        yield 'date-time' => [Format::DateTime];

        yield 'ipv4' => [Format::Ipv4];
    }

    #[DataProvider('rejectionProvider')]
    public function rejectsInvalidFormats(Format $format, string $input): void
    {
        $result = Schema::string()->format($format)->parse($input);

        Assert::false($result->ok);
        Assert::same($result->errors[0]->message, \sprintf('String is not a valid %s', $format->value));
    }

    public static function rejectionProvider(): iterable
    {
        yield 'email' => [
            Format::Email,
            'not-an-email',
        ];

        yield 'uuid' => [
            Format::Uuid,
            'zzzzzzzz-zzzz-zzzz-zzzz-zzzzzzzzzzzz',
        ];

        yield 'uri' => [
            Format::Url,
            'not a url',
        ];

        yield 'date-time' => [
            Format::DateTime,
            'yesterday, probably',
        ];

        yield 'ipv4' => [
            Format::Ipv4,
            '999.999.1.1',
        ];
    }

    public function formatCombinesWithLengthBounds(): void
    {
        $schema = Schema::string()->format(Format::Email)->min(3)->max(254);

        $random = new Random(23);

        for ($i = 0; $i < 25; $i++) {
            Assert::true($schema->parse($schema->arbitrary()->generate($random)->value)->ok);
        }
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function formatAndPatternAreMutuallyExclusive(): void
    {
        Schema::string()->regex('^[a-z]+$')->format(Format::Email);
    }

    public function formatExportsItsJsonSchemaKeyword(): void
    {
        Assert::same(
            Schema::string()->format(Format::Uuid)->toJsonSchema()->schema,
            ['type' => 'string', 'format' => 'uuid'],
        );
    }
}
