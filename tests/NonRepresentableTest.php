<?php

declare(strict_types=1);

namespace Rasuvaeff\Schema\Tests;

use Rasuvaeff\Schema\NonRepresentable;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(NonRepresentable::class)]
final class NonRepresentableTest
{
    /**
     * @param list<int|string> $segments
     */
    #[DataProvider('pathProvider')]
    public function rendersTheSegmentsAsAPath(array $segments, string $expected): void
    {
        Assert::same((new NonRepresentable($segments, 'refine'))->toPath(), $expected);
    }

    public static function pathProvider(): iterable
    {
        yield 'root' => [[], '$'];
        yield 'key' => [['name'], '$.name'];
        yield 'index' => [[0], '$[0]'];
        yield 'mixed' => [['items', 2, 'name'], '$.items[2].name'];
        yield 'consecutive indexes' => [[1, 2], '$[1][2]'];
    }

    public function withSegmentPrependsTheSegmentAndKeepsTheRest(): void
    {
        $entry = new NonRepresentable(['b', 1], 'transform');

        $moved = $entry->withSegment('a');

        Assert::same($moved->segments, ['a', 'b', 1]);
        Assert::same($moved->construct, 'transform');
        Assert::same($entry->segments, ['b', 1]);
        Assert::same($entry->withSegment(0)->segments, [0, 'b', 1]);
    }

    public function stringifiesAsTheConstructAtItsPath(): void
    {
        Assert::same((string) new NonRepresentable(['a', 0], 'refine'), 'refine @ $.a[0]');
        Assert::same((string) new NonRepresentable([], 'transform'), 'transform @ $');
    }
}
