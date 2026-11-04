<?php

declare(strict_types=1);

namespace Rasuvaeff\Schema\Psalm;

use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\VariadicPlaceholder;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\MethodReturnTypeProviderInterface;
use Psalm\StatementsSource;
use Psalm\Type;
use Psalm\Type\Atomic\TArray;
use Psalm\Type\Atomic\TEnumCase;
use Psalm\Type\Atomic\TGenericObject;
use Psalm\Type\Atomic\TKeyedArray;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;
use Rasuvaeff\Schema\Combinator\IntoSchema;
use Rasuvaeff\Schema\Combinator\RefineSchema;
use Rasuvaeff\Schema\Combinator\TransformSchema;
use Rasuvaeff\Schema\Schema;
use Rasuvaeff\Schema\Type\ArraySchema;
use Rasuvaeff\Schema\Type\BoolSchema;
use Rasuvaeff\Schema\Type\DateTimeSchema;
use Rasuvaeff\Schema\Type\DiscriminatedSchema;
use Rasuvaeff\Schema\Type\EnumSchema;
use Rasuvaeff\Schema\Type\FloatSchema;
use Rasuvaeff\Schema\Type\IntSchema;
use Rasuvaeff\Schema\Type\LiteralSchema;
use Rasuvaeff\Schema\Type\LiteralsSchema;
use Rasuvaeff\Schema\Type\MapSchema;
use Rasuvaeff\Schema\Type\NullableSchema;
use Rasuvaeff\Schema\Type\ObjectProperty;
use Rasuvaeff\Schema\Type\ObjectSchema;
use Rasuvaeff\Schema\Type\StringSchema;
use Rasuvaeff\Schema\Type\TupleSchema;
use Rasuvaeff\Schema\Unknown;

/**
 * Computes the `TShape` of an object schema from its declaration:
 * `Schema::object()` and the shape-changing `ObjectSchema` methods
 * (`unknown`, `extend`, `pick`, `omit`, `partial`).
 *
 * A required or defaulted property is a present key; an `->optional()`
 * one is a possibly undefined key (`name?:`); a property whose
 * declaration is not visible (a variable holding an `ObjectProperty`) is
 * treated as possibly undefined — the sound choice. `Unknown::Passthrough`
 * (or a mode that is not a literal case) leaves the shape unsealed
 * (`...<string, mixed>`). Anything the plugin cannot read precisely falls
 * back to the declared `array<string, mixed>`.
 *
 * @internal
 */
final class ObjectShapeProvider implements MethodReturnTypeProviderInterface
{
    #[\Override]
    public static function getClassLikeNames(): array
    {
        return [Schema::class, ObjectSchema::class];
    }

    #[\Override]
    public static function getMethodReturnType(MethodReturnTypeProviderEvent $event): ?Union
    {
        $source = $event->getSource();
        $args = $event->getCallArgs();
        $method = $event->getMethodNameLowercase();

        if (\strtolower($event->getFqClasslikeName()) === \strtolower(Schema::class)) {
            if ($method !== 'object') {
                return null;
            }

            $properties = self::properties($source, self::arg($args, 0, 'properties'));

            if ($properties === null) {
                return null;
            }

            return self::schema(self::shape($properties, self::sealed($source, self::arg($args, 1, 'unknown'))));
        }

        $current = self::current($event->getTemplateTypeParameters());

        if ($current === null) {
            return null;
        }

        [$properties, $sealed] = $current;

        $shape = match ($method) {
            'unknown' => self::shape($properties, self::sealed($source, self::arg($args, 0, 'mode'), whenAbsent: $sealed)),
            'extend' => self::extended($source, $args, $properties, $sealed),
            'pick' => self::picked($source, $args, $properties, $sealed, keep: true),
            'omit' => self::picked($source, $args, $properties, $sealed, keep: false),
            'partial' => self::shape(\array_map(
                static fn(Union $type): Union => $type->setPossiblyUndefined(possibly_undefined: true),
                $properties,
            ), $sealed),
            default => null,
        };

        return $shape instanceof Union ? self::schema($shape) : null;
    }

