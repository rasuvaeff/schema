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
 * @implements SchemaInterface<bool>
 *
 * @api
 */
final readonly class BoolSchema implements SchemaInterface
{
    use Combinators;

    public function parse(mixed $input): ParseResult
    {
        if (!\is_bool($input)) {
            return ParseResult::failed([
                new ParseError([], \sprintf('Expected bool, got %s', \get_debug_type($input))),
            ]);
        }

        return ParseResult::ok($input);
    }

    public function parseOrThrow(mixed $input): bool
    {
        $result = $this->parse($input);

        if ($result->ok) {
            /** @var bool $value */
            $value = $result->value;

            return $value;
        }

        throw new SchemaException($result->errors);
    }

    public function arbitrary(): ArbitraryInterface
    {
        return Gen::bool();
    }
}
