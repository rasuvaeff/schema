<?php

declare(strict_types=1);

namespace Rasuvaeff\Schema\Tests;

use Rasuvaeff\Schema\Internal\Wire;
use Rasuvaeff\Schema\Schema;
use Rasuvaeff\Schema\Tests\Support\Role;
use Rasuvaeff\Schema\Tests\Support\UserDto;
use Rasuvaeff\Schema\Type\EnumSchema;
use Rasuvaeff\Schema\Unknown;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

/**
 * The public wire port (`toWire()`), dogfooding the internal dispatch:
 * the inverse pair of the generator's domain — `arbitrary()` produces PHP
 * values, `toWire()` hands them to queues, HTTP and JSON storage.
 */
#[Test]
#[Covers(Wire::class)]
#[Covers(Schema::class)]
final class SchemaToWireTest
{
    public function convertsAnEnumInstanceToItsBackedValue(): void
    {
        Assert::same(Schema::enum(Role::class)->toWire(Role::Editor), 'editor');
    }

    public function convertsContainersRecursively(): void
    {
        $list = Schema::arrayOf(Schema::enum(Role::class));

        Assert::same($list->toWire([Role::Admin, Role::Viewer]), ['admin', 'viewer']);

        $map = Schema::mapOf(Schema::enum(Role::class));

        Assert::same($map->toWire(['first' => Role::Editor]), ['first' => 'editor']);

        $object = Schema::object([
            'role' => Schema::enum(Role::class),
            'count' => Schema::int(),
        ]);

        Assert::same(
            $object->toWire(['role' => Role::Admin, 'count' => 3]),
            ['role' => 'admin', 'count' => 3],
        );
    }

    public function anAbsentOptionalPropertyStaysAbsent(): void
    {
        $schema = Schema::object([
            'role' => Schema::enum(Role::class),
            'note' => Schema::string()->optional(),
        ]);

        Assert::same($schema->toWire(['role' => Role::Admin]), ['role' => 'admin']);
    }

    public function nullablePassesNullThrough(): void
    {
        $schema = Schema::nullable(Schema::enum(Role::class));

        Assert::null($schema->toWire(null));
        Assert::same($schema->toWire(Role::Admin), 'admin');
    }

    public function unionUsesTheFirstMemberThatAccepts(): void
    {
        $schema = Schema::union([
            Schema::int(),
            Schema::enum(Role::class),
        ]);

        Assert::same($schema->toWire(5), 5);
        Assert::same($schema->toWire(Role::Viewer), 'viewer');
    }

    public function transformAppliesItsDeclaredWireCallback(): void
    {
        $schema = Schema::int()->transform(
            static fn(int $value): \DateTimeImmutable => new \DateTimeImmutable('@' . $value),
            wire: static fn(\DateTimeImmutable $dateTime): string => $dateTime->format('Y-m-d'),
        );

        Assert::same($schema->toWire(new \DateTimeImmutable('@' . 86_400)), '1970-01-02');
    }

    public function transformWithoutAWireIsTheIdentity(): void
    {
        $schema = Schema::int()->transform(static fn(int $value): string => (string) $value);

        Assert::same($schema->toWire('42'), '42');
    }

    public function jsonNativeSchemasPassThroughUnchanged(): void
    {
        Assert::same(Schema::string()->toWire('x'), 'x');
        Assert::same(Schema::json()->toWire(['a' => [1, null]]), ['a' => [1, null]]);
    }

    public function aNonArrayAgainstAContainerSchemaPassesThrough(): void
    {
        Assert::same(Schema::arrayOf(Schema::int())->toWire('not an array'), 'not an array');
        Assert::same(Schema::mapOf(Schema::int())->toWire(42), 42);
    }

    public function propertyModifiersDelegateToTheInnerSchema(): void
    {
        $property = Schema::enum(Role::class)->default('admin');

        Assert::same($property->toWire(Role::Viewer), 'viewer');
    }

    public function onlyAnEnumSchemaProjectsAnEnumInstance(): void
    {
        Assert::same(Schema::literal('x')->toWire(Role::Admin), Role::Admin);
        Assert::same(Schema::enum(Role::class)->toWire('admin'), 'admin');
    }

    public function aDateTimeIsProjectedOnlyByADateTimeSchema(): void
    {
        $moment = new \DateTimeImmutable('2026-10-11T10:15:30.123+00:00');

        Assert::same(Schema::dateTime()->toWire($moment), '2026-10-11T10:15:30.123+00:00');
        Assert::same(Schema::dateTime()->toWire('not a date'), 'not a date');
        Assert::same(Schema::string()->toWire($moment), $moment);
    }

    public function stripDropsUnknownKeysWhilePassthroughKeepsThemRaw(): void
    {
        $properties = ['role' => Schema::enum(Role::class)];
        $value = ['role' => Role::Admin, 'extra' => Role::Viewer];

        Assert::same(
            Schema::object($properties, Unknown::Strip)->toWire($value),
            ['role' => 'admin'],
        );
        Assert::same(
            Schema::object($properties, Unknown::Passthrough)->toWire($value),
            ['role' => 'admin', 'extra' => Role::Viewer],
        );
    }

    public function aShortTupleProjectsOnlyThePositionsItHas(): void
    {
        $schema = Schema::tuple([Schema::enum(Role::class), Schema::enum(Role::class)]);

        Assert::same($schema->toWire([Role::Admin]), ['admin']);
        Assert::same($schema->toWire([Role::Admin, Role::Viewer]), ['admin', 'viewer']);
    }

    public function aUnionWithoutAnAcceptingMemberPassesTheValueThrough(): void
    {
        Assert::same(Schema::union([Schema::int(), Schema::bool()])->toWire('x'), 'x');
    }

    public function discriminatedProjectsThroughTheAcceptingBranch(): void
    {
        $schema = Schema::discriminated('kind', [
            'user' => Schema::object([
                'kind' => Schema::literal('user'),
                'role' => Schema::enum(Role::class),
            ]),
        ]);

        Assert::same(
            $schema->toWire(['kind' => 'user', 'role' => Role::Editor]),
            ['kind' => 'user', 'role' => 'editor'],
        );
        Assert::same($schema->toWire(['kind' => 'other']), ['kind' => 'other']);
    }

    public function refineLazyAndIntoDelegateToTheirInnerSchema(): void
    {
        Assert::same(
            Schema::enum(Role::class)->refine(static fn(Role $role): bool => true, 'never')->toWire(Role::Admin),
            'admin',
        );
        Assert::same(
            Schema::lazy(static fn(): EnumSchema => Schema::enum(Role::class))->toWire(Role::Viewer),
            'viewer',
        );

        $into = Schema::object([
            'name' => Schema::string(),
            'age' => Schema::int(),
            'role' => Schema::enum(Role::class),
            'bio' => Schema::string(),
        ])->into(UserDto::class);
        $expected = ['name' => 'ada', 'age' => 37, 'role' => 'admin', 'bio' => ''];

        Assert::same($into->toWire(new UserDto('ada', 37, Role::Admin)), $expected);
        Assert::same(
            $into->toWire(['name' => 'ada', 'age' => 37, 'role' => Role::Admin, 'bio' => '']),
            $expected,
        );
    }
}
