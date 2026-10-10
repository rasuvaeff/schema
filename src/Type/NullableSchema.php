<?php

declare(strict_types=1);

namespace Rasuvaeff\Schema\Type;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\Schema\Combinators;
use Rasuvaeff\Schema\ParseResult;
use Rasuvaeff\Schema\SchemaException;
use Rasuvaeff\Schema\SchemaInterface;

/**
 * The inner schema or null. Nullable is about the value; an optional key
 * is about the property — different levels, both exist.
 *
 * @template T
 *
 * @implements SchemaInterface<T|null>
 *
 * @api
 */
final readonly class NullableSchema implements SchemaInterface
{
    use Combinators;

    /**
     * @param SchemaInterface<T> $inner
     */
    public function __construct(
        public SchemaInterface $inner,
    ) {}

    public function parse(mixed $input): ParseResult
    {
        if ($input === null) {
            return ParseResult::ok(null);
        }

        return $this->inner->parse($input);
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
        return Gen::nullable($this->inner->arbitrary());
    }
}
