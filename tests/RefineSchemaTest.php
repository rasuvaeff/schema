<?php

declare(strict_types=1);

namespace Rasuvaeff\Schema\Tests;

use Rasuvaeff\PropertyTesting\Random;
use Rasuvaeff\Schema\Combinator\RefineSchema;
use Rasuvaeff\Schema\Schema;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(RefineSchema::class)]
final class RefineSchemaTest
{
    public function aPassingPredicateKeepsTheValue(): void
    {
        $schema = Schema::int()->refine(
            static fn(int $value): bool => $value % 2 === 0,
            'Int must be even',
        );

        $result = $schema->parse(4);

        Assert::true($result->ok);
        Assert::same($result->value, 4);
    }

    public function aFailingPredicateReportsTheMessage(): void
    {
        $schema = Schema::int()->refine(
            static fn(int $value): bool => $value % 2 === 0,
            'Int must be even',
        );

        $result = $schema->parse(5);

        Assert::false($result->ok);
        Assert::same($result->errors[0]->message, 'Int must be even');
    }

    public function innerFailuresPassThroughUntouched(): void
    {
        $schema = Schema::int()->refine(
            static fn(int $value): bool => true,
            'never reached',
        );

        $result = $schema->parse('nope');

        Assert::false($result->ok);
        Assert::same($result->errors[0]->message, 'Expected int, got string');
    }

    public function generatedValuesSatisfyTheRefinement(): void
    {
        $schema = Schema::int()->min(0)->max(100)->refine(
            static fn(int $value): bool => $value % 2 === 0,
            'Int must be even',
        );

        $random = new Random(41);

        for ($i = 0; $i < 25; $i++) {
            Assert::true($schema->parse($schema->arbitrary()->generate($random)->value)->ok);
        }
    }
}
