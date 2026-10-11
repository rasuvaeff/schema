<?php

declare(strict_types=1);

namespace Rasuvaeff\Schema\Type;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\Schema\JsonSchemaExport;
use Rasuvaeff\Schema\NonRepresentable;
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
    public function __construct(
        public readonly bool $coerce = false,
    ) {}

    /**
     * Accept the four unambiguous boolean strings — `"true"`, `"false"`,
     * `"1"`, `"0"` (case-sensitive) — and normalize them to bool. The
     * generator's domain stays bool; the export keeps `type: boolean`
     * with a `NonRepresentable` `coerce` entry.
     */
    public function coerce(): self
    {
        return new self(coerce: true);
    }

    #[\Override]
    public function parse(mixed $input): ParseResult
    {
        if (\is_string($input) && $this->coerce) {
            $input = match ($input) {
                'true', '1' => true,
                'false', '0' => false,
                default => $input,
            };
        }

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
        if (!$this->coerce) {
            return JsonSchemaExport::of(['type' => 'boolean']);
        }

        return JsonSchemaExport::of(
            ['type' => 'boolean'],
            [new NonRepresentable([], 'coerce')],
        );
    }
}
