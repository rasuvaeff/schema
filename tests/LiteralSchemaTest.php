<?php

declare(strict_types=1);

namespace Rasuvaeff\Schema\Tests;

use Rasuvaeff\PropertyTesting\Random;
use Rasuvaeff\Schema\Schema;
use Rasuvaeff\Schema\Type\LiteralSchema;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(LiteralSchema::class)]
final class LiteralSchemaTest
{
    public function acceptsTheExactValue(): void
    {
        $schema = Schema::literal('pending');

        Assert::true($schema->parse('pending')->ok);
        Assert::same($schema->parseOrThrow('pending'), 'pending');
    }

    public function rejectsADifferentValueOfTheSameType(): void
    {
        $result = Schema::literal('pending')->parse('done');

        Assert::false($result->ok);
        Assert::same($result->errors[0]->message, 'Expected the exact string value');
    }

    public function comparisonIsStrict(): void
    {
        Assert::false(Schema::literal('1')->parse(1)->ok);
        Assert::true(Schema::literal(0)->parse(0)->ok);
        Assert::false(Schema::literal(null)->parse(input: false)->ok);
    }

    public function generatedValueIsAlwaysTheLiteral(): void
    {
        $schema = Schema::literal('pending');

        Assert::same($schema->arbitrary()->generate(new Random(1))->value, 'pending');
    }
}
