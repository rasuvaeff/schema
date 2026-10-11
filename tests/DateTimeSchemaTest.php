<?php

declare(strict_types=1);

namespace Rasuvaeff\Schema\Tests;

use Rasuvaeff\PropertyTesting\Random;
use Rasuvaeff\Schema\NonRepresentableException;
use Rasuvaeff\Schema\Schema;
use Rasuvaeff\Schema\Type\DateTimeSchema;
use Testo\Assert;
use Testo\Assert\ExpectException;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(DateTimeSchema::class)]
final class DateTimeSchemaTest
{
    private const string WIRE = '2026-10-11T10:15:30.123+00:00';

    public function parsesAnRfc3339StringIntoAnInstance(): void
    {
        $result = Schema::dateTime()->parse(self::WIRE);

        Assert::true($result->ok);
        Assert::instanceOf($result->value, \DateTimeImmutable::class);
        Assert::same($result->value->format(\DATE_RFC3339_EXTENDED), self::WIRE);
    }

    public function acceptsTheZuluOffset(): void
    {
        $result = Schema::dateTime()->parse('2026-10-11T10:15:30.123Z');

        Assert::true($result->ok);
        Assert::same($result->value->getTimezone()->getName(), 'Z');
    }

    public function parseOrThrowReturnsTheInstance(): void
    {
        Assert::instanceOf(Schema::dateTime()->parseOrThrow(self::WIRE), \DateTimeImmutable::class);
    }

    #[DataProvider('rejectionProvider')]
    public function rejectsInvalidInput(mixed $input, string $expected): void
    {
        $result = Schema::dateTime()->parse($input);

        Assert::false($result->ok);
        Assert::same($result->errors[0]->message, $expected);
    }

    public static function rejectionProvider(): iterable
    {
        yield 'not a string' => [
            42,
            'Expected date-time string, got int',
        ];

        yield 'not a date' => [
            'yesterday',
            'String is not a valid date-time',
        ];

        yield 'missing milliseconds' => [
            '2026-10-11T10:15:30+00:00',
            'String is not a valid date-time',
        ];

        yield 'missing offset' => [
            '2026-10-11T10:15:30.123',
            'String is not a valid date-time',
        ];

        yield 'rolled-over hour' => [
            '2026-10-11T25:00:00.000+00:00',
            'String is not a valid date-time',
        ];

        yield 'trailing data' => [
            '2026-10-11T10:15:30.123+00:00x',
            'String is not a valid date-time',
        ];
    }

    public function boundsAreInclusive(): void
    {
        $min = new \DateTimeImmutable('2026-01-01T00:00:00.000+00:00');
        $max = new \DateTimeImmutable('2026-12-31T23:59:59.999+00:00');
        $schema = Schema::dateTime()->min($min)->max($max);

        Assert::true($schema->parse($min->format(\DATE_RFC3339_EXTENDED))->ok);
        Assert::true($schema->parse($max->format(\DATE_RFC3339_EXTENDED))->ok);
        Assert::same(
            $schema->parse('2027-01-01T00:00:00.000+00:00')->errors[0]->message,
            'Date-time must be at most 2026-12-31T23:59:59.999+00:00',
        );
        Assert::same(
            $schema->parse('2025-12-31T23:59:59.999+00:00')->errors[0]->message,
            'Date-time must be at least 2026-01-01T00:00:00.000+00:00',
        );
    }

    public function equalBoundsInEitherSetterOrder(): void
    {
        $moment = new \DateTimeImmutable('2026-06-01T12:00:00.000+00:00');
        $viaMin = Schema::dateTime()->max($moment)->min($moment);
        $viaMax = Schema::dateTime()->min($moment)->max($moment);

        Assert::same($viaMin->min, $moment);
        Assert::same($viaMin->max, $moment);
        Assert::same($viaMax->min, $moment);
        Assert::same($viaMax->max, $moment);
        Assert::true($viaMin->parse($moment->format(\DATE_RFC3339_EXTENDED))->ok);
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
            static fn(): DateTimeSchema => Schema::dateTime()
                ->max(new \DateTimeImmutable('2026-01-01T00:00:00.000+00:00'))
                ->min(new \DateTimeImmutable('2026-06-01T00:00:00.000+00:00')),
        ];

        yield 'max below min via max' => [
            static fn(): DateTimeSchema => Schema::dateTime()
                ->min(new \DateTimeImmutable('2026-06-01T00:00:00.000+00:00'))
                ->max(new \DateTimeImmutable('2026-01-01T00:00:00.000+00:00')),
        ];
    }

    public function generatedValuesRespectTheBoundsThroughTheWire(): void
    {
        $min = new \DateTimeImmutable('2026-01-01T00:00:00.000+00:00');
        $max = new \DateTimeImmutable('2026-12-31T23:59:59.999+00:00');
        $schema = Schema::dateTime()->min($min)->max($max);

        $random = new Random(31);

        for ($i = 0; $i < 25; $i++) {
            $value = $schema->arbitrary()->generate($random)->value;

            Assert::true($value >= $min && $value <= $max, \sprintf('value #%d escaped the bounds', $i));
            Assert::true($schema->parse($schema->toWire($value))->ok, \sprintf('value #%d failed to parse', $i));
        }
    }

    /**
     * The generator lives in the PHP domain, parse accepts the wire
     * string — the same split a transform carries, so the pinned law is
     * the wire round trip, not the direct parse.
     */
    public function generatedValuesRoundTripThroughTheWire(): void
    {
        $schema = Schema::dateTime();

        $random = new Random(37);

        for ($i = 0; $i < 25; $i++) {
            $value = $schema->arbitrary()->generate($random)->value;
            $wire = $schema->toWire($value);

            Assert::true($schema->parse($wire)->ok, \sprintf('wire value #%d failed to parse', $i));
        }
    }

    public function exportsAStringFormatDateTimeFragment(): void
    {
        $export = Schema::dateTime()->toJsonSchema();

        Assert::same($export->schema, ['type' => 'string', 'format' => 'date-time']);
        Assert::same($export->nonRepresentable, []);
    }

    public function boundsAreNotRepresentableAndRecorded(): void
    {
        $export = Schema::dateTime()->min(new \DateTimeImmutable('2026-01-01T00:00:00.000+00:00'))->toJsonSchema();

        Assert::same($export->schema, ['type' => 'string', 'format' => 'date-time']);
        Assert::same($export->nonRepresentable[0]->construct, 'dateTime bounds');
        Assert::same($export->nonRepresentable[0]->toPath(), '$');
    }

    public function boundsExportFailsFullyRepresentable(): void
    {
        $export = Schema::dateTime()->max(new \DateTimeImmutable('2026-01-01T00:00:00.000+00:00'))->toJsonSchema();

        try {
            $export->assertFullyRepresentable();
        } catch (NonRepresentableException $dropped) {
            Assert::string($dropped->getMessage())->contains('dateTime bounds');

            return;
        }

        throw new \LogicException('assertFullyRepresentable was expected to throw');
    }

    public function wireProjectsNestedInsideObjects(): void
    {
        $schema = Schema::object(['createdAt' => Schema::dateTime()]);

        $wire = $schema->toWire(['createdAt' => new \DateTimeImmutable(self::WIRE)]);

        Assert::same($wire, ['createdAt' => self::WIRE]);
    }

    public function wirePassesNonInstancesThrough(): void
    {
        $alreadyWire = Schema::dateTime()->toWire(self::WIRE);

        Assert::same($alreadyWire, self::WIRE);
    }
}
