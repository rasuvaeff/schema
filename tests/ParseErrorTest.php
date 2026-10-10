<?php

declare(strict_types=1);

namespace Rasuvaeff\Schema\Tests;

use Rasuvaeff\Schema\ParseError;
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
}