    private static function schema(Union $shape): Union
    {
        return new Union([new TGenericObject(ObjectSchema::class, [$shape])]);
    }

    /**
     * @param array<string, Union> $properties
     */
    private static function shape(array $properties, bool $sealed): Union
    {
        $fallback = $sealed ? null : [Type::getString(), Type::getMixed()];

        if ($properties === []) {
            return $fallback === null ? Type::getEmptyArray() : new Union([new TArray($fallback)]);
        }

        return new Union([TKeyedArray::make($properties, null, $fallback)]);
    }

    /**
     * @param list<Union>|null $templates
     *
     * @return array{array<string, Union>, bool}|null
     */
    private static function current(?array $templates): ?array
    {
        $shape = $templates[0] ?? null;

        if ($shape === null || !$shape->isSingle()) {
            return null;
        }

        $atomic = $shape->getSingleAtomic();

        if ($atomic instanceof TArray && $atomic->isEmptyArray()) {
            return [[], true];
        }

        if ($atomic instanceof TArray && $atomic->type_params[1]->isMixed()) {
            return [[], false];
        }

        if (!$atomic instanceof TKeyedArray || $atomic->is_list) {
            return null;
        }

        $properties = [];

        foreach ($atomic->properties as $key => $type) {
            if (!\is_string($key)) {
                return null;
            }

            $properties[$key] = $type;
        }

        return [$properties, $atomic->fallback_params === null];
    }

    /**
     * @param list<Arg|VariadicPlaceholder> $args
     * @param array<string, Union> $properties
     */
    private static function extended(StatementsSource $source, array $args, array $properties, bool $sealed): ?Union
    {
        $added = self::properties($source, self::arg($args, 0, 'properties'));

        if ($added === null) {
            return null;
        }

        return self::shape([...$properties, ...$added], $sealed);
    }

    /**
     * @param list<Arg|VariadicPlaceholder> $args
     * @param array<string, Union> $properties
     */
    private static function picked(StatementsSource $source, array $args, array $properties, bool $sealed, bool $keep): ?Union
    {
        $names = [];

        foreach ($args as $arg) {
            if (!$arg instanceof Arg || $arg->unpack) {
                return null;
            }

            $type = $source->getNodeTypeProvider()->getType($arg->value);

            if (!$type instanceof Union || !$type->isSingleStringLiteral()) {
                return null;
            }

            $names[] = $type->getSingleStringLiteral()->value;
        }

        $result = [];

        foreach ($properties as $name => $type) {
            if (\in_array($name, $names, strict: true) === $keep) {
                $result[$name] = $type;
            }
        }

        return self::shape($result, $sealed);
    }

    /**
     * @param list<Arg|VariadicPlaceholder> $args
     */
    private static function arg(array $args, int $position, string $name): ?Arg
    {
        $index = 0;

        foreach ($args as $arg) {
            if (!$arg instanceof Arg || $arg->unpack) {
                return null;
            }

            if ($arg->name instanceof Identifier) {
                if ($arg->name->name === $name) {
                    return $arg;
                }

                continue;
            }

            if ($index === $position) {
                return $arg;
            }

            $index++;
        }

        return null;
    }

    private static function sealed(StatementsSource $source, ?Arg $arg, bool $whenAbsent = true): bool
    {
        if (!$arg instanceof Arg) {
            return $whenAbsent;
        }

        $type = $source->getNodeTypeProvider()->getType($arg->value);

        if (!$type instanceof Union || !$type->isSingle()) {
            return false;
        }

        $atomic = $type->getSingleAtomic();

        return $atomic instanceof TEnumCase
            && \strtolower($atomic->value) === \strtolower(Unknown::class)
            && $atomic->case_name !== Unknown::Passthrough->name;
    }

