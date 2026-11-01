<?php

declare(strict_types=1);

namespace Rasuvaeff\Schema\Tests;

use Rasuvaeff\Schema\ParseError;
use Rasuvaeff\Schema\UnionBranch;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(ParseError::class)]
final class ParseErrorTest
{
    #[DataProvider('pathProvider')]
    public function rendersPath(array $segments, string $path, string $pointer): void
    {
        $error = new ParseError($segments, 'message');

        Assert::same($error->toPath(), $path);
        Assert::same($error->toJsonPointer(), $pointer);
    }

    public static function pathProvider(): iterable
    {
        yield 'root' => [
            ['name'],
            '$.name',
            '/name',
        ];

        yield 'nested list index' => [
            ['tags', 2, 'name'],
            '$.tags[2].name',
            '/tags/2/name',
        ];

        yield 'top level index' => [
            [0, 'value'],
            '$[0].value',
            '/0/value',
        ];
    }

    public function escapesPointerSpecialCharacters(): void
    {
        $error = new ParseError(['a~b', 'c/d'], 'message');

        Assert::same($error->toJsonPointer(), '/a~0b/c~1d');
    }

    public function withSegmentPrepends(): void
    {
        $error = new ParseError([2, 'name'], 'message');
        $deeper = $error->withSegment('tags');

        Assert::same($deeper->segments, ['tags', 2, 'name']);
        Assert::same($deeper->message, 'message');
        Assert::same($error->segments, [2, 'name']);
    }

    public function withSegmentPropagatesIntoUnionBranches(): void
    {
        $error = new ParseError(['name'], 'Value does not match any of 2 schemas', [
            new UnionBranch(0, new ParseError([], 'Expected string, got int')),
            new UnionBranch(1, new ParseError([], 'Expected int, got array')),
        ]);

        $deeper = $error->withSegment('payload');

        Assert::same($deeper->branches[0]->member, 0);
        Assert::same($deeper->branches[0]->error->toPath(), '$.payload');
        Assert::same($deeper->branches[1]->member, 1);
        Assert::same($deeper->branches[1]->error->toPath(), '$.payload');
        Assert::same($error->branches[0]->error->toPath(), '$');
        Assert::same((string) $deeper->branches[0], '[0] $.payload Expected string, got int');
    }

    public function branchesAreEmptyOutsideUnionFailures(): void
    {
        $error = new ParseError(['name'], 'message');

        Assert::same($error->branches, []);
    }

    public function stringFormIsThePathAndTheMessage(): void
    {
        Assert::same((string) new ParseError(['tags', 2], 'Expected string, got int'), '$.tags[2] Expected string, got int');
        Assert::same((string) new ParseError([], 'boom'), '$ boom');
        Assert::instanceOf(new ParseError([], 'boom'), \Stringable::class);
    }

    public function keysThatAreNotPlainNamesAreQuotedInThePath(): void
    {
        Assert::same((new ParseError(['a.b'], 'x'))->toPath(), "$['a.b']");
        Assert::same((new ParseError(['a', 'b'], 'x'))->toPath(), '$.a.b');
        Assert::same((new ParseError(['user@example.com', 0, 'first name'], 'x'))->toPath(), "$['user@example.com'][0]['first name']");
        Assert::same((new ParseError(["it's", 'back\\slash'], 'x'))->toPath(), "$['it\\'s']['back\\\\slash']");
        Assert::same((new ParseError(['0', '_ok', 'A9', '9a', ''], 'x'))->toPath(), "$['0']._ok.A9['9a']['']");
        Assert::same((new ParseError(["a\n"], 'x'))->toPath(), "$['a\n']");
    }

    public function aQuotedKeyStillRendersAsAPlainPointerSegment(): void
    {
        Assert::same((new ParseError(['a.b', 'c/d'], 'x'))->toJsonPointer(), '/a.b/c~1d');
    }
}
