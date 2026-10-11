<?php

declare(strict_types=1);

namespace Rasuvaeff\Schema\Tests;

use Rasuvaeff\PropertyTesting\Random;
use Rasuvaeff\Schema\Combinator\IntoSchema;
use Rasuvaeff\Schema\Schema;
use Rasuvaeff\Schema\SchemaInterface;
use Rasuvaeff\Schema\Tests\Support\Role;
use Rasuvaeff\Schema\Tests\Support\UserDto;
use Rasuvaeff\Schema\Tests\Support\UserRecord;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(IntoSchema::class)]
final class IntoSchemaTest
{
    public function hydratesParsedPropertiesIntoTheDto(): void
    {
        $schema = $this->user();

        $result = $schema->parse([
            'name' => 'ada',
            'age' => 37,
            'role' => 'admin',
        ]);

        Assert::true($result->ok);
        Assert::instanceOf($result->value, UserDto::class);
        Assert::same($result->value->name, 'ada');
        Assert::same($result->value->age, 37);
        Assert::same($result->value->role, Role::Admin);
        Assert::same($result->value->bio, '');
    }

    public function parseErrorsKeepTheirPaths(): void
    {
        $result = $this->user()->parse(['name' => '', 'age' => 'no', 'role' => 'admin']);

        Assert::false($result->ok);
        Assert::same($result->errors[0]->toPath(), '$.name');
        Assert::same($result->errors[1]->toPath(), '$.age');
    }

    public function aFailingConstructorIsAparseError(): void
    {
        $result = $this->userWithAge(Schema::int()->positive())->parse([
            'name' => 'ada',
            'age' => 200,
            'role' => 'admin',
        ]);

        Assert::false($result->ok);
        Assert::same(
            $result->errors[0]->message,
            'Hydration into Rasuvaeff\Schema\Tests\Support\UserDto failed (InvalidArgumentException)',
        );
    }

    #[DataProvider('authoringErrorProvider')]
    public function rejectsBadTargetsEagerly(string $message, callable $build): void
    {
        try {
            $build();
        } catch (\InvalidArgumentException $authoring) {
            Assert::string($authoring->getMessage())->contains($message);

            return;
        }

        throw new \LogicException('the constructor was expected to fail');
    }

    public static function authoringErrorProvider(): iterable
    {
        yield 'unknown class' => [
            'does not exist',
            static fn(): IntoSchema => Schema::object([])->into('No\Such\Class'),
        ];

        yield 'not instantiable' => [
            'is not instantiable',
            static fn(): IntoSchema => Schema::object([])->into(\DateTimeInterface::class),
        ];
    }

    public function generatedValuesHydrateAndParse(): void
    {
        $schema = $this->user();

        $random = new Random(61);

        for ($i = 0; $i < 25; $i++) {
            $dto = $schema->arbitrary()->generate($random)->value;

            Assert::instanceOf($dto, UserDto::class);
            Assert::true($schema->parse(['name' => $dto->name, 'age' => $dto->age, 'role' => $dto->role->value])->ok);
        }
    }

    public function exportIsTheObjectSchemasOwn(): void
    {
        $export = $this->user()->toJsonSchema();

        Assert::same($export->schema['type'], 'object');
        Assert::same($export->schema['properties']['role']['enum'], ['admin', 'editor', 'viewer']);
        Assert::same($export->schema['properties']['bio']['default'], '');
    }

    public function wireWalksTheObjectSchemaOverTheDto(): void
    {
        $schema = $this->user();
        $dto = $schema->parseOrThrow(['name' => 'ada', 'age' => 37, 'role' => Role::Admin]);

        Assert::same(
            $schema->toWire($dto),
            ['name' => 'ada', 'age' => 37, 'role' => 'admin', 'bio' => ''],
        );

        $nested = Schema::object(['user' => $this->user()]);

        Assert::same(
            $nested->toWire(['user' => $dto]),
            ['user' => ['name' => 'ada', 'age' => 37, 'role' => 'admin', 'bio' => '']],
        );
    }

    public function stripSettlesBeforeHydration(): void
    {
        $schema = Schema::object([
            'name' => Schema::string()->min(1),
        ])->strip()->into(UserRecord::class);

        $result = $schema->parse(['name' => 'ada', 'unknown' => 'dropped']);

        Assert::true($result->ok);
        Assert::instanceOf($result->value, UserRecord::class);
        Assert::same($result->value->name, 'ada');
    }

    /**
     * The age bound matches the DTO's own post-condition, so everything
     * the generator produces hydrates — a DTO invariant stricter than the
     * schema would break the generator (see aFailingConstructorIsAparseError
     * for the parse side of that mismatch).
     */
    private function user(): IntoSchema
    {
        return $this->userWithAge(Schema::int()->positive()->max(150));
    }

    private function userWithAge(SchemaInterface $age): IntoSchema
    {
        return Schema::object([
            'name' => Schema::string()->min(1),
            'age' => $age,
            'role' => Schema::enum(Role::class),
            'bio' => Schema::string()->default(''),
        ])->into(UserDto::class);
    }
}
