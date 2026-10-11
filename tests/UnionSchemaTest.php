<?php

declare(strict_types=1);

namespace Rasuvaeff\Schema\Tests;

use Rasuvaeff\PropertyTesting\Random;
use Rasuvaeff\Schema\ParseError;
use Rasuvaeff\Schema\ParseResult;
use Rasuvaeff\Schema\Schema;
use Rasuvaeff\Schema\SchemaException;
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

    public function noMemberMatchesCarriesEveryBranchError(): void
    {
        $schema = Schema::union([
            Schema::int(),
            Schema::bool(),
        ]);

        $result = $schema->parse('nope');

        Assert::false($result->ok);
        Assert::same($result->errors[0]->message, 'Value does not match any of 2 schemas');
        Assert::same(\count($result->errors[0]->branches), 2);
        Assert::same($result->errors[0]->branches[0]->member, 0);
        Assert::same($result->errors[0]->branches[0]->error->message, 'Expected int, got string');
        Assert::same($result->errors[0]->branches[1]->member, 1);
        Assert::same($result->errors[0]->branches[1]->error->message, 'Expected bool, got string');
    }

    public function everyErrorOfEveryMemberSurvivesWithItsPath(): void
    {
        $schema = Schema::union([
            Schema::object([
                'name' => Schema::string(),
                'age' => Schema::int(),
            ]),
            Schema::object([
                'id' => Schema::int(),
            ]),
        ]);

        $result = $schema->parse(['name' => 42, 'age' => 'no']);

        Assert::false($result->ok);

        $branches = $result->errors[0]->branches;

        // member 0 reports its two property errors; member 1 reports the
        // unknown keys and the missing required one — nothing is flattened.
        Assert::same(\count($branches), 5);
        Assert::same($branches[0]->member, 0);
        Assert::same($branches[0]->error->toPath(), '$.name');
        Assert::same($branches[0]->error->message, 'Expected string, got int');
        Assert::same($branches[1]->member, 0);
        Assert::same($branches[1]->error->toPath(), '$.age');
        Assert::same($branches[2]->member, 1);
        Assert::same($branches[2]->error->toPath(), '$.id');
        Assert::same($branches[2]->error->message, 'Required property "id" is missing');
        Assert::same($branches[3]->member, 1);
        Assert::same($branches[3]->error->toPath(), '$.name');
        Assert::same($branches[3]->error->message, 'Unknown property "name"');
        Assert::same($branches[4]->member, 1);
        Assert::same($branches[4]->error->toPath(), '$.age');
    }

    public function aUnionInsideAnObjectKeepsBranchPathsComplete(): void
    {
        $schema = Schema::object([
            'payload' => Schema::union([
                Schema::object(['name' => Schema::string()]),
                Schema::int(),
            ]),
        ]);

        $result = $schema->parse(['payload' => ['name' => 42]]);

        Assert::false($result->ok);
        Assert::same($result->errors[0]->toPath(), '$.payload');
        Assert::same($result->errors[0]->message, 'Value does not match any of 2 schemas');

        $branches = $result->errors[0]->branches;

        Assert::same($branches[0]->error->toPath(), '$.payload.name');
        Assert::same($branches[1]->error->toPath(), '$.payload');
        Assert::same($branches[1]->error->message, 'Expected int, got array');
    }

    public function exceptionRendersBranchesIndented(): void
    {
        $schema = Schema::object([
            'payload' => Schema::union([
                Schema::object(['name' => Schema::string()]),
                Schema::int(),
            ]),
        ]);

        try {
            $schema->parseOrThrow(['payload' => ['name' => 42]]);
        } catch (SchemaException $invalid) {
            Assert::same(
                $invalid->getMessage(),
                <<<'MESSAGE'
                    $.payload Value does not match any of 2 schemas
                      [0] $.payload.name Expected string, got int
                      [1] $.payload Expected int, got array
                    MESSAGE,
            );

            return;
        }

        throw new \LogicException('parseOrThrow was expected to fail');
    }

    public function memberBranchesComeFromTheirOwnErrors(): void
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
        Assert::same($result->errors[0]->branches[0]->error->message, 'first said no');
        Assert::same($result->errors[0]->branches[1]->error->message, 'second said no');
        Assert::same(
            (string) $result->errors[0]->branches[1],
            '[1] $ second said no',
        );
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
