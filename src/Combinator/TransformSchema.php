<?php

declare(strict_types=1);

namespace Rasuvaeff\Schema\Combinator;

use Closure;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\Schema\Combinators;
use Rasuvaeff\Schema\ParseError;
use Rasuvaeff\Schema\ParseResult;
use Rasuvaeff\Schema\SchemaException;
use Rasuvaeff\Schema\SchemaInterface;

/**
 * Validate against the source schema, then map the parsed value through
 * the callback.
 *
 * Domains, pinned: `parse` accepts the source space; `arbitrary`
 * produces the **target** space (`Gen::map` over the source generator);
 * shrinking happens in the source space and the callback is re-applied,
 * so a shrunk counterexample is still a valid target value.
 *
 * @template TSource
 * @template TTarget
 *
 * @implements SchemaInterface<TTarget>
 *
 * @api
 */
final readonly class TransformSchema implements SchemaInterface
{
    use Combinators;

    /**
     * @param SchemaInterface<TSource> $source
     * @param Closure(TSource): TTarget $transform
     * @param Closure(TTarget): TSource|null $from optional inverse — used
     *     by round-trip laws only, never by parse or generation
     */
    public function __construct(
        public SchemaInterface $source,
        public Closure $transform,
        public ?Closure $from = null,
    ) {}

    public function parse(mixed $input): ParseResult
    {
        $result = $this->source->parse($input);

        if (!$result->ok) {
            return ParseResult::failed($result->errors);
        }

        $transform = $this->transform;

        try {
            return ParseResult::ok($transform($result->value));
        } catch (\Throwable $exception) {
            return ParseResult::failed([
                // The callback's own message may embed values; the class
                // name is the most an error ever carries.
                new ParseError([], \sprintf('Transform callback failed (%s)', $exception::class)),
            ]);
        }
    }

    public function parseOrThrow(mixed $input): mixed
    {
        $result = $this->parse($input);

        if ($result->ok) {
            return $result->value;
        }

        throw new SchemaException($result->errors);
    }

    public function arbitrary(): ArbitraryInterface
    {
        return Gen::map($this->source->arbitrary(), $this->transform);
    }
}
