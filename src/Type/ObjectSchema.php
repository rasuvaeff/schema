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
use Rasuvaeff\Schema\Unknown;

/**
 * A fixed shape: declared properties, each required, optional or
 * defaulted; one {@see Unknown} mode for undeclared keys — rejected by
 * default, dropped by `Unknown::Strip`, passed through into the result by
 * `Unknown::Passthrough`; and optional "at least one of" groups
 * ({@see requireAnyOf()}).
 *
 * Parse semantics pinned by the package docs: an absent optional key is
 * **absent from the result** (not null); a defaulted key is always
 * present, carrying the validated, normalized default; the result keeps
 * the declaration order of the properties.
 *
 * `TShape` is the parsed array shape. Plain Psalm infers the bound
 * (`array<string, mixed>`); with the package's Psalm plugin
 * (`Rasuvaeff\Schema\Psalm\Plugin`) `Schema::object([...])` infers the
 * exact `array{...}` shape.
 *
 * @template TShape of array<string, mixed>
 *
 * @extends SchemaType<TShape>
 *
 * @api
 */
final class ObjectSchema extends SchemaType
{
    /**
     * @var array<array-key, ObjectProperty> declared names; a numeric name
     *     like "0" is an int key (PHP array semantics) and still an object key
     */
    public readonly array $properties;

    /**
     * @var list<non-empty-list<string>> "at least one of" groups, each a
     *     list of declared property names
     */
    public readonly array $anyOf;

    /**
     * @param array<array-key, SchemaInterface> $properties bare schemas
     *     are required; `->optional()`/`->default()` wrap into
     *     {@see ObjectProperty}
     * @param list<list<string>> $anyOf "at least one of" groups, each
     *     non-empty; every name must be a declared property
     */
    public function __construct(
        array $properties,
        public readonly Unknown $unknown = Unknown::Reject,
        array $anyOf = [],
    ) {
        $normalized = [];

        foreach ($properties as $name => $property) {
            if ($name === '') {
                throw new \InvalidArgumentException('Property names must be non-empty strings');
            }

            $normalized[$name] = $property instanceof ObjectProperty
                ? $property
                : new ObjectProperty($property);
        }

        foreach ($anyOf as $group) {
            if ($group === []) {
                throw new \InvalidArgumentException('An "at least one of" group needs at least one property');
            }

            foreach ($group as $name) {
                if (!\array_key_exists($name, $normalized)) {
                    throw new \InvalidArgumentException(\sprintf('Unknown property "%s" in an "at least one of" group', $name));
                }
            }
        }

        $this->properties = $normalized;
        /** @var list<non-empty-list<string>> $anyOf checked above */
        $this->anyOf = $anyOf;
    }

    /**
     * The same shape with another unknown-key mode.
     *
     * @return ObjectSchema<array<string, mixed>>
     */
    public function unknown(Unknown $mode): self
    {
        return new self(properties: $this->properties, unknown: $mode, anyOf: $this->anyOf);
    }

    /**
     * Demand that at least one of `$names` is present in the input (a
     * defaulted property always counts as present). Calls accumulate:
     * every group must be satisfied. The generator satisfies the groups
     * constructively; the export adds `anyOf: [{required: [name]}, ...]`.
     *
     * @return ObjectSchema<TShape>
     */
    public function requireAnyOf(string $name, string ...$names): self
    {
        /** @var ObjectSchema<TShape> $schema the shape is unchanged */
        $schema = new self(properties: $this->properties, unknown: $this->unknown, anyOf: [...$this->anyOf, [$name, ...\array_values($names)]]);

        return $schema;
    }

    /**
     * Add properties or replace declared ones (same name); the unknown-key
     * mode and the groups are kept.
     *
     * @param array<string, SchemaInterface> $properties
     *
     * @return ObjectSchema<array<string, mixed>>
     */
    public function extend(array $properties): self
    {
        return new self(properties: \array_replace($this->properties, $properties), unknown: $this->unknown, anyOf: $this->anyOf);
    }

