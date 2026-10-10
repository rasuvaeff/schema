<?php

declare(strict_types=1);

namespace Rasuvaeff\Schema\Tests;

use Rasuvaeff\PropertyTesting\Random;
use Rasuvaeff\Schema\Schema;
use Rasuvaeff\Schema\Type\LazySchema;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(LazySchema::class)]
final class LazySchemaTest
{
    public function resolvesThroughTheFactory(): void
    {
        $lazy = Schema::lazy(static fn(): \Rasuvaeff\Schema\Type\StringSchema => Schema::string()->min(2));

        Assert::true($lazy->parse('ab')->ok);
        Assert::false($lazy->parse('a')->ok);
        Assert::same($lazy->parseOrThrow('ab'), 'ab');
    }

    public function theFactoryRunsOnce(): void
    {
        $calls = 0;

        $lazy = Schema::lazy(static function () use (&$calls): \Rasuvaeff\Schema\Type\IntSchema {
            $calls++;

            return Schema::int();
        });

        $lazy->parse(1);
        $lazy->parse(2);
        $lazy->arbitrary()->generate(new Random(4));

        Assert::same($calls, 1);
    }

    public function supportsRecursiveShapes(): void
    {
        $node = null;

        $node = Schema::lazy(
            static function () use (&$node): \Rasuvaeff\Schema\Type\ObjectSchema {
                return Schema::object([
                    'name' => Schema::string()->min(1),
                    'children' => Schema::arrayOf(Schema::union([Schema::string()->min(1), $node]))->maxCount(2),
                ]);
            },
        );

        $result = $node->parse([
            'name' => 'root',
            'children' => [
                'leaf',
                ['name' => 'inner', 'children' => []],
            ],
        ]);

        Assert::true($result->ok);
        Assert::true($node->parse(['name' => 'root', 'children' => [['name' => 'bad']]])->ok === false);
    }
}
