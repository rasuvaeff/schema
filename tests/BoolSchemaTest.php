<?php

declare(strict_types=1);

namespace Rasuvaeff\Schema\Tests;

use Rasuvaeff\PropertyTesting\Random;
use Rasuvaeff\Schema\Schema;
use Rasuvaeff\Schema\Type\BoolSchema;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(BoolSchema::class)]
final class BoolSchemaTest
{
    public function acceptsBothBooleans(): void
    {
        Assert::true(Schema::bool()->parse(true)->ok);
        Assert::same(Schema::bool()->parseOrThrow(false), false);
    }

    public function rejectsAnIntegerEvenThoughPhpIsLenient(): void
    {
        $result = Schema::bool()->parse(1);

        Assert::false($result->ok);
        Assert::same($result->errors[0]->message, 'Expected bool, got int');
    }

    public function generatedValuesAlwaysParse(): void
    {
        Assert::true(Schema::bool()->parse(Schema::bool()->arbitrary()->generate(new Random(1))->value)->ok);
    }
}
