<?php

declare(strict_types=1);

namespace Rasuvaeff\Schema\Tests;

use Rasuvaeff\PropertyTesting\GenerationExhaustedException;
use Rasuvaeff\PropertyTesting\Random;
use Rasuvaeff\Schema\Internal\DeferredArbitrary;
use Rasuvaeff\Schema\Internal\RecursionDepth;
use Rasuvaeff\Schema\Schema;
use Rasuvaeff\Schema\Type\LazySchema;
use Testo\Assert;
use Testo\Assert\ExpectException;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(LazySchema::class)]
#[Covers(DeferredArbitrary::class)]
#[Covers(RecursionDepth::class)]
#[Covers(Schema::class)]
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

    public function arbitraryOfARecursiveSchemaTerminates(): void
    {
        $node = null;

        $node = Schema::lazy(
            static function () use (&$node): \Rasuvaeff\Schema\Type\ObjectSchema {
                return Schema::object([
                    'name' => Schema::string()->min(1)->max(5),
                    'children' => Schema::arrayOf($node)->maxCount(2)->optional(),
                ]);
            },
        );

        $arbitrary = $node->arbitrary();

        for ($i = 0; $i < 20; $i++) {
            $value = $arbitrary->generate(new Random($i))->value;

            Assert::true($node->parse($value)->ok);
        }
    }

    /**
     * The exact repro of the supercritical-branching report: an uncapped
     * array of nodes (up to 20 children each) has an expected recursive
     * child count above 1, so before containers shrank with the remaining
     * budget most seeds hit the depth limit. Every seed must generate now.
     */
    public function arbitraryOfAUncappedRecursiveTreeGeneratesOnEverySeed(): void
    {
        $node = null;

        $node = Schema::object([
            'name' => Schema::string()->min(1)->max(5),
            'children' => Schema::arrayOf(Schema::lazy(
                static function () use (&$node): \Rasuvaeff\Schema\SchemaInterface {
                    return $node;
                },
            ))->optional(),
        ]);

        $arbitrary = $node->arbitrary();

        for ($seed = 1; $seed <= 50; $seed++) {
            $value = $arbitrary->generate(new Random($seed))->value;

            Assert::true($node->parse($value)->ok);
        }
    }

    public function mapOfRecursiveValuesStaysSubcritical(): void
    {
        $node = null;

        $node = Schema::lazy(
            static function () use (&$node): \Rasuvaeff\Schema\Type\ObjectSchema {
                return Schema::object([
                    'name' => Schema::string()->min(1)->max(5),
                    'children' => Schema::mapOf($node)->optional(),
                ]);
            },
        );

        $arbitrary = $node->arbitrary();

        for ($seed = 1; $seed <= 50; $seed++) {
            $value = $arbitrary->generate(new Random($seed))->value;

            Assert::true($node->parse($value)->ok);
        }
    }

    #[ExpectException(GenerationExhaustedException::class)]
    public function arbitraryExhaustsWhenTheRecursionHasNoFiniteValue(): void
    {
        $node = null;

        $node = Schema::lazy(
            static function () use (&$node): \Rasuvaeff\Schema\Type\ObjectSchema {
                return Schema::object([
                    'name' => Schema::string()->min(1),
                    'child' => $node,
                ]);
            },
        );

        $node->arbitrary()->generate(new Random(1));
    }

    public function generatedRecursiveValuesShrink(): void
    {
        $node = null;

        $node = Schema::lazy(
            static function () use (&$node): \Rasuvaeff\Schema\Type\ObjectSchema {
                return Schema::object([
                    'name' => Schema::string()->min(1)->max(5),
                    'children' => Schema::arrayOf($node)->maxCount(2)->optional(),
                ]);
            },
        );

        $shrinkable = $node->arbitrary()->generate(new Random(7));

        foreach ($shrinkable->shrinks() as $candidate) {
            Assert::true($node->parse($candidate->value)->ok);
        }
    }
}
