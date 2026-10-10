<?php

declare(strict_types=1);

namespace Rasuvaeff\Schema\Combinator;

use Closure;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\Schema\ParseError;
use Rasuvaeff\Schema\ParseResult;
use Rasuvaeff\Schema\SchemaException;
use Rasuvaeff\Schema\SchemaInterface;
use Rasuvaeff\Schema\SchemaType;

/**
 * A post-condition over the parsed value. Prefer a constraint: `refine`
 * filters the generator, and an over-narrow predicate burns generation
 * budget on discards.
 *
 * @template TValue
 *
 * @extends SchemaType<TValue>
 *
 * @api
 */
final class RefineSchema extends SchemaType
{
    /**
     * @param SchemaInterface<TValue> $inner
     * @param Closure(TValue): bool $predicate
     */
    public function __construct(
        public readonly SchemaInterface $inner,
        public readonly Closure $predicate,
        public readonly string $message,
    ) {}

    #[\Override]
    public function parse(mixed $input): ParseResult
    {
        $result = $this->inner->parse($input);

        if (!$result->ok) {
            return ParseResult::failed($result->errors);
        }

        /** @var TValue $value */
        $value = $result->value;

        if (($this->predicate)($value)) {
            return $result;
        }

        return ParseResult::failed([
            new ParseError([], $this->message),
        ]);
    }

    #[\Override]
    public function parseOrThrow(mixed $input): mixed
    {
        $result = $this->parse($input);

        if ($result->ok) {
            /** @var TValue $value */
            $value = $result->value;

            return $value;
        }

        throw new SchemaException($result->errors);
    }

    #[\Override]
    public function arbitrary(): ArbitraryInterface
    {
        return Gen::filter($this->inner->arbitrary(), $this->predicate);
    }
}
