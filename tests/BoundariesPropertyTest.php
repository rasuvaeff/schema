<?php

declare(strict_types=1);

namespace Rasuvaeff\Schema\Tests;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Classify;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Rasuvaeff\Schema\Schema;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

/**
 * Law 3: generators reach their constraint boundaries, not just the
 * middle of the range — pinned with a Classify coverage gate.
 */
#[Test]
#[Covers(Schema::class)]
final class BoundariesPropertyTest
{
    #[Property(runs: 300)]
    public function intGeneratorReachesItsBounds(int $min, int $span): void
    {
        $max = $min + $span;

        $schema = Schema::int()->min($min)->max($max);
        $value = Gen::draw($schema->arbitrary());

        Classify::cover($value === $min || $value === $max, 'boundary', 5);

        Assert::true($schema->parse($value)->ok);
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function intGeneratorReachesItsBoundsGenerators(): array
    {
        return [
            'min' => Gen::intBetween(-100, 100),
            'span' => Gen::intBetween(0, 8),
        ];
    }

    /**
     * @return iterable<string, list<int>>
     */
    public static function intGeneratorReachesItsBoundsExamples(): iterable
    {
        yield 'zero span' => [5, 0];

        yield 'negative range' => [-10, 4];
    }

    #[Property(runs: 200)]
    public function stringGeneratorReachesItsLengthBounds(int $min, int $span): void
    {
        $max = $min + $span;

        $schema = Schema::string()->min($min)->max($max);
        $value = Gen::draw($schema->arbitrary());
        $length = \mb_strlen($value);

        Classify::cover($length === $min || $length === $max, 'boundary', 5);

        Assert::true($schema->parse($value)->ok);
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function stringGeneratorReachesItsLengthBoundsGenerators(): array
    {
        return [
            'min' => Gen::intBetween(0, 4),
            'span' => Gen::intBetween(0, 8),
        ];
    }

    /**
     * @return iterable<string, list<int>>
     */
    public static function stringGeneratorReachesItsLengthBoundsExamples(): iterable
    {
        yield 'empty string allowed' => [0, 0];

        yield 'tight range' => [3, 2];
    }
}
