<?php

declare(strict_types=1);

namespace Rasuvaeff\Schema\Tests;

use Rasuvaeff\Schema\Internal\Json;
use Rasuvaeff\Schema\Schema;
use Rasuvaeff\Schema\SchemaType;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(SchemaType::class)]
#[Covers(Json::class)]
#[Covers(Schema::class)]
final class SchemaTypeTest
{
    public function parseJsonDecodesAndParses(): void
    {
        $schema = Schema::object(['id' => Schema::int()]);

        Assert::same($schema->parseJson('{"id": 5}')->value, ['id' => 5]);
        Assert::same($schema->parseJson('{"id": "5"}')->errors[0]->toPath(), '$.id');
    }

    public function parseJsonReportsInvalidJsonAtTheRoot(): void
    {
        $result = Schema::object(['id' => Schema::int()])->parseJson('{"id": ');

        Assert::false($result->ok);
        Assert::same(\count($result->errors), 1);
        Assert::same($result->errors[0]->toPath(), '$');
        // PHP 8.6 adds the location ("Syntax error near location 1:8"):
        // a position, never the input.
        Assert::true(\str_starts_with($result->errors[0]->message, 'Invalid JSON (Syntax error'));
    }

    public function parseJsonKeepsADecodedNull(): void
    {
        Assert::true(Schema::nullable(Schema::int())->parseJson('null')->ok);
        Assert::same(Schema::int()->parseJson('null')->errors[0]->message, 'Expected int, got null');
    }

    public function parseJsonGoesThroughAProperty(): void
    {
        Assert::same(Schema::int()->optional()->parseJson('7')->value, 7);
    }

    public function optionalMarksThePropertyOptionalWithoutADefault(): void
    {
        $property = Schema::int()->optional();

        Assert::true($property->optional);
        Assert::false($property->hasDefault);
    }
}
