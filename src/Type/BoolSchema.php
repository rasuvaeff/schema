<?php

declare(strict_types=1);

namespace Rasuvaeff\Schema\Type;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\Schema\JsonSchemaExport;
use Rasuvaeff\Schema\ParseError;
use Rasuvaeff\Schema\ParseResult;
use Rasuvaeff\Schema\SchemaException;
use Rasuvaeff\Schema\SchemaType;

/**
 * @extends SchemaType<bool>
 *
 * @api
 */
final class BoolSchema extends SchemaType
{
    #[\Override]
    public function parse(mixed $input): ParseResult
    {
        if (!\is_bool($input)) {
            return ParseResult::failed([
                new ParseError([], \sprintf('Expected bool, got %s', \get_debug_type($input))),
            ]);
        }

        return ParseResult::ok($input);
    }

    #[\Override]
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

    #[\Override]
    public function arbitrary(): ArbitraryInterface
    {
        return Gen::bool();
    }

    #[\Override]
    public function toJsonSchema(bool $lenient = false): JsonSchemaExport
    {
        return JsonSchemaExport::of(['type' => 'boolean']);
    }
}
