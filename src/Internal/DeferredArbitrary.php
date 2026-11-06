<?php

declare(strict_types=1);

namespace Rasuvaeff\Schema\Internal;

use Closure;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Random;
use Rasuvaeff\PropertyTesting\Shrinkable;

/**
 * An arbitrary that defers building the real generator to generate() time.
 * This is the seam that makes recursive {@see \Rasuvaeff\Schema\Type\LazySchema}
 * trees finite: resolving the inner schema eagerly would re-enter the same
 * lazy schema while the generator tree is still being built and never
 * terminate.
 *
 * @implements ArbitraryInterface<mixed>
 *
 * @internal
 */
final class DeferredArbitrary implements ArbitraryInterface
{
    /**
     * @param Closure(Random): Shrinkable<mixed> $generate
     */
    public function __construct(
        private readonly Closure $generate,
    ) {}

    #[\Override]
    public function generate(Random $random): Shrinkable
    {
        return ($this->generate)($random);
    }
}
