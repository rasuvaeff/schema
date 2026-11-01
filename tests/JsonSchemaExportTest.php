<?php

declare(strict_types=1);

namespace Rasuvaeff\Schema\Tests;

use Rasuvaeff\Schema\Combinator\RefineSchema;
use Rasuvaeff\Schema\Combinator\TransformSchema;
use Rasuvaeff\Schema\JsonSchemaExport;
use Rasuvaeff\Schema\NonRepresentable;
use Rasuvaeff\Schema\NonRepresentableException;
use Rasuvaeff\Schema\Schema;
use Rasuvaeff\Schema\Tests\Support\Role;
use Rasuvaeff\Schema\Type\ArraySchema;
use Rasuvaeff\Schema\Type\DiscriminatedSchema;
use Rasuvaeff\Schema\Type\EnumSchema;
use Rasuvaeff\Schema\Type\LiteralSchema;
use Rasuvaeff\Schema\Type\MapSchema;
use Rasuvaeff\Schema\Type\NullableSchema;
use Rasuvaeff\Schema\Type\ObjectSchema;
use Rasuvaeff\Schema\Type\TupleSchema;
use Rasuvaeff\Schema\Type\UnionSchema;
use Testo\Assert;
use Testo\Assert\ExpectException;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(JsonSchemaExport::class)]
#[Covers(NonRepresentableException::class)]
#[Covers(Schema::class)]
#[Covers(NonRepresentable::class)]
#[Covers(RefineSchema::class)]
#[Covers(TransformSchema::class)]
#[Covers(NullableSchema::class)]
#[Covers(UnionSchema::class)]
#[Covers(LiteralSchema::class)]
#[Covers(TupleSchema::class)]
#[Covers(DiscriminatedSchema::class)]
#[Covers(EnumSchema::class)]
#[Covers(ArraySchema::class)]
#[Covers(MapSchema::class)]
#[Covers(ObjectSchema::class)]
final class JsonSchemaExportTest
{
    public function stringExportsBoundsPatternAndFormat(): void
    {
        $export = Schema::string()->min(2)->max(5)->format(\Rasuvaeff\Schema\Format::Email)->toJsonSchema();

        Assert::same($export->schema, [
            'type' => 'string',
            'minLength' => 2,
            'maxLength' => 5,
            'format' => 'email',
        ]);
        Assert::same($export->nonRepresentable, []);
    }

    public function intExportsBounds(): void
    {
        $export = Schema::int()->min(1)->max(10)->toJsonSchema();

        Assert::same($export->schema, [
            'type' => 'integer',
            'minimum' => 1,
            'maximum' => 10,
        ]);
    }

    public function boolExports(): void
    {
        Assert::same(Schema::bool()->toJsonSchema()->schema, ['type' => 'boolean']);
    }

    public function literalExportsConst(): void
    {
        Assert::same(Schema::literal('pending')->toJsonSchema()->schema, ['const' => 'pending']);
    }

    public function enumExportsBackedValues(): void
    {
        $export = Schema::enum(Role::class)->toJsonSchema();

        Assert::same($export->schema, [
            'type' => 'string',
            'enum' => ['admin', 'editor', 'viewer'],
        ]);
    }

    public function jsonExportsAnEmptyFragment(): void
    {
        Assert::same(Schema::json()->toJsonSchema()->schema, []);
    }

    public function arrayExportsItemsAndCounts(): void
    {
        $export = Schema::arrayOf(Schema::int())->minCount(1)->maxCount(3)->toJsonSchema();

        Assert::same($export->schema, [
            'type' => 'array',
            'items' => ['type' => 'integer'],
            'minItems' => 1,
            'maxItems' => 3,
        ]);
    }

    public function mapExportsAdditionalProperties(): void
    {
        $export = Schema::mapOf(Schema::int())->toJsonSchema();

        Assert::same($export->schema, [
            'type' => 'object',
            'additionalProperties' => ['type' => 'integer'],
        ]);
    }