    /**
     * Keep only the named properties. Every name must be declared; group
     * members that are dropped leave their groups, an emptied group goes.
     *
     * @return ObjectSchema<array<string, mixed>>
     */
    public function pick(string $name, string ...$names): self
    {
        return $this->only($this->declared([$name, ...$names]), keep: true);
    }

    /**
     * Drop the named properties. Every name must be declared; group
     * members that are dropped leave their groups, an emptied group goes.
     *
     * @return ObjectSchema<array<string, mixed>>
     */
    public function omit(string $name, string ...$names): self
    {
        return $this->only($this->declared([$name, ...$names]), keep: false);
    }

    /**
     * Every property optional — the shape of a PATCH body. A defaulted
     * property keeps its default (it is optional already).
     *
     * @return ObjectSchema<array<string, mixed>>
     */
    public function partial(): self
    {
        $properties = [];

        foreach ($this->properties as $name => $property) {
            $properties[$name] = $property->optional();
        }

        return new self(properties: $properties, unknown: $this->unknown, anyOf: $this->anyOf);
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

        /** @var array<array-key, mixed> $values */
        $values = [];
        $errors = [];

        foreach ($this->properties as $name => $property) {
            if (\array_key_exists($name, $input)) {
                $result = $property->schema->parse($input[$name]);

                if ($result->ok) {
                    $values[$name] = $result->value;
                } else {
                    foreach ($result->errors as $error) {
                        // PHP turns a key like "0" into int 0; an object
                        // key is a string segment ($.0, not $[0]).
                        $errors[] = $error->withSegment((string) $name);
                    }
                }
            } elseif ($property->hasDefault) {
                $values[$name] = $property->default;
            } elseif (!$property->optional) {
                $errors[] = new ParseError([(string) $name], \sprintf('Required property "%s" is missing', $name));
            }
        }

        foreach (\array_keys($input) as $key) {
            if (\array_key_exists($key, $this->properties)) {
                continue;
            }

            if ($this->unknown === Unknown::Passthrough) {
                $values[$key] = $input[$key];
            } elseif ($this->unknown === Unknown::Reject) {
                // the key is input: it is named by the path, never by the message
                $errors[] = new ParseError([(string) $key], 'Unknown property');
            }
        }

        foreach ($this->anyOf as $group) {
            if (!$this->satisfied($group, $input)) {
                $errors[] = new ParseError([], \sprintf('At least one of %s is required', $this->quoted($group)));
            }
        }

        if ($errors !== []) {
            return ParseResult::failed($errors);
        }

        /** @var TShape $values */
        return ParseResult::ok($values);
    }

    #[\Override]
    public function parseOrThrow(mixed $input): array
    {
        $result = $this->parse($input);

        if ($result->ok) {
            /** @var TShape $value */
            $value = $result->value;

            return $value;
        }

        throw new SchemaException($result->errors);
    }

