<?php

declare(strict_types=1);

namespace Rasuvaeff\Schema\Tests;

use Rasuvaeff\Schema\JsonSchemaExport;
use Rasuvaeff\Schema\NonRepresentableException;
use Rasuvaeff\Schema\Schema;
use Rasuvaeff\Schema\Tests\Support\Role;
use Testo\Assert;
use Testo\Assert\ExpectException;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(JsonSchemaExport::class)]
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

            Assert::true(false, 'assertFullyRepresentable must throw');
        } catch (NonRepresentableException $exception) {
            Assert::string($exception->getMessage())->contains('refine @ $.a');
            Assert::string($exception->getMessage())->contains('transform @ $.b');
        }
    }
}