    public function objectExportsRequiredOptionalAndDefault(): void
    {
        $export = Schema::object([
            'name' => Schema::string(),
            'bio' => Schema::string()->optional(),
            'role' => Schema::enum(Role::class)->default('admin'),
        ])->toJsonSchema();

        Assert::same($export->schema, [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string'],
                'bio' => ['type' => 'string'],
                'role' => [
                    'type' => 'string',
                    'enum' => ['admin', 'editor', 'viewer'],
                    'default' => 'admin',
                ],
            ],
            'additionalProperties' => false,
            'required' => ['name'],
        ]);
    }

    public function nullableExportsAnyOfNull(): void
    {
        $export = Schema::nullable(Schema::int())->toJsonSchema();

        Assert::same($export->schema, [
            'anyOf' => [
                ['type' => 'null'],
                ['type' => 'integer'],
            ],
        ]);
    }

    public function unionExportsAnyOfInDeclarationOrder(): void
    {
        $export = Schema::union([Schema::int(), Schema::string()])->toJsonSchema();

        Assert::same($export->schema, [
            'anyOf' => [
                ['type' => 'integer'],
                ['type' => 'string'],
            ],
        ]);
    }

    public function refineExportsTheInnerSchemaAsAHonestSuperset(): void
    {
        $export = Schema::int()->refine(
            static fn(int $value): bool => $value % 2 === 0,
            'even',
        )->toJsonSchema();

        Assert::same($export->schema, ['type' => 'integer']);
        Assert::same(\count($export->nonRepresentable), 1);
        Assert::same((string) $export->nonRepresentable[0], 'refine @ $');
    }

    public function nonRepresentableEntriesCarryThePathThroughContainers(): void
    {
        $schema = Schema::object([
            'tags' => Schema::arrayOf(
                Schema::int()->refine(static fn(int $value): bool => $value > 0, 'positive'),
            ),
        ]);

        $export = $schema->toJsonSchema();

        Assert::same(\count($export->nonRepresentable), 1);
        Assert::same((string) $export->nonRepresentable[0], 'refine @ $.tags');
    }

    #[ExpectException(NonRepresentableException::class)]
    public function transformWithoutACodecThrowsInStrictMode(): void
    {
        Schema::int()->transform(static fn(int $value): string => (string) $value)->toJsonSchema();
    }

    public function transformWithoutACodecDowngradesInLenientMode(): void
    {
        $export = Schema::int()
            ->transform(static fn(int $value): string => (string) $value)
            ->toJsonSchema(lenient: true);

        Assert::same($export->schema, []);
        Assert::same((string) $export->nonRepresentable[0], 'transform @ $');
    }

    public function transformWithACodecExportsTheDeclaredFragment(): void
    {
        $export = Schema::int()->transform(
            static fn(int $value): string => (string) $value,
            jsonSchema: ['type' => 'string'],
        )->toJsonSchema();

        Assert::same($export->schema, ['type' => 'string']);
        Assert::same($export->nonRepresentable, []);
    }

    public function assertFullyRepresentableThrowsListingEveryEntry(): void
    {
        $export = Schema::object([
            'a' => Schema::int()->refine(static fn(int $value): bool => true, 'refined a'),
            'b' => Schema::int()->transform(
                static fn(int $value): string => (string) $value,
            ),
        ])->toJsonSchema(lenient: true);

        Assert::same(\count($export->nonRepresentable), 2);

        try {
            $export->assertFullyRepresentable();

            Assert::true(actual: false, message: 'assertFullyRepresentable must throw');
        } catch (NonRepresentableException $exception) {
            Assert::string($exception->getMessage())->contains('refine @ $.a');
            Assert::string($exception->getMessage())->contains('transform @ $.b');
        }
    }

    public function unionExportsMembersInOrderAndKeepsTheirEntries(): void
    {
        $export = Schema::union([
            Schema::int()->refine(static fn(int $value): bool => $value > 0, 'positive'),
            Schema::string(),
        ])->toJsonSchema();

        Assert::same($export->schema, ['anyOf' => [['type' => 'integer'], ['type' => 'string']]]);
        Assert::same(\count($export->nonRepresentable), 1);
        Assert::same((string) $export->nonRepresentable[0], 'refine @ $');
    }

    public function nullableExportsNullBesideTheInnerFragment(): void
    {
        $export = Schema::nullable(
            Schema::int()->refine(static fn(int $value): bool => true, 'any'),
        )->toJsonSchema();

        Assert::same($export->schema, ['anyOf' => [['type' => 'null'], ['type' => 'integer']]]);
        Assert::same((string) $export->nonRepresentable[0], 'refine @ $');
    }

    public function literalExportsItsConstant(): void
    {
        Assert::same(Schema::literal('a')->toJsonSchema()->schema, ['const' => 'a']);
        Assert::same(Schema::literal(3)->toJsonSchema()->schema, ['const' => 3]);
        Assert::same(Schema::literal(null)->toJsonSchema()->schema, ['const' => null]);
    }

    public function nestedRefinementsAreAllRecorded(): void
    {
        $export = Schema::int()
            ->refine(static fn(int $value): bool => true, 'inner')
            ->refine(static fn(int $value): bool => true, 'outer')
            ->toJsonSchema();

        Assert::same(\count($export->nonRepresentable), 2);
    }

    public function entriesCarryTheirPathThroughTuplesUnionsAndDiscriminatedBranches(): void
    {
        $transform = Schema::int()->transform(static fn(int $value): string => (string) $value);

        $tuple = Schema::tuple([Schema::string(), $transform])->toJsonSchema(lenient: true);
        Assert::same((string) $tuple->nonRepresentable[0], 'transform @ $[1]');

        $union = Schema::union([Schema::string(), $transform])->toJsonSchema(lenient: true);
        Assert::same((string) $union->nonRepresentable[0], 'transform @ $');

        $discriminated = Schema::discriminated('kind', [
            'a' => Schema::object(['kind' => Schema::literal('a'), 'n' => $transform]),
        ])->toJsonSchema(lenient: true);
        Assert::same((string) $discriminated->nonRepresentable[0], 'transform @ $.a.n');
    }

    public function assertFullyRepresentableJoinsTheEntriesWithASemicolon(): void
    {
        $export = JsonSchemaExport::of([], [
            new NonRepresentable(['a'], 'refine'),
            new NonRepresentable([], 'transform'),
        ]);

        try {
            $export->assertFullyRepresentable();
        } catch (NonRepresentableException $exception) {
            Assert::same($exception->getMessage(), 'refine @ $.a; transform @ $');

            return;
        }

        throw new \LogicException('assertFullyRepresentable was expected to throw');
    }

    public function aFullyRepresentableExportDoesNotThrow(): void
    {
        JsonSchemaExport::of(['type' => 'string'])->assertFullyRepresentable();

        Assert::same(JsonSchemaExport::of(['type' => 'string'])->nonRepresentable, []);
    }
}
