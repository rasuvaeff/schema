<?php

declare(strict_types=1);

namespace Rasuvaeff\Schema\Tests;

use Rasuvaeff\Schema\Internal\Wire;
use Rasuvaeff\Schema\Schema;
use Rasuvaeff\Schema\Tests\Support\Role;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(Wire::class)]
final class WireTest
{
    public function convertsAnEnumInstanceToItsBackedValue(): void
    {
        Assert::same(Wire::value(Schema::enum(Role::class), Role::Editor), 'editor');
    }

    public function convertsContainersRecursively(): void
    {
        $list = Schema::arrayOf(Schema::enum(Role::class));

        Assert::same(Wire::value($list, [Role::Admin, Role::Viewer]), ['admin', 'viewer']);

        $map = Schema::mapOf(Schema::enum(Role::class));

        Assert::same(Wire::value($map, ['first' => Role::Editor]), ['first' => 'editor']);

        $object = Schema::object([
            'role' => Schema::enum(Role::class),
            'count' => Schema::int(),
        ]);

        Assert::same(
            Wire::value($object, ['role' => Role::Admin, 'count' => 3]),
            ['role' => 'admin', 'count' => 3],
        );
    }

    public function aNonArrayAgainstAContainerSchemaPassesThrough(): void
    {
        Assert::same(Wire::value(Schema::arrayOf(Schema::int()), 'not an array'), 'not an array');
        Assert::same(Wire::value(Schema::mapOf(Schema::int()), 42), 42);
    }

    public function nullablePassesNullThrough(): void
    {
        $schema = Schema::nullable(Schema::enum(Role::class));

        Assert::null(Wire::value($schema, null));
        Assert::same(Wire::value($schema, Role::Admin), 'admin');
    }

    public function unionUsesTheFirstMemberThatAccepts(): void
    {
        $schema = Schema::union([
            Schema::int(),
            Schema::enum(Role::class),
        ]);

        Assert::same(Wire::value($schema, 5), 5);
        Assert::same(Wire::value($schema, Role::Viewer), 'viewer');
    }

    public function transformAppliesItsDeclaredWireCallback(): void
    {
        $schema = Schema::int()->transform(
            static fn(int $value): \DateTimeImmutable => new \DateTimeImmutable('@' . $value),
            wire: static fn(\DateTimeImmutable $dateTime): string => $dateTime->format('Y-m-d'),
        );

        Assert::same(Wire::value($schema, new \DateTimeImmutable('@' . 86_400)), '1970-01-02');
    }

    public function transformWithoutAWireIsTheIdentity(): void
    {
        $schema = Schema::int()->transform(static fn(int $value): string => (string) $value);

        Assert::same(Wire::value($schema, '42'), '42');
    }

    public function jsonNativeSchemasPassThroughUnchanged(): void
    {
        Assert::same(Wire::value(Schema::string(), 'x'), 'x');
        Assert::same(Wire::value(Schema::json(), ['a' => [1, null]]), ['a' => [1, null]]);
    }
}
