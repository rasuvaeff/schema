<?php

declare(strict_types=1);

namespace Rasuvaeff\Schema\Tests;

use Rasuvaeff\PropertyTesting\Random;
use Rasuvaeff\Schema\Schema;
use Rasuvaeff\Schema\Tests\Support\Role;
use Rasuvaeff\Schema\Type\ObjectProperty;
use Testo\Assert;
use Testo\Assert\ExpectException;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(ObjectProperty::class)]
final class ObjectPropertyTest
{
    public function defaultIsValidatedAndNormalized(): void
    {
        $property = Schema::enum(Role::class)->default('admin');

        Assert::true($property->hasDefault);
        Assert::true($property->optional);
        Assert::same($property->default, Role::Admin);
    }

    public function optionalIsIdempotent(): void
    {
        $property = Schema::string()->optional();

        Assert::same($property->optional(), $property);
    }

    public function defaultImpliesOptional(): void
    {
        $property = Schema::string()->default('x');

        Assert::true($property->optional);
        Assert::same($property->default, 'x');
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function anInvalidDefaultIsAnAuthoringError(): void
    {
        Schema::int()->min(10)->default(1);
    }

    public function delegatesParsingToTheInnerSchema(): void
    {
        $property = Schema::string()->min(2)->optional();

        Assert::true($property->parse('ab')->ok);
        Assert::same($property->parseOrThrow('ab'), 'ab');
        Assert::true($property->parse('x')->ok === false);
        Assert::true($property->arbitrary()->generate(new Random(2)) instanceof \Rasuvaeff\PropertyTesting\Shrinkable);
    }
}