    /**
     * @return array<string, Union>|null
     */
    private static function properties(StatementsSource $source, ?Arg $arg): ?array
    {
        if (!$arg instanceof Arg) {
            return null;
        }

        $type = $source->getNodeTypeProvider()->getType($arg->value);

        if (!$type instanceof Union || !$type->isSingle()) {
            return null;
        }

        $atomic = $type->getSingleAtomic();

        if ($atomic instanceof TArray && $atomic->isEmptyArray()) {
            return [];
        }

        if (!$atomic instanceof TKeyedArray || $atomic->fallback_params !== null) {
            return null;
        }

        $declarations = self::declarations($arg->value);
        $properties = [];

        foreach ($atomic->properties as $key => $schema) {
            if (!\is_string($key)) {
                return null;
            }

            $property = self::property($schema, $declarations[$key] ?? null);

            if (!$property instanceof Union) {
                return null;
            }

            $properties[$key] = $property;
        }

        return $properties;
    }

    /**
     * @return array<string, Expr>
     */
    private static function declarations(Expr $expr): array
    {
        if (!$expr instanceof Array_) {
            return [];
        }

        $declarations = [];

        foreach (\array_filter($expr->items) as $item) {
            if ($item->key instanceof String_) {
                $declarations[$item->key->value] = $item->value;
            }
        }

        return $declarations;
    }

    private static function property(Union $schema, ?Expr $declaration): ?Union
    {
        if (!$schema->isSingle()) {
            return null;
        }

        $atomic = $schema->getSingleAtomic();

        if (!$atomic instanceof TNamedObject) {
            return null;
        }

        if (\strtolower($atomic->value) !== \strtolower(ObjectProperty::class)) {
            return self::value($atomic);
        }

        $value = $atomic instanceof TGenericObject ? $atomic->type_params[0] : Type::getMixed();

        return $declaration instanceof Expr && self::defaulted($declaration)
            ? $value->setPossiblyUndefined(possibly_undefined: false)
            : $value->setPossiblyUndefined(possibly_undefined: true);
    }

    /**
     * A `->default(...)` anywhere in the chain makes the key present:
     * `ObjectProperty::optional()` keeps a default.
     */
    private static function defaulted(Expr $expr): bool
    {
        while ($expr instanceof MethodCall) {
            if ($expr->name instanceof Identifier && \strtolower($expr->name->name) === 'default') {
                return true;
            }

            $expr = $expr->var;
        }

        return false;
    }

    /**
     * The parsed value type of one schema object type.
     */
    private static function value(TNamedObject $schema): Union
    {
        $params = $schema instanceof TGenericObject ? $schema->type_params : [];
        $first = $params[0] ?? Type::getMixed();

        return match (\strtolower($schema->value)) {
            \strtolower(StringSchema::class) => Type::getString(),
            \strtolower(IntSchema::class) => Type::getInt(),
            \strtolower(FloatSchema::class) => Type::getFloat(),
            \strtolower(BoolSchema::class) => Type::getBool(),
            \strtolower(DateTimeSchema::class) => new Union([new TNamedObject(\DateTimeImmutable::class)]),
            \strtolower(TupleSchema::class) => Type::getList(),
            \strtolower(DiscriminatedSchema::class) => new Union([new TArray([Type::getString(), Type::getMixed()])]),
            \strtolower(ArraySchema::class) => Type::getList($first),
            \strtolower(MapSchema::class) => new Union([new TArray([Type::getString(), $first])]),
            \strtolower(NullableSchema::class) => Type::combineUnionTypes($first, Type::getNull()),
            \strtolower(TransformSchema::class) => $params[1] ?? Type::getMixed(),
            \strtolower(LiteralSchema::class),
            \strtolower(LiteralsSchema::class),
            \strtolower(EnumSchema::class),
            \strtolower(RefineSchema::class),
            \strtolower(IntoSchema::class),
            \strtolower(ObjectSchema::class) => $first,
            default => Type::getMixed(),
        };
    }
}
