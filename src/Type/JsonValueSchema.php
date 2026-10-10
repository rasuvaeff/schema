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
 * Any JSON value: null, a finite scalar, a sequential list or a
 * string-keyed map of such values — exactly what `json_decode` can
 * produce. NAN, INF, resources and objects are rejected.
 *
 * @extends SchemaType<mixed>
 *
 * @api
 */
final class JsonValueSchema extends SchemaType
{
    #[\Override]
    public function parse(mixed $input): ParseResult
    {
        if (!$this->isJson($input)) {
            return ParseResult::failed([
                new ParseError([], \sprintf('Expected a JSON value, got %s', \get_debug_type($input))),
            ]);
        }

        return ParseResult::ok($input);
    }

    #[\Override]
    public function parseOrThrow(mixed $input): mixed
    {
        $result = $this->parse($input);

        if ($result->ok) {
            return $result->value;
        }

        throw new SchemaException($result->errors);
    }

    #[\Override]
    public function arbitrary(): ArbitraryInterface
    {
        return Gen::json();
    }

    private function isJson(mixed $value): bool
    {
        if ($value === null || \is_int($value) || \is_string($value) || \is_bool($value)) {
            return true;
        }

        if (\is_float($value)) {
            return \is_finite($value);
        }

        if (!\is_array($value)) {
            return false;
        }

        if (!\array_is_list($value)) {
            foreach (\array_keys($value) as $key) {
                if (!\is_string($key)) {
                    return false;
                }
            }
        }

        foreach (\array_keys($value) as $key) {
            if (!$this->isJson($value[$key])) {
                return false;
            }
        }

        return true;
    }

    #[\Override]
    public function toJsonSchema(bool $lenient = false): JsonSchemaExport
    {
        return JsonSchemaExport::of([]);
    }
}
