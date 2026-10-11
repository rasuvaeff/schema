<?php

declare(strict_types=1);

namespace Rasuvaeff\Schema\Combinator;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\Schema\Internal\Wire;
use Rasuvaeff\Schema\JsonSchemaExport;
use Rasuvaeff\Schema\ParseError;
use Rasuvaeff\Schema\ParseResult;
use Rasuvaeff\Schema\SchemaException;
use Rasuvaeff\Schema\SchemaType;
use Rasuvaeff\Schema\Type\ObjectSchema;

/**
 * Optional hydration of a parsed object into a DTO: the object schema
 * parses the payload, then the parsed properties are spread into the
 * DTO's constructor as named arguments — enum instances and defaults are
 * already in place, so a `Schema::enum(Role::class)` property arrives as
 * the `Role` case the constructor asks for.
 *
 * The DTO is expected to take its properties as public promoted
 * constructor arguments (the value object shape); that is what makes the
 * wire projection lossless — {@see toWire()} walks the object schema
 * over `get_object_vars()`.
 *
 * @template TDto of object
 *
 * @extends SchemaType<TDto>
 *
 * @api
 */
final class IntoSchema extends SchemaType
{
    /**
     * @param ObjectSchema $object the source shape: parsed, defaulted,
     *     stripped — all of it before the constructor call
     * @param class-string<TDto> $class the DTO to hydrate into; must
     *     exist and be instantiable (checked eagerly)
     */
    public function __construct(
        public readonly ObjectSchema $object,
        public readonly string $class,
    ) {
        if (!\class_exists($class) && !\interface_exists($class)) {
            throw new \InvalidArgumentException(\sprintf('Hydration target "%s" does not exist', $class));
        }

        $reflection = new \ReflectionClass($class);

        if (!$reflection->isInstantiable()) {
            throw new \InvalidArgumentException(\sprintf('Hydration target "%s" is not instantiable', $class));
        }
    }

    #[\Override]
    public function parse(mixed $input): ParseResult
    {
        $result = $this->object->parse($input);

        if (!$result->ok) {
            return ParseResult::failed($result->errors);
        }

        /** @var array<string, mixed> $properties */
        $properties = $result->value;

        try {
            return ParseResult::ok($this->hydrate(properties: $properties));
        } catch (\Throwable $exception) {
            // The constructor's own message may embed values; the class
            // names are the most an error ever carries.
            return ParseResult::failed([
                new ParseError(
                    [],
                    \sprintf('Hydration into %s failed (%s)', $this->class, $exception::class),
                ),
            ]);
        }
    }

    #[\Override]
    public function parseOrThrow(mixed $input): object
    {
        $result = $this->parse($input);

        if ($result->ok) {
            /** @var TDto $value */
            $value = $result->value;

            return $value;
        }

        throw new SchemaException($result->errors);
    }

    #[\Override]
    public function arbitrary(): ArbitraryInterface
    {
        return Gen::map(
            $this->object->arbitrary(),
            /**
             * @param array<string, mixed> $properties
             */
            fn(array $properties): object => $this->hydrate(properties: $properties),
        );
    }

    #[\Override]
    public function toJsonSchema(bool $lenient = false): JsonSchemaExport
    {
        // The wire form is the object schema's own — hydration is a PHP
        //-domain concern the fragment describes exactly as before.
        return $this->object->toJsonSchema($lenient);
    }

    #[\Override]
    public function toWire(mixed $value): mixed
    {
        return Wire::value($this->object, \is_object($value) ? \get_object_vars($value) : $value);
    }

    /**
     * @param array<string, mixed> $properties
     *
     * @return TDto
     */
    private function hydrate(array $properties): object
    {
        /** @var \ReflectionClass<TDto> $reflection */
        $reflection = new \ReflectionClass($this->class);

        return $reflection->newInstanceArgs($properties);
    }
}
