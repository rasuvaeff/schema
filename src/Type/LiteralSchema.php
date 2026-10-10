<?php

declare(strict_types=1);

namespace Rasuvaeff\Schema\Type;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\Schema\ParseError;
use Rasuvaeff\Schema\ParseResult;
use Rasuvaeff\Schema\SchemaException;
use Rasuvaeff\Schema\SchemaType;

/**
 * Exactly one constant — compared strictly. The literal is part of the
 * schema, not of the input, so error messages may name it.
 *
 * @template TValue of string|int|float|bool|null
 *
 * @extends SchemaType<TValue>
 *
 * @api
 */
final class LiteralSchema extends SchemaType
{
    /**
     * @param TValue $value
     */
    public function __construct(
        public readonly string|int|float|bool|null $value,
    ) {}

    #[\Override]
    public function parse(mixed $input): ParseResult
    {
        if ($input !== $this->value) {
            return ParseResult::failed([
                new ParseError([], \sprintf('Expected the exact %s value', \get_debug_type($this->value))),
            ]);
        }

        return ParseResult::ok($this->value);
    }

    #[\Override]
    public function parseOrThrow(mixed $input): string|int|float|bool|null
    {
        $result = $this->parse($input);

        if ($result->ok) {
            return $this->value;
        }

        throw new SchemaException($result->errors);
    }

    #[\Override]
    public function arbitrary(): ArbitraryInterface
    {
        return Gen::constant($this->value);
    }
}
