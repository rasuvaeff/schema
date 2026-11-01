<?php

declare(strict_types=1);

namespace Rasuvaeff\Schema\Tests;

use Rasuvaeff\Schema\ParseError;
use Rasuvaeff\Schema\ParseResult;
use Rasuvaeff\Schema\Schema;
use Rasuvaeff\Schema\SchemaException;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(ParseResult::class)]
final class ParseResultTest
{
    public function orThrowReturnsTheValueOfASuccess(): void
    {
        Assert::same(ParseResult::ok(['a' => 1])->orThrow(), ['a' => 1]);
        Assert::null(ParseResult::ok(null)->orThrow());
    }

    public function orThrowThrowsEveryErrorOfAFailure(): void
    {
        $errors = [new ParseError(['a'], 'first'), new ParseError(['b'], 'second')];

        try {
            ParseResult::failed($errors)->orThrow();
        } catch (SchemaException $exception) {
            Assert::same($exception->errors, $errors);
            Assert::same($exception->getMessage(), "$.a first\n$.b second");

            return;
        }

        throw new \LogicException('orThrow() did not throw');
    }

    public function summaryOfASuccessIsEmpty(): void
    {
        Assert::same(ParseResult::ok(1)->summary(), '');
    }

    public function summaryJoinsTheFirstErrors(): void
    {
        $result = ParseResult::failed([
            new ParseError(['a'], 'one'),
            new ParseError(['b', 0], 'two'),
        ]);

        Assert::same($result->summary(), '$.a one; $.b[0] two');
        Assert::same($result->summary(separator: "\n"), "$.a one\n$.b[0] two");
    }

    public function summaryCountsTheCutErrors(): void
    {
        $result = ParseResult::failed([
            new ParseError(['a'], 'one'),
            new ParseError(['b'], 'two'),
            new ParseError(['c'], 'three'),
            new ParseError(['d'], 'four'),
            new ParseError(['e'], 'five'),
        ]);

        Assert::same($result->summary(), '$.a one; $.b two; $.c three; (+2 more)');
        Assert::same($result->summary(limit: 1), '$.a one; (+4 more)');
        Assert::same($result->summary(limit: 5), '$.a one; $.b two; $.c three; $.d four; $.e five');
        Assert::same(ParseResult::failed([new ParseError([], 'x'), new ParseError([], 'y'), new ParseError([], 'z')])->summary(), '$ x; $ y; $ z');
    }

    public function summaryOfARealParseNeverEchoesTheInput(): void
    {
        $summary = Schema::object(['token' => Schema::int()])->parse(['token' => 'secret-value'])->summary();

        Assert::same($summary, '$.token Expected int, got string');
    }

    public function valueOrIsTheValueOfASuccessAndTheDefaultOfAFailure(): void
    {
        Assert::same(ParseResult::ok(['a' => 1])->valueOr(null), ['a' => 1]);
        Assert::same(ParseResult::ok(0)->valueOr(5), 0);
        Assert::null(ParseResult::ok(null)->valueOr('default'));
        Assert::same(ParseResult::failed([new ParseError([], 'x')])->valueOr('default'), 'default');
    }
}
