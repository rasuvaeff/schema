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
 * A backed enum: parse accepts an instance or its backed value and always
 * returns the instance. The generator produces instances (best DX for
 * property tests); the wire form — the backed value — is a projection
 * introduced by the 1.1 export port.
 *
 * @template TEnum of \BackedEnum
 *
 * @implements SchemaInterface<TEnum>
 *
 * @api
 */
final readonly class EnumSchema implements SchemaInterface
{
    use Combinators;

    /**
     * @param class-string<TEnum> $enum
     */
    public function __construct(
        public string $enum,
    ) {
        if (!\enum_exists($enum) || !\is_subclass_of($enum, \BackedEnum::class)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a backed enum', $enum));
        }
    }

    public function parse(mixed $input): ParseResult
    {
        if ($input instanceof $this->enum) {
            return ParseResult::ok($input);
        }

        if (\is_int($input) || \is_string($input)) {
            $case = $this->enum::tryFrom($input);

            if ($case !== null) {
                return ParseResult::ok($case);
            }
        }

        return ParseResult::failed([
            new ParseError([], \sprintf('Expected one of: %s', $this->allowedList())),
        ]);
    }

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
            $values[] = \var_export($case->value, true);
        }

        return \implode(', ', $values);
    }
}
