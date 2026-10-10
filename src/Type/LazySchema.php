<?php

declare(strict_types=1);

namespace Rasuvaeff\Schema\Type;

use Closure;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\Schema\Combinators;
use Rasuvaeff\Schema\ParseResult;
use Rasuvaeff\Schema\SchemaException;
use Rasuvaeff\Schema\SchemaInterface;

/**
 * A deferred schema for recursive definitions. The factory runs once, on
 * first use, and its result is memoized.
 *
 * ```php
 * $node = Schema::lazy(fn (): ObjectSchema => Schema::object([
 *     'name' => Schema::string(),
 *     'children' => Schema::arrayOf($node ?? Schema::string()),
 * ]));
 * ```
 *
 * @implements SchemaInterface<mixed>
 *
 * @api
 */
final class LazySchema implements SchemaInterface
{
    use Combinators;

    private ?SchemaInterface $resolved = null;

    /**
     * @param Closure(): SchemaInterface $factory
     */
    public function __construct(
        private readonly Closure $factory,
    ) {}

    public function parse(mixed $input): ParseResult
    {
        return $this->resolve()->parse($input);
    }

    public function parseOrThrow(mixed $input): mixed
    {
        return $this->resolve()->parseOrThrow($input);
    }

    public function arbitrary(): ArbitraryInterface
    {
        return $this->resolve()->arbitrary();
    }

    private function resolve(): SchemaInterface
    {
        if ($this->resolved === null) {
            $factory = $this->factory;
            $this->resolved = $factory();
        }

        return $this->resolved;
    }
}
