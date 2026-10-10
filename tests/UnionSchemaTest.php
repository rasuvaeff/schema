<?php

declare(strict_types=1);

namespace Rasuvaeff\Schema\Tests;

use Rasuvaeff\PropertyTesting\Random;
use Rasuvaeff\Schema\ParseError;
use Rasuvaeff\Schema\ParseResult;
use Rasuvaeff\Schema\Schema;
use Rasuvaeff\Schema\SchemaInterface;
use Rasuvaeff\Schema\Type\UnionSchema;
use Rasuvaeff\Understudy\Arg;
use Rasuvaeff\Understudy\Understudy;
use Testo\Assert;
use Testo\Assert\ExpectException;
use Testo\Codecov\Covers;
use Testo\Test;

use function Rasuvaeff\Understudy\when;

#[Test]
#[Covers(UnionSchema::class)]
final class UnionSchemaTest
{
    public function firstMatchingMemberWins(): void
    {
        $schema = Schema::union([
            Schema::int()->min(0),
            Schema::string(),
        ]);

        $int = $schema->parse(5);
        $string = $schema->parse('five');

        Assert::true($int->ok);
        Assert::same($int->value, 5);
        Assert::true($string->ok);
        Assert::same($string->value, 'five');
    }

    public function laterMembersAreNotTriedAfterASuccess(): void
    {
        $never = Understudy::for(SchemaInterface::class);
        when(static fn(): ParseResult => $never->parse(Arg::any()))
            ->throws(new \RuntimeException('must not be called'));

        $schema = Schema::union([
            Schema::int(),
            $never,
        ]);

        Assert::true($schema->parse(5)->ok);
    }

    public function noMemberMatchesCarriesEverySummary(): void
    {
        $schema = Schema::union([
            Schema::int(),
            Schema::bool(),
        ]);

        $result = $schema->parse('nope');

        Assert::false($result->ok);
        Assert::same(
            $result->errors[0]->message,
            'Value does not match any of 2 schemas: Expected int, got string; Expected bool, got string',
        );
    }

    public function memberSummariesComeFromTheirOwnFirstErrors(): void
    {
        $first = Understudy::for(SchemaInterface::class);
        when(static fn(): ParseResult => $first->parse(Arg::any()))
            ->returns(ParseResult::failed([new ParseError([], 'first said no')]));
        $second = Understudy::for(SchemaInterface::class);
        when(static fn(): ParseResult => $second->parse(Arg::any()))
            ->returns(ParseResult::failed([new ParseError([], 'second said no')]));

        $schema = Schema::union([$first, $second]);

        $result = $schema->parse('anything');

        Assert::false($result->ok);
        Assert::string($result->errors[0]->message)->contains('first said no');
        Assert::string($result->errors[0]->message)->contains('second said no');
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsAnEmptyMemberList(): void
    {
        Schema::union([]);
    }

    public function generatedValuesAlwaysParse(): void
    {
        $schema = Schema::union([
            Schema::int()->min(-2)->max(2),
            Schema::string()->min(1)->max(4),
        ]);

        $random = new Random(29);

        for ($i = 0; $i < 25; $i++) {
            Assert::true($schema->parse($schema->arbitrary()->generate($random)->value)->ok);
        }
    }
}
