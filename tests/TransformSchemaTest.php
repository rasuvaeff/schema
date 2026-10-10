<?php

declare(strict_types=1);

namespace Rasuvaeff\Schema\Tests;

use Rasuvaeff\PropertyTesting\Random;
use Rasuvaeff\Schema\Combinator\TransformSchema;
use Rasuvaeff\Schema\Schema;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(TransformSchema::class)]
final class TransformSchemaTest
{
    public function mapsTheParsedValue(): void
    {
        $schema = Schema::int()
            ->min(0)
            ->transform(static fn(int $value): string => (string) $value);

        $result = $schema->parse(42);

        Assert::true($result->ok);
        Assert::same($result->value, '42');
    }

    public function sourceFailuresPassThrough(): void
    {
        $schema = Schema::int()->transform(static fn(int $value): int => $value);

        $result = $schema->parse('nope');

        Assert::false($result->ok);
        Assert::same($result->errors[0]->message, 'Expected int, got string');
    }

    public function aThrowingCallbackBecomesAnErrorNamingTheClassOnly(): void
    {
        $schema = Schema::int()->transform(
            static function (int $value): int {
                throw new \DomainException('secret ' . $value);
            },
        );

        $result = $schema->parse(7);

        Assert::false($result->ok);
        Assert::same($result->errors[0]->message, 'Transform callback failed (DomainException)');
    }

    public function generatedValuesAreMappedIntoTheTargetSpace(): void
    {
        $schema = Schema::int()->min(0)->max(9)->transform(static fn(int $value): string => '#' . $value);

        $random = new Random(37);

        for ($i = 0; $i < 25; $i++) {
            // The generator produces target-space values: '#<int>'.
            $target = $schema->arbitrary()->generate($random)->value;

            Assert::string($target)->contains('#');

            // Parity for a transform goes through the source: a generated
            // source value parses into the mapped target value.
            $source = Schema::int()->min(0)->max(9)->arbitrary()->generate($random)->value;

            Assert::same($schema->parse($source)->value, '#' . $source);
        }
    }

    public function fromIsNeverUsedByParse(): void
    {
        $from = static function (string $value): int {
            throw new \LogicException('from must not be called');
        };

        $schema = Schema::int()->transform(
            static fn(int $value): string => (string) $value,
            $from,
        );

        Assert::true($schema->parse(5)->ok);
    }
}
