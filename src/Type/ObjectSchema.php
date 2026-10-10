<?php

declare(strict_types=1);

namespace Rasuvaeff\Schema\Type;

use Closure;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\Schema\Combinators;
use Rasuvaeff\Schema\ParseError;
use Rasuvaeff\Schema\ParseResult;
use Rasuvaeff\Schema\SchemaException;
use Rasuvaeff\Schema\SchemaInterface;

/**
 * A fixed shape: declared properties, each required, optional or
 * defaulted; unknown keys are rejected unless `additionalProperties()`
 * allows them.
 *
 * Parse semantics pinned by the package docs: an absent optional key is
 * **absent from the result** (not null); a defaulted key is always
 * present, carrying the validated, normalized default.
 *
 * @implements SchemaInterface<array<string, mixed>>
 *
 * @api
 */
final readonly class ObjectSchema implements SchemaInterface
{
    use Combinators;

    /**
     * @param array<string, SchemaInterface> $properties bare schemas are
     *     required; `->optional()`/`->default()` wrap into
     *     {@see ObjectProperty}
     */
    public function __construct(
        public array $properties,
        public bool $additionalProperties = false,
    ) {
        foreach ($properties as $name => $property) {
            if (!\is_string($name) || $name === '') {
                throw new \InvalidArgumentException('Property names must be non-empty strings');
            }
        }
    }

    public function additionalProperties(bool $allow = true): self
    {
        return new self(properties: $this->properties, additionalProperties: $allow);
    }

    public function parse(mixed $input): ParseResult
    {
        if (!\is_array($input)) {
            return ParseResult::failed([
                new ParseError([], \sprintf('Expected object, got %s', \get_debug_type($input))),
            ]);
        }

        $values = [];
        $errors = [];

        foreach ($this->properties as $name => $property) {
            if (\array_key_exists($name, $input)) {
                $result = $property->schema->parse($input[$name]);

                if ($result->ok) {
                    $values[$name] = $result->value;
                } else {
                    foreach ($result->errors as $error) {
                        $errors[] = $error->withSegment($name);
                    }
                }
            } elseif ($property->hasDefault) {
                $values[$name] = $property->default;
            } elseif (!$property->optional) {
                $errors[] = new ParseError([$name], \sprintf('Required property "%s" is missing', $name));
            }
        }

        if (!$this->additionalProperties) {
            foreach (\array_keys($input) as $key) {
                if (!\array_key_exists($key, $this->properties)) {
                    $errors[] = new ParseError(
                        [\is_string($key) ? $key : (string) $key],
                        \sprintf('Unknown property "%s"', $key),
                    );
                }
            }
        }

        if ($errors !== []) {
            return ParseResult::failed($errors);
        }

        return ParseResult::ok($values);
    }

    public function parseOrThrow(mixed $input): array
    {
        $result = $this->parse($input);

        if ($result->ok) {
            /** @var array<string, mixed> $value */
            $value = $result->value;

            return $value;
        }

        throw new SchemaException($result->errors);
    }

    public function arbitrary(): ArbitraryInterface
    {
        if ($this->properties === []) {
            return Gen::constant([]);
        }

        $generators = [];

        foreach ($this->properties as $name => $property) {
            $entry = self::entry($name);

            if ($property->optional && !$property->hasDefault) {
                $generators[] = Gen::frequency([
                    [1, Gen::map($property->schema->arbitrary(), $entry)],
                    [1, Gen::constant([])],
                ]);
            } else {
                $generators[] = Gen::map($property->schema->arbitrary(), $entry);
            }
        }

        return Gen::map(
            Gen::tuple(...$generators),
            static fn (array $parts): array => \array_merge(...$parts),
        );
    }

    /**
     * @return Closure(mixed): array<string, mixed>
     */
    private static function entry(string $name): Closure
    {
        return static fn (mixed $value): array => [$name => $value];
    }
}
