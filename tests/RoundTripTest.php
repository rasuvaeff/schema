<?php

declare(strict_types=1);

namespace Rasuvaeff\Schema\Tests;

use Rasuvaeff\PropertyTesting\Random;
use Rasuvaeff\Schema\Schema;
use Rasuvaeff\Schema\Testing\RoundTrip;
use Rasuvaeff\Schema\Testing\RoundTripException;
use Rasuvaeff\Schema\Tests\Support\UserRecord;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(RoundTrip::class)]
#[Covers(RoundTripException::class)]
final class RoundTripTest
{
    public function assertParsesPassesForAGeneratedValue(): void
    {
        $schema = Schema::object(['id' => Schema::int()->positive()]);
        $value = $schema->arbitrary()->generate(new Random(3))->value;

        RoundTrip::assertParses($schema, $value);

        Assert::true(actual: true);
    }

    public function assertParsesThrowsWithTheStructuredErrors(): void
    {
        try {
            RoundTrip::assertParses(Schema::string()->min(3), 'ab');
        } catch (RoundTripException $failure) {
            Assert::same($failure->getMessage(), 'parse of a generated value: $ String length must be at least 3, got 2');

            return;
        }

        throw new \LogicException('assertParses was expected to fail');
    }

    public function wireRoundTripHoldsForADecodeType(): void
    {
        $schema = Schema::object(['at' => Schema::dateTime()]);
        $value = $schema->arbitrary()->generate(new Random(7))->value;

        RoundTrip::assertWireRoundTrip($schema, $value);

        Assert::true(actual: true);
    }

    public function wireRoundTripDetectsLoss(): void
    {
        // A transform whose wire projection appends on every hop: the
        // second projection differs from the first — lossy by design.
        $schema = Schema::string()->transform(
            static fn(string $value): string => $value,
            wire: static fn(string $value): string => $value . '!',
        );

        try {
            RoundTrip::assertWireRoundTrip($schema, 'x');
        } catch (RoundTripException $lossy) {
            Assert::same(
                $lossy->getMessage(),
                'wire round trip is lossy: toWire(parse(toWire(value))) differs from toWire(value) (string vs string)',
            );

            return;
        }

        throw new \LogicException('assertWireRoundTrip was expected to fail');
    }

    public function consumerRoundTripHoldsForAHydrateWirePair(): void
    {
        $schema = Schema::object([
            'name' => Schema::string()->min(1),
        ]);

        $hydrate = static function (mixed $payload) use ($schema): object {
            $parsed = $schema->parseOrThrow($payload);

            return new UserRecord($parsed['name']);
        };
        $wire = static fn(object $dto): array => ['name' => $dto->name];

        $payload = $schema->arbitrary()->generate(new Random(11))->value;

        RoundTrip::assertConsumerRoundTrip($schema, $payload, $hydrate, $wire);

        Assert::true(actual: true);
    }

    public function consumerRoundTripReportsAHydrateFailure(): void
    {
        $schema = Schema::object(['name' => Schema::string()->min(1)]);

        try {
            RoundTrip::assertConsumerRoundTrip(
                $schema,
                ['name' => 'ada'],
                static fn(mixed $payload): object => throw new \LogicException('no'),
                static fn(object $dto): array => [],
            );
        } catch (RoundTripException $failure) {
            Assert::string($failure->getMessage())->contains('hydrate of a generated payload failed (LogicException)');

            return;
        }

        throw new \LogicException('assertConsumerRoundTrip was expected to fail');
    }

    public function consumerRoundTripDetectsDisagreeingWireForms(): void
    {
        $schema = Schema::object(['name' => Schema::string()->min(1)]);

        try {
            RoundTrip::assertConsumerRoundTrip(
                $schema,
                ['name' => 'ada'],
                static fn(array $payload): object => new \stdClass(),
                static fn(object $dto): array => ['name' => 'something else'],
            );
        } catch (RoundTripException $failure) {
            Assert::string($failure->getMessage())->contains('disagree (array vs array)');

            return;
        }

        throw new \LogicException('assertConsumerRoundTrip was expected to fail');
    }

    public function everyParseErrorIsListedOnItsOwnLine(): void
    {
        $schema = Schema::object(['name' => Schema::string()->min(3), 'age' => Schema::int()]);

        try {
            RoundTrip::assertParses($schema, ['name' => 'ab', 'age' => 'x']);
        } catch (RoundTripException $failure) {
            Assert::same(
                $failure->getMessage(),
                "parse of a generated value: $.name String length must be at least 3, got 2\n$.age Expected int, got string",
            );

            return;
        }

        throw new \LogicException('assertParses was expected to fail');
    }

    public function hydrateFailureKeepsTheOriginalException(): void
    {
        $cause = new \LogicException('no');

        try {
            RoundTrip::assertConsumerRoundTrip(
                Schema::object(['name' => Schema::string()]),
                ['name' => 'ada'],
                static fn(mixed $payload): object => throw $cause,
                static fn(object $dto): array => [],
            );
        } catch (RoundTripException $failure) {
            Assert::same($failure->getPrevious(), $cause);
            Assert::same($failure->getCode(), 0);

            return;
        }

        throw new \LogicException('assertConsumerRoundTrip was expected to fail');
    }

    public function rehydrateFailureKeepsTheOriginalException(): void
    {
        $cause = new \LogicException('second hydrate');
        $calls = 0;

        try {
            RoundTrip::assertConsumerRoundTrip(
                Schema::object(['name' => Schema::string()]),
                ['name' => 'ada'],
                static function (mixed $payload) use (&$calls, $cause): object {
                    if (++$calls > 1) {
                        throw $cause;
                    }

                    return new UserRecord('ada');
                },
                static fn(object $dto): array => ['name' => 'ada'],
            );
        } catch (RoundTripException $failure) {
            Assert::same($failure->getMessage(), 'hydrate of the wire form failed (LogicException)');
            Assert::same($failure->getPrevious(), $cause);
            Assert::same($failure->getCode(), 0);

            return;
        }

        throw new \LogicException('assertConsumerRoundTrip was expected to fail');
    }
}
