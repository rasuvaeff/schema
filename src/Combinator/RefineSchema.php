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
 * A post-condition over the parsed value. Prefer a constraint: `refine`
 * filters the generator, and an over-narrow predicate burns generation
 * budget on discards.
 *
 * @template TValue
 *
 * @implements SchemaInterface<TValue>
 *
 * @api
 */
final readonly class RefineSchema implements SchemaInterface
{
    use Combinators;

    /**
     * @param SchemaInterface<TValue> $inner
     * @param Closure(TValue): bool $predicate
     */
    public function __construct(
        public SchemaInterface $inner,
        public Closure $predicate,
        public string $message,
    ) {}

    public function parse(mixed $input): ParseResult
    {
        $result = $this->inner->parse($input);

        if (!$result->ok) {
            return ParseResult::failed($result->errors);
        }

        $predicate = $this->predicate;

        if ($predicate($result->value)) {
            return $result;
        }

        return ParseResult::failed([
            new ParseError([], $this->message),
        ]);
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
        return Gen::filter($this->inner->arbitrary(), $this->predicate);
    }
}
