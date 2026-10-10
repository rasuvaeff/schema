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
 * A backed enum: parse accepts an instance or its backed value and always
 * returns the instance. The generator produces instances (best DX for
 * property tests); the wire form — the backed value — is a projection
 * introduced by the 1.1 export port.
 *
 * @template TEnum of \BackedEnum
 *
 * @extends SchemaType<TEnum>
 *
 * @api
 */
final class EnumSchema extends SchemaType
{
    public readonly string $backingType;

    /**
     * @param class-string<TEnum> $enum
     */
    public function __construct(
        public readonly string $enum,
    ) {
        if (!\enum_exists($enum) || !\is_subclass_of($enum, \BackedEnum::class)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a backed enum', $enum));
        }

        $backingType = (new \ReflectionEnum($enum))->getBackingType();

        if (!$backingType instanceof \ReflectionNamedType) {
            throw new \InvalidArgumentException(\sprintf('"%s" has an unexpected backing type', $enum));
        }

        $this->backingType = $backingType->getName();
    }

    #[\Override]
    public function parse(mixed $input): ParseResult
    {
        if ($input instanceof $this->enum) {
            return ParseResult::ok($input);
        }

        $matchesBackingType = ($this->backingType === 'int' && \is_int($input))
            || ($this->backingType === 'string' && \is_string($input));

        if ($matchesBackingType) {
            $case = $this->enum::tryFrom($input);

            if ($case !== null) {
                return ParseResult::ok($case);
            }
        }

        return ParseResult::failed([
            new ParseError([], \sprintf('Expected one of: %s', $this->allowedList())),
        ]);
    }

    #[\Override]
    public function parseOrThrow(mixed $input): \BackedEnum
    {
        $result = $this->parse($input);

        if ($result->ok) {
            /** @var TEnum $value */
            $value = $result->value;

            return $value;
        }

        throw new SchemaException($result->errors);
    }

    #[\Override]
    public function arbitrary(): ArbitraryInterface
    {
        return Gen::enum($this->enum);
    }

    /**
     * The allowed backed values, quoted and comma-joined — the enum's
     * vocabulary is part of the schema, safe to name.
     */
    private function allowedList(): string
    {
        $values = [];

        foreach ($this->enum::cases() as $case) {
            $values[] = \var_export($case->value, return: true);
        }

        return \implode(', ', $values);
    }

    #[\Override]
    public function toJsonSchema(bool $lenient = false): JsonSchemaExport
    {
        $backedValues = [];

        foreach ($this->enum::cases() as $case) {
            $backedValues[] = $case->value;
        }

        return JsonSchemaExport::of([
            // PHP's reflection name for an int backing type is "int";
            // JSON Schema calls it "integer".
            'type' => $this->backingType === 'int' ? 'integer' : 'string',
            'enum' => $backedValues,
        ]);
    }
}
