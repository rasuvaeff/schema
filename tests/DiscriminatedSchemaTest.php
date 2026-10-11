<?php

declare(strict_types=1);

namespace Rasuvaeff\Schema\Tests;

use Rasuvaeff\PropertyTesting\Random;
use Rasuvaeff\Schema\Schema;
use Rasuvaeff\Schema\Type\DiscriminatedSchema;
use Rasuvaeff\Schema\Type\ObjectProperty;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(DiscriminatedSchema::class)]
final class DiscriminatedSchemaTest
{
    public function theTagSelectsTheBranch(): void
    {
        $schema = $this->tagged();

        $text = $schema->parse(['kind' => 'text', 'body' => 'hello']);
        $number = $schema->parse(['kind' => 'number', 'value' => 5]);

        Assert::true($text->ok);
        Assert::same($text->value['body'], 'hello');
        Assert::true($number->ok);
        Assert::same($number->value['value'], 5);
    }

    #[DataProvider('rejectionProvider')]
    public function rejectsInvalidInput(mixed $input, string $path, string $expected): void
    {
        $result = $this->tagged()->parse($input);

        Assert::false($result->ok);
        Assert::same($result->errors[0]->toPath(), $path);
        Assert::same($result->errors[0]->message, $expected);
    }

    public static function rejectionProvider(): iterable
    {
        yield 'missing tag' => [
            ['body' => 'hello'],
            '$.kind',
            'Discriminator property "kind" is missing',
        ];

        yield 'unknown tag' => [
            ['kind' => 'image', 'url' => 'https://example.com/a.png'],
            '$.kind',
            'Unknown "kind" discriminator value',
        ];

        yield 'non-scalar tag' => [
            ['kind' => ['text'], 'body' => 'hello'],
            '$.kind',
            'Unknown "kind" discriminator value',
        ];

        yield 'tag mismatching the branch literal' => [
            ['kind' => 'text', 'body' => 42],
            '$.body',
            'Expected string, got int',
        ];
    }

    public function notAnObjectIsItsOwnError(): void
    {
        $result = $this->tagged()->parse('hello');

        Assert::false($result->ok);
        Assert::same($result->errors[0]->message, 'Expected object, got string');
    }

    public function branchErrorsKeepTheirFullPaths(): void
    {
        $schema = Schema::object([
            'message' => $this->tagged(),
        ]);

        $result = $schema->parse(['message' => ['kind' => 'number', 'value' => 'big']]);

        Assert::false($result->ok);
        Assert::same($result->errors[0]->toPath(), '$.message.value');
        Assert::same($result->errors[0]->message, 'Expected int, got string');
    }

    #[DataProvider('authoringErrorProvider')]
    public function rejectsAuthoringErrors(callable $build, string $expected): void
    {
        try {
            $build();
        } catch (\InvalidArgumentException $authoring) {
            Assert::same($authoring->getMessage(), $expected);

            return;
        }

        throw new \LogicException('the constructor was expected to fail');
    }

    public static function authoringErrorProvider(): iterable
    {
        yield 'empty branch list' => [
            static fn(): DiscriminatedSchema => Schema::discriminated('kind', []),
            'Discriminated union requires at least one branch',
        ];

        yield 'empty discriminator name' => [
            static fn(): DiscriminatedSchema => Schema::discriminated('', [
                'a' => Schema::object(['a' => Schema::literal('a')]),
            ]),
            'Discriminator property name must be a non-empty string',
        ];

        yield 'non-object branch' => [
            static fn(): DiscriminatedSchema => Schema::discriminated('kind', ['a' => Schema::int()]),
            'Discriminated branches must be object schemas',
        ];

        yield 'branch without the discriminator property' => [
            static fn(): DiscriminatedSchema => Schema::discriminated('kind', [
                'a' => Schema::object(['body' => Schema::string()]),
            ]),
            'Discriminated branch "a" must declare property "kind" as a required literal equal to the branch key',
        ];

        yield 'branch with a mismatched literal' => [
            static fn(): DiscriminatedSchema => Schema::discriminated('kind', [
                'a' => Schema::object(['kind' => Schema::literal('b')]),
            ]),
            'Discriminated branch "a" must declare property "kind" as a required literal equal to the branch key',
        ];

        yield 'branch with a non-literal discriminator property' => [
            static fn(): DiscriminatedSchema => Schema::discriminated('kind', [
                'a' => Schema::object(['kind' => Schema::string()]),
            ]),
            'Discriminated branch "a" must declare property "kind" as a required literal equal to the branch key',
        ];

        yield 'optional discriminator property' => [
            static fn(): DiscriminatedSchema => Schema::discriminated('kind', [
                'a' => Schema::object(['kind' => Schema::literal('a')->optional()]),
            ]),
            'Discriminated branch "a" must declare property "kind" as a required literal equal to the branch key',
        ];

        yield 'defaulted discriminator property' => [
            static fn(): DiscriminatedSchema => Schema::discriminated('kind', [
                'a' => Schema::object([
                    // default() implies optional(), which the optional
                    // clause already rejects — this isolates hasDefault.
                    'kind' => new ObjectProperty(Schema::literal('a'), optional: false, hasDefault: true, default: 'a'),
                ]),
            ]),
            'Discriminated branch "a" must declare property "kind" as a required literal equal to the branch key',
        ];
    }

    public function intTagsAreSupported(): void
    {
        $schema = Schema::discriminated('version', [
            1 => Schema::object(['version' => Schema::literal(1), 'legacy' => Schema::bool()]),
            2 => Schema::object(['version' => Schema::literal(2), 'modern' => Schema::bool()]),
        ]);

        Assert::true($schema->parse(['version' => 1, 'legacy' => true])->ok);
        Assert::true($schema->parse(['version' => 2, 'modern' => false])->ok);
        Assert::false($schema->parse(['version' => 3])->ok);
        Assert::same($schema->parse(['version' => 3])->errors[0]->message, 'Unknown "version" discriminator value');
    }

    public function generatedValuesAlwaysParse(): void
    {
        $schema = $this->tagged();

        $random = new Random(47);

        for ($i = 0; $i < 25; $i++) {
            Assert::true($schema->parse($schema->arbitrary()->generate($random)->value)->ok);
        }
    }

    public function exportIsOneOfAndDisjointByConstruction(): void
    {
        $export = $this->tagged()->toJsonSchema();

        Assert::same(\count($export->schema['oneOf']), 2);
        Assert::same($export->schema['oneOf'][0]['properties']['kind']['const'] ?? null, 'text');
        Assert::same($export->schema['oneOf'][1]['properties']['kind']['const'] ?? null, 'number');
        Assert::same($export->nonRepresentable, []);
    }

    public function wireUsesTheBranchOfTheValue(): void
    {
        $schema = Schema::object(['message' => $this->tagged()]);

        $wire = $schema->toWire(['message' => ['kind' => 'number', 'value' => 7]]);

        Assert::same($wire, ['message' => ['kind' => 'number', 'value' => 7]]);
    }

    private function tagged(): DiscriminatedSchema
    {
        return Schema::discriminated('kind', [
            'text' => Schema::object([
                'kind' => Schema::literal('text'),
                'body' => Schema::string()->min(1),
            ]),
            'number' => Schema::object([
                'kind' => Schema::literal('number'),
                'value' => Schema::int(),
            ]),
        ]);
    }
}
