<?php

declare(strict_types=1);

namespace Rasuvaeff\Schema\Type;

use Closure;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\GenerationExhaustedException;
use Rasuvaeff\PropertyTesting\Random;
use Rasuvaeff\PropertyTesting\Shrinkable;
use Rasuvaeff\Schema\Internal\DeferredArbitrary;
use Rasuvaeff\Schema\Internal\RecursionDepth;
use Rasuvaeff\Schema\JsonSchemaExport;
use Rasuvaeff\Schema\ParseResult;
use Rasuvaeff\Schema\SchemaInterface;
use Rasuvaeff\Schema\SchemaType;

/**
 * A deferred schema for recursive definitions. The factory runs once, on
 * first use, and its result is memoized. Self-reference needs a by-value
 * capture of a variable that does not exist yet — use a closure with an
 * explicit by-reference capture:
 *
 * ```php
 * $node = null;
 * $node = Schema::lazy(
 *     static function () use (&$node): ObjectSchema {
 *         return Schema::object([
 *             'name' => Schema::string(),
 *             'children' => Schema::arrayOf($node),
 *         ]);
 *     },
 * );
 * ```
 *
 * @extends SchemaType<mixed>
 *
 * @api
 */
final class LazySchema extends SchemaType
{
    private ?SchemaInterface $resolved = null;

    /**
     * @param Closure(): SchemaInterface $factory
     */
    public function __construct(
        private readonly Closure $factory,
    ) {}

    #[\Override]
    public function parse(mixed $input): ParseResult
    {
        return $this->resolve()->parse($input);
    }

    #[\Override]
    public function parseOrThrow(mixed $input): mixed
    {
        return $this->resolve()->parseOrThrow($input);
    }

    #[\Override]
    public function arbitrary(): ArbitraryInterface
    {
        // Resolving here would recurse: building the inner schema's generator
        // meets this lazy schema again while the tree is still under
        // construction. The inner generator is built per generate() call,
        // nested only as deep as the random draw actually goes, and bounded
        // by the recursion budget.
        $lazy = $this;

        return new DeferredArbitrary(
            static fn(Random $random): Shrinkable => $lazy->generateBounded($random),
        );
    }

    /**
     * @internal the recursion budget seam of {@see arbitrary()}
     */
    public function generateBounded(Random $random): Shrinkable
    {
        if (RecursionDepth::remaining() === 0) {
            throw new GenerationExhaustedException(
                'Schema::lazy()',
                RecursionDepth::BUDGET,
                'recursive generation exceeded the depth budget of '
                    . RecursionDepth::BUDGET
                    . ' levels — the recursion has no finite value: every path '
                    . 'through it is mandatory (no optional key, no empty '
                    . 'container, no non-recursive branch reaches a leaf). '
                    . 'Containers inside the recursion already shrink with the '
                    . 'remaining budget, so an ordinary tree does not get here',
            );
        }

        RecursionDepth::enter();

        try {
            return $this->resolve()->arbitrary()->generate($random);
        } finally {
            RecursionDepth::exit();
        }
    }

    #[\Override]
    public function toJsonSchema(bool $lenient = false): JsonSchemaExport
    {
        return $this->resolve()->toJsonSchema($lenient);
    }

    /**
     * @internal the memoization seam; the factory runs exactly once
     */
    public function resolve(): SchemaInterface
    {
        if (!$this->resolved instanceof \Rasuvaeff\Schema\SchemaInterface) {
            $factory = $this->factory;
            $this->resolved = $factory();
        }

        return $this->resolved;
    }
}
