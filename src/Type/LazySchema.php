<?php

declare(strict_types=1);

namespace Rasuvaeff\Schema\Type;

use Closure;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
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
        return $this->resolve()->arbitrary();
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
