<?php

declare(strict_types=1);

namespace Rasuvaeff\Schema\Type;

use Closure;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\Schema\Combinator\IntoSchema;
use Rasuvaeff\Schema\Internal\Wire;
use Rasuvaeff\Schema\JsonSchemaExport;
use Rasuvaeff\Schema\NonRepresentable;
use Rasuvaeff\Schema\ParseError;
use Rasuvaeff\Schema\ParseResult;
use Rasuvaeff\Schema\SchemaException;
use Rasuvaeff\Schema\SchemaInterface;
use Rasuvaeff\Schema\SchemaType;

/**
 * A fixed shape: declared properties, each required, optional or
 * defaulted; and one of three unknown-key modes — rejected by default,
 * passed through into the result by `additionalProperties()`, or
 * dropped silently by `strip()`.
 *
 * Parse semantics pinned by the package docs: an absent optional key is
 * **absent from the result** (not null); a defaulted key is always
 * present, carrying the validated, normalized default.
 *
 * @extends SchemaType<array<string, mixed>>
 *
 * @api
 */
final class ObjectSchema extends SchemaType
{
    /** @var array<string, ObjectProperty> */
    public readonly array $properties;

    /**
     * @param array<string, SchemaInterface> $properties bare schemas are
     *     required; `->optional()`/`->default()` wrap into
     *     {@see ObjectProperty}
     * @param bool $additionalProperties allow unknown keys through
     *     (default: reject them)
     * @param bool $strip drop unknown keys silently instead of rejecting
     *     them — the third mode, mutually exclusive with
     *     `$additionalProperties`
     */
    public function __construct(
        array $properties,
        public readonly bool $additionalProperties = false,
        public readonly bool $strip = false,
    ) {
        if ($additionalProperties && $strip) {
            throw new \InvalidArgumentException('Strip and additionalProperties are mutually exclusive — pick one unknown-key mode');
        }

        $normalized = [];

        foreach ($properties as $name => $property) {
            if ($name === '') {
                throw new \InvalidArgumentException('Property names must be non-empty strings');
            }

            $normalized[$name] = $property instanceof ObjectProperty
                ? $property
                : new ObjectProperty($property);
        }

        $this->properties = $normalized;
    }

    public function additionalProperties(bool $allow = true): self
    {
        return new self(properties: $this->properties, additionalProperties: $allow, strip: false);
    }

    /**
     * The third unknown-key mode: drop them and keep the known ones —
     * the shape for parsing external payloads you only trust partially.
     * Mutually exclusive with `additionalProperties()`; the export keeps
     * `additionalProperties: true` (validators accept what the parser
     * strips) and records the drop as a `NonRepresentable` entry.
     */
    public function strip(): self
    {
        return new self(properties: $this->properties, additionalProperties: false, strip: true);
    }

    /**
     * Hydrate the parsed properties into a DTO with named arguments — a
     * thin combinator over the object parse:
     * `Schema::object([...])->into(UserDto::class)`. The DTO takes its
     * properties as public promoted constructor arguments; enum
     * instances, defaults and stripped extras are already settled when
     * the constructor runs. The export and the wire form stay this
     * object schema's own.
     *
     * @template TDto of object
     *
     * @param class-string<TDto> $class must exist and be instantiable
     *
     * @return IntoSchema<TDto>
     */
    public function into(string $class): IntoSchema
    {
        return new IntoSchema($this, $class);
    }

    #[\Override]
    public function parse(mixed $input): ParseResult
    {
        if (!\is_array($input)) {
            return ParseResult::failed([
                new ParseError([], \sprintf('Expected object, got %s', \get_debug_type($input))),
            ]);
        }

        /** @var array<string, mixed> $values */
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

        if ($this->additionalProperties) {
            // Pass-through: tolerated unknown keys stay in the result —
            // the mode for maps you round-trip. Strip drops them below
            // by never reaching this branch.
            foreach ($input as $key => $item) {
                if (!\array_key_exists($key, $this->properties)) {
                    $values[(string) $key] = $item;
                }
            }
        }

        if (!$this->additionalProperties && !$this->strip) {
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

    #[\Override]
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

    #[\Override]
    public function arbitrary(): ArbitraryInterface
    {
        if ($this->properties === []) {
            return Gen::constant([]);
        }

        /** @var list<ArbitraryInterface<array<string, mixed>>> $generators */
        $generators = [];

        foreach ($this->properties as $name => $property) {
            $entry = $this->entry($name);

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
            /**
             * @param list<mixed> $parts
             *
             * @return array<string, mixed>
             */
            static function (array $parts): array {
                /** @var array<string, mixed> $merged */
                $merged = [];

                foreach ($parts as $part) {
                    /** @var array<string, mixed> $part */
                    foreach (\array_keys($part) as $key) {
                        $merged[$key] = $part[$key];
                    }
                }

                return $merged;
            },
        );
    }

    /**
     * @return Closure(mixed): array<string, mixed>
     */
    private function entry(string $name): Closure
    {
        return static fn(mixed $value): array => [$name => $value];
    }

    #[\Override]
    public function toJsonSchema(bool $lenient = false): JsonSchemaExport
    {
        $properties = [];
        $required = [];
        $nonRepresentable = [];

        foreach ($this->properties as $name => $property) {
            $child = $property->schema->toJsonSchema($lenient);
            $properties[$name] = $child->schema;

            foreach ($child->nonRepresentable as $entry) {
                $nonRepresentable[] = $entry->withSegment($name);
            }

            if ($property->hasDefault) {
                // The annotation goes through the wire seam: an enum
                // default instance is exported as its backed value.
                $properties[$name]['default'] = Wire::value($property->schema, $property->default);
            } elseif (!$property->optional) {
                $required[] = $name;
            }
        }

        $schema = [
            'type' => 'object',
            'properties' => $properties,
            'additionalProperties' => $this->additionalProperties || $this->strip,
        ];

        if ($required !== []) {
            $schema['required'] = $required;
        }

        if ($this->strip) {
            // The fragment is an honest superset: a validator accepts
            // documents whose extra keys the parser silently drops. The
            // drop itself is not expressible — recorded, not thrown.
            $nonRepresentable[] = new NonRepresentable([], 'strip');
        }

        return JsonSchemaExport::of($schema, $nonRepresentable);
    }
}
