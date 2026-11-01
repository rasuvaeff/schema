<?php

declare(strict_types=1);

namespace Rasuvaeff\Schema\Tests\Psalm;

use Rasuvaeff\Schema\Schema;
use Rasuvaeff\Schema\Tests\Support\Role;
use Rasuvaeff\Schema\Tests\Support\UserDto;
use Rasuvaeff\Schema\Unknown;

/**
 * Not a test class: Psalm analyses it with the plugin enabled
 * (`psalm-plugin.xml`), and every `@psalm-check-type-exact` must hold.
 */
final class ObjectShapeFixture
{
    /**
     * Every checked variable lands here, so no assignment is dead code
     * (rector would remove it, and the check with it).
     *
     * @var list<mixed>
     */
    public array $checked = [];
    public function scalarsAndModifiers(mixed $input): void
    {
        $user = Schema::object([
            'id' => Schema::int()->positive(),
            'email' => Schema::string(),
            'score' => Schema::float(),
            'active' => Schema::bool(),
            'at' => Schema::dateTime(),
            'nick' => Schema::string()->optional(),
            'lang' => Schema::string()->default('en'),
            'kept' => Schema::string()->default('x')->optional(),
        ])->parseOrThrow($input);

        /** @psalm-check-type-exact $user = array{id: int, email: string, score: float, active: bool, at: \DateTimeImmutable, nick?: string, lang: string, kept: string} */

        $this->checked[] = $user;
    }

    public function containersAndCombinators(mixed $input): void
    {
        $value = Schema::object([
            'tags' => Schema::arrayOf(Schema::string()),
            'labels' => Schema::mapOf(Schema::int()),
            'parent' => Schema::nullable(Schema::int()),
            'nullableChain' => Schema::string()->nullable(),
            'role' => Schema::enum(Role::class),
            'kind' => Schema::literal('creator'),
            'state' => Schema::literals(['pending', 'paid']),
            'refined' => Schema::int()->refine(static fn(int $v): bool => $v > 0, 'positive'),
            'asString' => Schema::int()->transform(static fn(int $v): string => (string) $v, jsonSchema: ['type' => 'string']),
            'meta' => Schema::json(),
            'point' => Schema::tuple([Schema::float(), Schema::float()]),
        ])->parse($input)->orThrow();

        /** @psalm-check-type-exact $value = array{tags: list<string>, labels: array<string, int>, parent: int|null, nullableChain: null|string, role: Role, kind: 'creator', state: 'paid'|'pending', refined: int, asString: numeric-string, meta: mixed, point: list<mixed>} */

        $this->checked[] = $value;
    }

    public function nestedObjectsAndDtos(mixed $input): void
    {
        $value = Schema::object([
            'author' => Schema::object(['name' => Schema::string()]),
            'items' => Schema::arrayOf(Schema::object(['sku' => Schema::string(), 'qty' => Schema::int()->optional()])),
            'user' => Schema::object(['name' => Schema::string(), 'age' => Schema::int()])->into(UserDto::class),
        ])->parseOrThrow($input);

        /** @psalm-check-type-exact $value = array{author: array{name: string}, items: list<array{sku: string, qty?: int}>, user: UserDto} */

        $this->checked[] = $value;
    }

    public function unknownModes(mixed $input): void
    {
        $strip = Schema::object(['a' => Schema::int()], unknown: Unknown::Strip)->parseOrThrow($input);
        /** @psalm-check-type-exact $strip = array{a: int} */
        $this->checked[] = $strip;

        $open = Schema::object(['a' => Schema::int()], unknown: Unknown::Passthrough)->parseOrThrow($input);
        /** @psalm-check-type-exact $open = array{a: int, ...<string, mixed>} */
        $this->checked[] = $open;

        $positional = Schema::object(['a' => Schema::int()], Unknown::Passthrough)->parseOrThrow($input);
        /** @psalm-check-type-exact $positional = array{a: int, ...<string, mixed>} */
        $this->checked[] = $positional;

        $closedAgain = Schema::object(['a' => Schema::int()], unknown: Unknown::Passthrough)->unknown(Unknown::Reject)->parseOrThrow($input);
        /** @psalm-check-type-exact $closedAgain = array{a: int} */
        $this->checked[] = $closedAgain;

        $empty = Schema::object([])->parseOrThrow($input);
        /** @psalm-check-type-exact $empty = array<never, never> */
        $this->checked[] = $empty;

        $emptyOpen = Schema::object([], unknown: Unknown::Passthrough)->parseOrThrow($input);
        /** @psalm-check-type-exact $emptyOpen = array<string, mixed> */
        $this->checked[] = $emptyOpen;
    }

    public function composition(mixed $input): void
    {
        $base = Schema::object([
            'id' => Schema::int(),
            'name' => Schema::string(),
            'email' => Schema::string()->optional(),
        ]);

        $extended = $base->extend(['name' => Schema::int(), 'extra' => Schema::bool()])->parseOrThrow($input);
        /** @psalm-check-type-exact $extended = array{id: int, name: int, email?: string, extra: bool} */
        $this->checked[] = $extended;

        $picked = $base->pick('email', 'id')->parseOrThrow($input);
        /** @psalm-check-type-exact $picked = array{id: int, email?: string} */
        $this->checked[] = $picked;

        $omitted = $base->omit('id')->parseOrThrow($input);
        /** @psalm-check-type-exact $omitted = array{name: string, email?: string} */
        $this->checked[] = $omitted;

        $partial = $base->partial()->parseOrThrow($input);
        /** @psalm-check-type-exact $partial = array{id?: int, name?: string, email?: string} */
        $this->checked[] = $partial;

        $grouped = $base->requireAnyOf('email', 'name')->parseOrThrow($input);
        /** @psalm-check-type-exact $grouped = array{id: int, name: string, email?: string} */
        $this->checked[] = $grouped;
    }

    public function parseJsonAndResults(string $json): void
    {
        $result = Schema::object(['id' => Schema::int()])->parseJson($json);

        if ($result->ok) {
            $value = $result->orThrow();
            /** @psalm-check-type-exact $value = array{id: int} */
            $this->checked[] = $value;
        }
    }

    public function unreadableDeclarationsFallBack(mixed $input, array $properties): void
    {
        /** @var array<string, \Rasuvaeff\Schema\SchemaInterface> $properties */
        $value = Schema::object($properties)->parseOrThrow($input);
        /** @psalm-check-type-exact $value = array<string, mixed> */
        $this->checked[] = $value;

        $property = Schema::string()->default('x');
        $hidden = Schema::object(['p' => $property])->parseOrThrow($input);
        /** @psalm-check-type-exact $hidden = array{p?: string} */
        $this->checked[] = $hidden;
    }

    public function theParsedValueIsTypedOnTheResultToo(mixed $input): void
    {
        $result = Schema::object(['id' => Schema::int()])->parse($input);
        $value = $result->value;
        /** @psalm-check-type-exact $value = array{id: int}|null */
        $this->checked[] = $value;
    }

    /**
     * @return array<string, mixed>
     */
    public function anEventIsItsSchemasWireFormWithoutACast(UserDto $user): array
    {
        $wire = Schema::object(['name' => Schema::string(), 'age' => Schema::int()])->into(UserDto::class)->toWire($user);
        /** @psalm-check-type-exact $wire = array<string, mixed> */
        $this->checked[] = $wire;

        $object = Schema::object(['id' => Schema::int()])->toWire(['id' => 1]);
        /** @psalm-check-type-exact $object = array<string, mixed> */
        $this->checked[] = $object;

        return $wire;
    }
}
