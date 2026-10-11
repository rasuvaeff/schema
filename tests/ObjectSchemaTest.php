<?php

declare(strict_types=1);

namespace Rasuvaeff\Schema\Tests;

use Rasuvaeff\PropertyTesting\Random;
use Rasuvaeff\Schema\ParseError;
use Rasuvaeff\Schema\ParseResult;
use Rasuvaeff\Schema\Schema;
use Rasuvaeff\Schema\SchemaInterface;
use Rasuvaeff\Schema\Tests\Support\Role;
use Rasuvaeff\Schema\Type\ObjectSchema;
use Rasuvaeff\Understudy\Arg;
use Rasuvaeff\Understudy\Understudy;
use Testo\Assert;
use Testo\Assert\ExpectException;
use Testo\Codecov\Covers;
use Testo\Test;

use function Rasuvaeff\Understudy\when;

#[Test]
#[Covers(ObjectSchema::class)]
final class ObjectSchemaTest
{
    public function parsesDeclaredProperties(): void
    {
        $schema = Schema::object([
            'name' => Schema::string()->min(1),
            'age' => Schema::int(),
        ]);

        $result = $schema->parse(['name' => 'ada', 'age' => 36]);

        Assert::true($result->ok);
        Assert::same($result->value, ['name' => 'ada', 'age' => 36]);
    }

    public function rejectsNonArrayInput(): void
    {
        $result = Schema::object(['name' => Schema::string()])->parse('nope');

        Assert::false($result->ok);
        Assert::same($result->errors[0]->message, 'Expected object, got string');
    }

    public function requiredPropertyMissingIsAnErrorAtItsPath(): void
    {
        $schema = Schema::object(['name' => Schema::string()]);

        $result = $schema->parse([]);

        Assert::false($result->ok);
        Assert::same($result->errors[0]->toPath(), '$.name');
        Assert::same($result->errors[0]->message, 'Required property "name" is missing');
    }

    public function anAbsentOptionalKeyStaysAbsent(): void
    {
        $schema = Schema::object([
            'name' => Schema::string(),
            'bio' => Schema::string()->optional(),
        ]);

        $result = $schema->parse(['name' => 'ada']);

        Assert::true($result->ok);
        Assert::false(\array_key_exists('bio', $result->value));
    }

    public function aDefaultedKeyIsAlwaysPresentAndNormalized(): void
    {
        $schema = Schema::object([
            'name' => Schema::string(),
            'role' => Schema::enum(Role::class)->default('admin'),
        ]);

        $result = $schema->parse(['name' => 'ada']);

        Assert::true($result->ok);
        Assert::same($result->value['role'], Role::Admin);
    }

    public function unknownPropertiesAreRejectedWithTheirName(): void
    {
        $schema = Schema::object(['name' => Schema::string()]);

        $result = $schema->parse(['name' => 'ada', 'extra' => 1]);

        Assert::false($result->ok);
        Assert::same($result->errors[0]->message, 'Unknown property "extra"');
        Assert::same($result->errors[0]->toPath(), '$.extra');
    }

    public function anIntegerKeyIsReportedAsUnknown(): void
    {
        $schema = Schema::object(['name' => Schema::string()]);

        $result = $schema->parse(['name' => 'ada', 0 => 'x']);

        Assert::false($result->ok);
        Assert::same($result->errors[0]->message, 'Unknown property "0"');
    }

