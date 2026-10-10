<?php

declare(strict_types=1);

namespace Rasuvaeff\Schema\Tests;

use Rasuvaeff\PropertyTesting\Random;
use Rasuvaeff\Schema\Schema;
use Rasuvaeff\Schema\Tests\Support\PureEnum;
use Rasuvaeff\Schema\Tests\Support\Role;
use Rasuvaeff\Schema\Tests\Support\Suit;
use Rasuvaeff\Schema\Type\EnumSchema;
use Testo\Assert;
use Testo\Assert\ExpectException;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(EnumSchema::class)]
final class EnumSchemaTest
{
    public function acceptsAnInstance(): void
    {
        $result = Schema::enum(Role::class)->parse(Role::Editor);

        Assert::true($result->ok);
        Assert::same($result->value, Role::Editor);
    }

    public function acceptsABackedValueAndReturnsTheInstance(): void
    {
        Assert::same(Schema::enum(Role::class)->parseOrThrow('admin'), Role::Admin);
        Assert::same(Schema::enum(Suit::class)->parseOrThrow(2), Suit::Spades);
    }

    public function rejectsAnUnknownValueWithTheAllowedList(): void
    {
        $result = Schema::enum(Role::class)->parse('nobody');

        Assert::false($result->ok);
        Assert::same($result->errors[0]->message, 'Expected one of: \'admin\', \'editor\', \'viewer\'');
    }

    public function rejectsAnInputTypeTheEnumDoesNotUse(): void
    {
        Assert::false(Schema::enum(Role::class)->parse(1)->ok);
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsANonEnumClass(): void
    {
        Schema::enum(\stdClass::class);
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsAUnitEnum(): void
    {
        Schema::enum(PureEnum::class);
    }

    public function generatedInstancesAlwaysParse(): void
    {
        $schema = Schema::enum(Role::class);

        $random = new Random(5);

        for ($i = 0; $i < 25; $i++) {
            Assert::true($schema->parse($schema->arbitrary()->generate($random)->value)->ok);
        }
    }
}
