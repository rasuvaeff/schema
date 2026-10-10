<?php

declare(strict_types=1);

namespace Rasuvaeff\Schema\Type;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\Schema\Combinators;
use Rasuvaeff\Schema\ParseError;
use Rasuvaeff\Schema\ParseResult;
use Rasuvaeff\Schema\SchemaException;
use Rasuvaeff\Schema\SchemaInterface;

/**
 * Exactly one constant — compared strictly. The literal is part of the
 * schema, not of the input, so error messages may name it.
 *
 * @template TValue of string|int|float|bool|null
 *
 * @implements SchemaInterface<TValue>
 *
 * @api
 */
final readonly class LiteralSchema implements SchemaInterface
{
    use Combinators;

    /**
     * @param TValue $value
     */
    public function __construct(
        public string|int|float|bool|null $value,
    ) {}

    public function parse(mixed $input): ParseResult
    {
        if ($input !== $this->value) {
            return ParseResult::failed([
                new ParseError([], \sprintf('Expected the exact %s value', \get_debug_type($this->value))),
            ]);
        }

        return ParseResult::ok($input);
    }

    public function parseOrThrow(mixed $input): string|int|float|bool|null
    {
        $result = $this->parse($input);

        if ($result->ok) {
            return $this->value;
        }

        throw new SchemaException($result->errors);
    }

    public function arbitrary(): ArbitraryInterface
    {
        return Gen::constant($this->value);
    }
}
