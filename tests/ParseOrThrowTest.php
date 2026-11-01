<?php

declare(strict_types=1);

namespace Rasuvaeff\Schema\Tests;

use Rasuvaeff\Schema\Combinator\IntoSchema;
use Rasuvaeff\Schema\Combinator\RefineSchema;
use Rasuvaeff\Schema\Combinator\TransformSchema;
use Rasuvaeff\Schema\Schema;
use Rasuvaeff\Schema\SchemaException;
use Rasuvaeff\Schema\SchemaInterface;
use Rasuvaeff\Schema\Tests\Support\Role;
use Rasuvaeff\Schema\Tests\Support\UserDto;
use Rasuvaeff\Schema\Type\ArraySchema;
use Rasuvaeff\Schema\Type\BoolSchema;
use Rasuvaeff\Schema\Type\DateTimeSchema;
use Rasuvaeff\Schema\Type\DiscriminatedSchema;
use Rasuvaeff\Schema\Type\EnumSchema;
use Rasuvaeff\Schema\Type\FloatSchema;
use Rasuvaeff\Schema\Type\IntSchema;
use Rasuvaeff\Schema\Type\JsonValueSchema;
use Rasuvaeff\Schema\Type\LiteralSchema;
use Rasuvaeff\Schema\Type\MapSchema;
use Rasuvaeff\Schema\Type\NullableSchema;
use Rasuvaeff\Schema\Type\ObjectSchema;
use Rasuvaeff\Schema\Type\StringSchema;
use Rasuvaeff\Schema\Type\TupleSchema;
use Rasuvaeff\Schema\Type\UnionSchema;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

/**
 * `parseOrThrow()` on every schema type: the success path returns the
 * parsed value, the failure path throws a {@see SchemaException} carrying
 * the same errors as `parse()`.
 */
#[Test]
#[Covers(ArraySchema::class)]
#[Covers(BoolSchema::class)]
#[Covers(DateTimeSchema::class)]
#[Covers(DiscriminatedSchema::class)]
#[Covers(EnumSchema::class)]
#[Covers(FloatSchema::class)]
#[Covers(IntSchema::class)]
#[Covers(IntoSchema::class)]
#[Covers(JsonValueSchema::class)]
#[Covers(LiteralSchema::class)]
#[Covers(MapSchema::class)]
#[Covers(NullableSchema::class)]
#[Covers(ObjectSchema::class)]
#[Covers(RefineSchema::class)]
#[Covers(StringSchema::class)]
#[Covers(TransformSchema::class)]
#[Covers(TupleSchema::class)]
#[Covers(UnionSchema::class)]
final class ParseOrThrowTest
{
    #[DataProvider('schemaProvider')]
    public function returnsTheParsedValueOnSuccess(SchemaInterface $schema, mixed $valid, mixed $expected): void
    {
        Assert::equals($schema->parseOrThrow($valid), $expected);
    }

    #[DataProvider('schemaProvider')]
    public function throwsTheParseErrorsOnFailure(SchemaInterface $schema, mixed $valid, mixed $expected, mixed $invalid): void
    {
        $errors = $schema->parse($invalid)->errors;

        Assert::true($errors !== []);

        try {
            $schema->parseOrThrow($invalid);
        } catch (SchemaException $exception) {
            Assert::equals($exception->errors, $errors);

            return;
        }

        throw new \LogicException('parseOrThrow was expected to fail');
    }

    public static function schemaProvider(): iterable
    {
        yield 'string' => [Schema::string(), 'a', 'a', 1];
        yield 'int' => [Schema::int(), 1, 1, 'x'];
        yield 'float' => [Schema::float(), 1.5, 1.5, 'x'];
        yield 'bool' => [Schema::bool(), true, true, 'x'];
        yield 'dateTime' => [Schema::dateTime(), '2026-10-11T10:15:30.123+00:00', new \DateTimeImmutable('2026-10-11T10:15:30.123+00:00'), 'x'];
        yield 'literal' => [Schema::literal('a'), 'a', 'a', 'b'];
        yield 'enum' => [Schema::enum(Role::class), 'admin', Role::Admin, 'x'];
        yield 'json' => [Schema::json(), ['a' => 1], ['a' => 1], new \stdClass()];
        yield 'array' => [Schema::arrayOf(Schema::int()), [1, 2], [1, 2], [1, 'x']];
        yield 'map' => [Schema::mapOf(Schema::int()), ['a' => 1, 'b' => 2], ['a' => 1, 'b' => 2], ['a' => 'x']];
        yield 'object' => [Schema::object(['id' => Schema::int(), 'n' => Schema::int()]), ['id' => 1, 'n' => 2], ['id' => 1, 'n' => 2], ['id' => 'x', 'n' => 2]];
        yield 'tuple' => [Schema::tuple([Schema::int(), Schema::string()]), [1, 'a'], [1, 'a'], ['a', 1]];
        yield 'nullable' => [Schema::nullable(Schema::int()), 3, 3, 'x'];
        yield 'union' => [Schema::union([Schema::int(), Schema::bool()]), 3, 3, 'x'];
        yield 'discriminated' => [
            Schema::discriminated('kind', [
                'a' => Schema::object(['kind' => Schema::literal('a'), 'n' => Schema::int()]),
            ]),
            ['kind' => 'a', 'n' => 1],
            ['kind' => 'a', 'n' => 1],
            ['kind' => 'a', 'n' => 'x'],
        ];
        yield 'refine' => [
            Schema::int()->refine(static fn(int $value): bool => $value > 0, 'Int must be positive'),
            1,
            1,
            -1,
        ];
        yield 'transform' => [
            Schema::int()->transform(static fn(int $value): string => (string) $value),
            1,
            '1',
            'x',
        ];
        yield 'into' => [
            Schema::object([
                'name' => Schema::string()->min(1),
                'age' => Schema::int(),
                'role' => Schema::enum(Role::class),
            ])->into(UserDto::class),
            ['name' => 'ada', 'age' => 37, 'role' => Role::Admin],
            new UserDto('ada', 37, Role::Admin),
            ['name' => '', 'age' => 37, 'role' => Role::Admin],
        ];
    }
}