    public function additionalPropertiesAllowUnknownKeys(): void
    {
        $schema = Schema::object(['name' => Schema::string()])->additionalProperties();

        $result = $schema->parse(['name' => 'ada', 'extra' => 'kept']);

        Assert::true($result->ok);
        Assert::same($result->value, ['name' => 'ada', 'extra' => 'kept']);
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsAnEmptyPropertyName(): void
    {
        Schema::object(['' => Schema::string()]);
    }

    public function anEmptyObjectAcceptsAnEmptyArray(): void
    {
        $result = Schema::object([])->parse([]);

        Assert::true($result->ok);
        Assert::same($result->value, []);
    }

    public function childErrorsArePrefixedWithThePropertyName(): void
    {
        $child = Understudy::for(SchemaInterface::class);
        when(static fn(): ParseResult => $child->parse(Arg::any()))
            ->returns(ParseResult::failed([new ParseError([], 'boom')]));

        $schema = Schema::object(['inner' => $child]);

        $result = $schema->parse(['inner' => 'anything']);

        Assert::false($result->ok);
        Assert::same($result->errors[0]->toPath(), '$.inner');
        Assert::same($result->errors[0]->message, 'boom');
    }

    public function generatedValuesAlwaysParse(): void
    {
        $schema = Schema::object([
            'name' => Schema::string()->min(1)->max(8),
            'age' => Schema::int()->min(0)->max(3),
            'role' => Schema::enum(Role::class)->default('viewer'),
            'bio' => Schema::string()->optional(),
            'tags' => Schema::arrayOf(Schema::string()->min(1))->maxCount(3),
        ]);

        $random = new Random(23);

        for ($i = 0; $i < 25; $i++) {
            $result = $schema->parse($schema->arbitrary()->generate($random)->value);

            Assert::true($result->ok, \sprintf('value #%d failed to parse', $i));
            Assert::same(\array_key_exists('role', $result->value), expected: true);
        }
    }

    public function stripDropsUnknownKeysAndKeepsTheKnownOnes(): void
    {
        $schema = Schema::object([
            'name' => Schema::string()->min(1),
        ])->strip();

        $result = $schema->parse(['name' => 'ann', 'unknown' => 'x', 'another' => 42]);

        Assert::true($result->ok);
        Assert::same($result->value, ['name' => 'ann']);
    }

    public function stripStillReportsErrorsOfTheKnownKeys(): void
    {
        $schema = Schema::object([
            'name' => Schema::string()->min(1),
        ])->strip();

        $result = $schema->parse(['name' => '', 'unknown' => 'x']);

        Assert::false($result->ok);
        Assert::same(\count($result->errors), 1);
        Assert::same($result->errors[0]->toPath(), '$.name');
    }

    public function additionalPropertiesAfterStripSwitchesTheModeBack(): void
    {
        $passthrough = Schema::object(['name' => Schema::string()])->strip()->additionalProperties();

        Assert::true($passthrough->additionalProperties);
        Assert::false($passthrough->strip);
        Assert::true($passthrough->parse(['name' => 'ann', 'extra' => 1])->ok);
        Assert::same($passthrough->parse(['name' => 'ann', 'extra' => 1])->value['extra'], 1);

        $strict = Schema::object(['name' => Schema::string()])->strip()->additionalProperties(false);

        Assert::false($strict->additionalProperties);
        Assert::false($strict->strip);
        Assert::false($strict->parse(['name' => 'ann', 'extra' => 1])->ok);
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function stripAndAdditionalPropertiesAreMutuallyExclusive(): void
    {
        new ObjectSchema(['name' => Schema::string()], additionalProperties: true, strip: true);
    }

    public function stripExportsAdditionalPropertiesTrueAndRecordsTheDrop(): void
    {
        $export = Schema::object(['name' => Schema::string()])->strip()->toJsonSchema();

        Assert::same($export->schema['additionalProperties'], true);
        Assert::same($export->nonRepresentable[0]->construct, 'strip');
        Assert::same($export->nonRepresentable[0]->toPath(), '$');
    }

    public function stripWireCarriesOnlyKnownKeys(): void
    {
        $schema = Schema::object(['name' => Schema::string()])->strip();

        Assert::same($schema->toWire(['name' => 'ann']), ['name' => 'ann']);
    }

    public function stripIsVisibleOnTheInstance(): void
    {
        $schema = Schema::object(['name' => Schema::string()])->strip();

        Assert::true($schema->strip);
        Assert::false($schema->additionalProperties);
    }
}