    #[\Override]
    public function arbitrary(): ArbitraryInterface
    {
        if ($this->properties === []) {
            /** @var ArbitraryInterface<TShape> */
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

        // One fallback per group: a chosen member and a value for it,
        // inserted only when the random draw left the whole group absent —
        // the groups are satisfied by construction, never by filtering.
        /** @var list<ArbitraryInterface<array{list<string>, array<string, mixed>}>> $fallbacks */
        $fallbacks = [];

        foreach ($this->anyOf as $group) {
            $properties = $this->properties;
            $fallbacks[] = Gen::flatMap(
                Gen::elements($group),
                static fn(string $name): ArbitraryInterface => Gen::map(
                    $properties[$name]->schema->arbitrary(),
                    static fn(mixed $value): array => [$group, [$name => $value]],
                ),
            );
        }

        $order = \array_keys($this->properties);

        /** @var ArbitraryInterface<TShape> */
        return Gen::map(
            Gen::tuple(Gen::tuple(...$generators), $fallbacks === [] ? Gen::constant([]) : Gen::tuple(...$fallbacks)),
            /**
             * @param list<mixed> $drawn the properties' parts, then the
             *     groups' fallbacks
             *
             * @return array<array-key, mixed>
             */
            static function (array $drawn) use ($order): array {
                /** @var array{list<mixed>, list<mixed>} $drawn */
                [$parts, $fallbacks] = $drawn;

                /** @var array<array-key, mixed> $merged */
                $merged = [];

                foreach ($parts as $part) {
                    /** @var array<array-key, mixed> $part */
                    foreach (\array_keys($part) as $key) {
                        $merged[$key] = $part[$key];
                    }
                }

                foreach ($fallbacks as $fallback) {
                    /** @var array{0: list<string>, 1: array<string, mixed>} $fallback */
                    if (\array_intersect_key($merged, \array_flip($fallback[0])) === []) {
                        $merged = \array_replace($merged, $fallback[1]);
                    }
                }

                $ordered = [];

                foreach ($order as $key) {
                    if (\array_key_exists($key, $merged)) {
                        $ordered[$key] = $merged[$key];
                    }
                }

                return $ordered;
            },
        );
    }

    /**
     * @return Closure(mixed): array<array-key, mixed>
     */
    private function entry(int|string $name): Closure
    {
        return static fn(mixed $value): array => [$name => $value];
    }

    /**
     * @template TIn
     *
     * @param TIn $value
     *
     * @return (TIn is array ? array<string, mixed> : TIn)
     */
    #[\Override]
    public function toWire(mixed $value): mixed
    {
        /** @var (TIn is array ? array<string, mixed> : TIn) $wire Wire maps an array to an array, anything else passes */
        $wire = Wire::value($this, $value);

        return $wire;
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
                $nonRepresentable[] = $entry->withSegment((string) $name);
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
            'additionalProperties' => $this->unknown !== Unknown::Reject,
        ];

        if ($required !== []) {
            $schema['required'] = $required;
        }

        $groups = [];

        foreach ($this->anyOf as $group) {
            $groups[] = ['anyOf' => \array_map(static fn(string $name): array => ['required' => [$name]], $group)];
        }

        if (\count($groups) === 1) {
            $schema['anyOf'] = $groups[0]['anyOf'];
        } elseif ($groups !== []) {
            $schema['allOf'] = $groups;
        }

        if ($this->unknown === Unknown::Strip) {
            // The fragment is an honest superset: a validator accepts
            // documents whose extra keys the parser silently drops. The
            // drop itself is not expressible — recorded, not thrown.
            $nonRepresentable[] = new NonRepresentable([], 'strip');
        }

        return JsonSchemaExport::of($schema, $nonRepresentable);
    }

    /**
     * @param non-empty-list<string> $group
     * @param array<array-key, mixed> $input
     */
    private function satisfied(array $group, array $input): bool
    {
        foreach ($group as $name) {
            if (\array_key_exists($name, $input) || $this->properties[$name]->hasDefault) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $names
     */
    private function quoted(array $names): string
    {
        return \implode(', ', \array_map(static fn(string $name): string => '"' . $name . '"', $names));
    }

    /**
     * @param array<array-key, string> $names
     *
     * @return array<array-key, string>
     */
    private function declared(array $names): array
    {
        foreach ($names as $name) {
            if (!\array_key_exists($name, $this->properties)) {
                throw new \InvalidArgumentException(\sprintf('Unknown property "%s"', $name));
            }
        }

        return $names;
    }

    /**
     * @param array<array-key, string> $names
     * @param bool $keep keep the named properties (pick) or drop them (omit)
     *
     * @return ObjectSchema<array<string, mixed>>
     */
    private function only(array $names, bool $keep): self
    {
        $properties = [];

        foreach ($this->properties as $name => $property) {
            if (\in_array((string) $name, $names, strict: true) === $keep) {
                $properties[$name] = $property;
            }
        }

        $anyOf = [];

        foreach ($this->anyOf as $group) {
            $left = [];

            foreach ($group as $member) {
                if (\array_key_exists($member, $properties)) {
                    $left[] = $member;
                }
            }

            if ($left !== []) {
                $anyOf[] = $left;
            }
        }

        return new self(properties: $properties, unknown: $this->unknown, anyOf: $anyOf);
    }
}
