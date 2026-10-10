<?php

declare(strict_types=1);

namespace Rasuvaeff\Schema\Tests;

use Rasuvaeff\PropertyTesting\Random;
use Rasuvaeff\Schema\Schema;
use Rasuvaeff\Schema\Type\NullableSchema;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(NullableSchema::class)]
final class NullableSchemaTest
{
    public function acceptsNull(): void
    {
        $result = Schema::nullable(Schema::int())->parse(null);

        Assert::true($result->ok);
        Assert::null($result->value);
    }

    public function delegatesNonNullValues(): void
    {
        Assert::same(Schema::nullable(Schema::int())->parseOrThrow(5), 5);
        Assert::false(Schema::nullable(Schema::int())->parse('5')->ok);
    }

    public function generatedValuesAlwaysParse(): void
    {
        $schema = Schema::nullable(Schema::int()->min(-2)->max(2));

        $random = new Random(31);

        for ($i = 0; $i < 25; $i++) {
            Assert::true($schema->parse($schema->arbitrary()->generate($random)->value)->ok);
        }
    }
}
