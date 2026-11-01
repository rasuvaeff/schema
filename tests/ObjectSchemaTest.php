<?php

declare(strict_types=1);

namespace Rasuvaeff\Schema\Tests;

use Rasuvaeff\PropertyTesting\Random;
use Rasuvaeff\Schema\Internal\Wire;
use Rasuvaeff\Schema\JsonSchemaExport;
use Rasuvaeff\Schema\ParseError;
use Rasuvaeff\Schema\ParseResult;
use Rasuvaeff\Schema\Schema;
use Rasuvaeff\Schema\SchemaInterface;
use Rasuvaeff\Schema\Tests\Support\Role;
use Rasuvaeff\Schema\Type\ObjectSchema;
use Rasuvaeff\Schema\Unknown;
use Rasuvaeff\Understudy\Arg;
use Rasuvaeff\Understudy\Understudy;
use Testo\Assert;
use Testo\Assert\ExpectException;
use Testo\Codecov\Covers;
use Testo\Test;

use function Rasuvaeff\Understudy\when;

#[Test]
#[Covers(ObjectSchema::class)]
#[Covers(ParseError::class)]
#[Covers(ParseResult::class)]
#[Covers(Schema::class)]
#[Covers(Unknown::class)]
#[Covers(Wire::class)]
#[Covers(JsonSchemaExport::class)]
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

    public function unknownPropertiesAreRejectedAtTheirPath(): void
    {
        $schema = Schema::object(['name' => Schema::string()]);

        $result = $schema->parse(['name' => 'ada', 'extra' => 1]);

        Assert::false($result->ok);
        Assert::same($result->errors[0]->message, 'Unknown property');
        Assert::same($result->errors[0]->toPath(), '$.extra');
    }

    public function anIntegerKeyIsReportedAsUnknown(): void
    {
        $schema = Schema::object(['name' => Schema::string()]);

        $result = $schema->parse(['name' => 'ada', 0 => 'x']);

        Assert::false($result->ok);
        Assert::same($result->errors[0]->message, 'Unknown property');
    }

    public function passthroughKeepsUnknownKeysInTheResult(): void
    {
        $schema = Schema::object(['name' => Schema::string()], unknown: Unknown::Passthrough);

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
        ], unknown: Unknown::Strip);

        $result = $schema->parse(['name' => 'ann', 'unknown' => 'x', 'another' => 42]);

        Assert::true($result->ok);
        Assert::same($result->value, ['name' => 'ann']);
    }

    public function stripStillReportsErrorsOfTheKnownKeys(): void
    {
        $schema = Schema::object([
            'name' => Schema::string()->min(1),
        ])->unknown(Unknown::Strip);

        $result = $schema->parse(['name' => '', 'unknown' => 'x']);

        Assert::false($result->ok);
        Assert::same(\count($result->errors), 1);
        Assert::same($result->errors[0]->toPath(), '$.name');
    }

    public function unknownSwitchesTheModeAndKeepsTheRest(): void
    {
        $base = Schema::object(['name' => Schema::string(), 'nick' => Schema::string()->optional()])->requireAnyOf('nick');

        $passthrough = $base->unknown(Unknown::Strip)->unknown(Unknown::Passthrough);

        Assert::same($passthrough->unknown, Unknown::Passthrough);
        Assert::same($passthrough->anyOf, [['nick']]);
        Assert::same(\array_keys($passthrough->properties), ['name', 'nick']);
        Assert::same($passthrough->parse(['name' => 'ann', 'nick' => 'a', 'extra' => 1])->value['extra'], 1);

        $strict = $passthrough->unknown(Unknown::Reject);

        Assert::same($strict->unknown, Unknown::Reject);
        Assert::false($strict->parse(['name' => 'ann', 'nick' => 'a', 'extra' => 1])->ok);
    }

    public function rejectIsTheDefault(): void
    {
        Assert::same(Schema::object([])->unknown, Unknown::Reject);
        Assert::same((new ObjectSchema([]))->unknown, Unknown::Reject);
    }

    public function stripExportsAdditionalPropertiesTrueAndRecordsTheDrop(): void
    {
        $export = Schema::object(['name' => Schema::string()], unknown: Unknown::Strip)->toJsonSchema();

        Assert::same($export->schema['additionalProperties'], expected: true);
        Assert::same($export->nonRepresentable[0]->construct, 'strip');
        Assert::same($export->nonRepresentable[0]->toPath(), '$');
    }

    public function passthroughExportsAdditionalPropertiesTrueWithoutAnEntry(): void
    {
        $export = Schema::object(['name' => Schema::string()], unknown: Unknown::Passthrough)->toJsonSchema();

        Assert::same($export->schema['additionalProperties'], expected: true);
        Assert::same($export->nonRepresentable, []);
    }

    public function stripWireCarriesOnlyKnownKeys(): void
    {
        $schema = Schema::object(['name' => Schema::string()], unknown: Unknown::Strip);

        Assert::same($schema->toWire(['name' => 'ann', 'extra' => 1]), ['name' => 'ann']);
    }

    public function passthroughWireKeepsUnknownKeys(): void
    {
        $schema = Schema::object(['name' => Schema::string()], unknown: Unknown::Passthrough);

        Assert::same($schema->toWire(['name' => 'ann', 'extra' => 1]), ['name' => 'ann', 'extra' => 1]);
    }

    public function requireAnyOfRejectsAnInputWithoutAnyMember(): void
    {
        $schema = Schema::object([
            'email' => Schema::string()->optional(),
            'phone' => Schema::string()->optional(),
        ])->requireAnyOf('email', 'phone');

        $result = $schema->parse([]);

        Assert::false($result->ok);
        Assert::same(\count($result->errors), 1);
        Assert::same($result->errors[0]->toPath(), '$');
        Assert::same($result->errors[0]->message, 'At least one of "email", "phone" is required');
        Assert::true($schema->parse(['phone' => '1'])->ok);
        Assert::true($schema->parse(['email' => 'a', 'phone' => '1'])->ok);
    }

    public function requireAnyOfCountsAPresentButInvalidMemberAsPresent(): void
    {
        $schema = Schema::object(['email' => Schema::string()->optional()])->requireAnyOf('email');

        $result = $schema->parse(['email' => 5]);

        Assert::same(\count($result->errors), 1);
        Assert::same($result->errors[0]->toPath(), '$.email');
    }

    public function requireAnyOfIsSatisfiedByADefault(): void
    {
        $schema = Schema::object([
            'email' => Schema::string()->optional(),
            'lang' => Schema::string()->default('en'),
        ])->requireAnyOf('email', 'lang');

        Assert::true($schema->parse([])->ok);
    }

    public function requireAnyOfGroupsAccumulate(): void
    {
        $schema = Schema::object([
            'a' => Schema::int()->optional(),
            'b' => Schema::int()->optional(),
            'c' => Schema::int()->optional(),
        ])->requireAnyOf('a', 'b')->requireAnyOf('c');

        Assert::same($schema->anyOf, [['a', 'b'], ['c']]);
        Assert::same(\count($schema->parse([])->errors), 2);
        Assert::same($schema->parse(['a' => 1])->errors[0]->message, 'At least one of "c" is required');
        Assert::true($schema->parse(['b' => 1, 'c' => 2])->ok);
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function requireAnyOfRejectsAnUndeclaredName(): void
    {
        Schema::object(['a' => Schema::int()])->requireAnyOf('nope');
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function anEmptyGroupIsRejected(): void
    {
        new ObjectSchema(['a' => Schema::int()], anyOf: [[]]);
    }

    public function requireAnyOfGeneratorSatisfiesTheGroupsConstructively(): void
    {
        $schema = Schema::object([
            'a' => Schema::int()->optional(),
            'b' => Schema::int()->optional(),
            'c' => Schema::int()->optional(),
            'd' => Schema::int()->optional(),
        ])->requireAnyOf('a', 'b')->requireAnyOf('c', 'd');

        $random = new Random(7);

        for ($i = 0; $i < 50; $i++) {
            $value = $schema->arbitrary()->generate($random)->value;

            Assert::true($schema->parse($value)->ok, \sprintf('value #%d failed to parse', $i));
            Assert::same(\array_keys($value), \array_values(\array_intersect(['a', 'b', 'c', 'd'], \array_keys($value))));
        }
    }

    public function requireAnyOfExportsAnyOfRequired(): void
    {
        $one = Schema::object(['a' => Schema::int()->optional(), 'b' => Schema::int()->optional()])
            ->requireAnyOf('a', 'b')
            ->toJsonSchema()->schema;

        Assert::same($one['anyOf'], [['required' => ['a']], ['required' => ['b']]]);
        Assert::false(\array_key_exists('allOf', $one));

        $two = Schema::object(['a' => Schema::int()->optional(), 'b' => Schema::int()->optional()])
            ->requireAnyOf('a')
            ->requireAnyOf('b')
            ->toJsonSchema()->schema;

        Assert::same($two['allOf'], [
            ['anyOf' => [['required' => ['a']]]],
            ['anyOf' => [['required' => ['b']]]],
        ]);
        Assert::false(\array_key_exists('anyOf', $two));
        Assert::false(\array_key_exists('anyOf', Schema::object([])->toJsonSchema()->schema));
    }

    public function extendAddsAndReplacesProperties(): void
    {
        $base = Schema::object(['id' => Schema::int(), 'name' => Schema::string()], unknown: Unknown::Strip)
            ->requireAnyOf('name');

        $extended = $base->extend(['name' => Schema::string()->min(2), 'email' => Schema::string()->optional()]);

        Assert::same(\array_keys($extended->properties), ['id', 'name', 'email']);
        Assert::same($extended->unknown, Unknown::Strip);
        Assert::same($extended->anyOf, [['name']]);
        Assert::false($extended->parse(['id' => 1, 'name' => 'a'])->ok);
        Assert::true($extended->parse(['id' => 1, 'name' => 'ab'])->ok);
    }

    public function pickKeepsTheNamedPropertiesInDeclarationOrder(): void
    {
        $base = Schema::object([
            'a' => Schema::int()->optional(),
            'b' => Schema::int()->optional(),
            'c' => Schema::int()->optional(),
        ], unknown: Unknown::Passthrough)->requireAnyOf('a', 'b')->requireAnyOf('c');

        $picked = $base->pick('c', 'a');

        Assert::same(\array_keys($picked->properties), ['a', 'c']);
        Assert::same($picked->unknown, Unknown::Passthrough);
        Assert::same($picked->anyOf, [['a'], ['c']]);
        Assert::same($base->pick('b')->anyOf, [['b']]);
    }

    public function omitDropsTheNamedPropertiesAndEmptiedGroups(): void
    {
        $base = Schema::object([
            'a' => Schema::int()->optional(),
            'b' => Schema::int()->optional(),
            'c' => Schema::int()->optional(),
        ])->requireAnyOf('a', 'b')->requireAnyOf('c');

        $omitted = $base->omit('c', 'b');

        Assert::same(\array_keys($omitted->properties), ['a']);
        Assert::same($omitted->anyOf, [['a']]);
        Assert::same($omitted->unknown, Unknown::Reject);
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function pickRejectsAnUndeclaredName(): void
    {
        Schema::object(['a' => Schema::int()])->pick('a', 'nope');
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function omitRejectsAnUndeclaredName(): void
    {
        Schema::object(['a' => Schema::int()])->omit('nope');
    }

    public function partialMakesEveryPropertyOptionalAndKeepsDefaults(): void
    {
        $partial = Schema::object([
            'id' => Schema::int(),
            'lang' => Schema::string()->default('en'),
        ], unknown: Unknown::Strip)->partial();

        Assert::same($partial->parse([])->value, ['lang' => 'en']);
        Assert::same($partial->parse(['id' => 3, 'x' => 1])->value, ['id' => 3, 'lang' => 'en']);
        Assert::same($partial->unknown, Unknown::Strip);
        Assert::true($partial->properties['id']->optional);
    }

    public function namedVariadicNamesStayAList(): void
    {
        $schema = Schema::object([
            'a' => Schema::int()->optional(),
            'b' => Schema::int()->optional(),
            'c' => Schema::int()->optional(),
        ]);

        Assert::same($schema->requireAnyOf('a', other: 'b')->anyOf, [['a', 'b']]);
        Assert::same(\array_keys($schema->pick('a', other: 'c')->properties), ['a', 'c']);
        Assert::same(\array_keys($schema->omit('a', other: 'c')->properties), ['b']);
    }

    public function omitDropsEveryNamedProperty(): void
    {
        $schema = Schema::object([
            'a' => Schema::int(),
            'b' => Schema::int(),
            'c' => Schema::int(),
            'd' => Schema::int(),
        ]);

        Assert::same(\array_keys($schema->omit('a', 'b', 'c')->properties), ['d']);
        Assert::same(\array_keys($schema->pick('a', 'b', 'c')->properties), ['a', 'b', 'c']);
    }

    public function anUnknownIntKeyIsReportedAsAStringSegment(): void
    {
        $error = Schema::object([])->parse([7 => 'x'])->errors[0];

        Assert::same($error->segments, ['7']);
        Assert::same($error->toPath(), "$['7']");
    }

    public function theGroupFallbackIsUsedOnlyWhenTheGroupIsAbsent(): void
    {
        // a and b are optional (each present half the time); without the
        // group a quarter of the draws would be empty. The fallback fills
        // only those, so "both present" stays the plain quarter.
        $schema = Schema::object([
            'a' => Schema::int()->optional(),
            'b' => Schema::int()->optional(),
        ])->requireAnyOf('a', 'b');

        $random = new Random(11);
        $both = 0;

        for ($i = 0; $i < 400; $i++) {
            $value = $schema->arbitrary()->generate($random)->value;
            Assert::true($value !== []);
            $both += \count($value) === 2 ? 1 : 0;
        }

        Assert::true($both < 140, \sprintf('%d of 400 draws had both keys', $both));
        Assert::true($both > 60, \sprintf('%d of 400 draws had both keys', $both));
    }

    public function exportsTheFullFragment(): void
    {
        $export = Schema::object([
            'id' => Schema::int(),
            'tags' => Schema::arrayOf(Schema::string())->optional(),
            'lang' => Schema::string()->default('en'),
            'even' => Schema::int()->refine(static fn(int $v): bool => $v % 2 === 0, 'even'),
        ])->toJsonSchema();

        Assert::same($export->schema, [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer'],
                'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
                'lang' => ['type' => 'string', 'default' => 'en'],
                'even' => ['type' => 'integer'],
            ],
            'additionalProperties' => false,
            'required' => ['id', 'even'],
        ]);
        Assert::same(\count($export->nonRepresentable), 1);
        Assert::same($export->nonRepresentable[0]->toPath(), '$.even');
        Assert::false(\array_key_exists('required', Schema::object(['a' => Schema::int()->optional()])->toJsonSchema()->schema));
    }

    public function numericPropertyNamesWorkEverywhere(): void
    {
        // PHP stores the key "0" as int 0; the schema still treats it as
        // an object key.
        $schema = Schema::object([
            '0' => Schema::int()->optional(),
            '1' => Schema::string()->optional(),
            'a' => Schema::int(),
        ])->requireAnyOf('0', '1');

        Assert::same($schema->parse(['a' => 1, '0' => 'x'])->errors[0]->segments, ['0']);
        Assert::same($schema->parse(['0' => 1])->errors[0]->segments, ['a']);
        Assert::same(Schema::object(['7' => Schema::int()])->parse([])->errors[0]->segments, ['7']);
        Assert::true($schema->parse(['a' => 1, '1' => 'x'])->ok);

        $random = new Random(5);

        for ($i = 0; $i < 30; $i++) {
            Assert::true($schema->parse($schema->arbitrary()->generate($random)->value)->ok);
        }

        Assert::same(\array_keys($schema->pick('0', 'a')->properties), [0, 'a']);
        Assert::same(\array_keys($schema->omit('0')->properties), [1, 'a']);
        Assert::same($schema->omit('0')->anyOf, [['1']]);

        $replaced = $schema->extend(['0' => Schema::string()->optional()]);

        Assert::same(\array_keys($replaced->properties), [0, 1, 'a']);
        Assert::true($replaced->parse(['a' => 1, '0' => 'now a string'])->ok);
    }

    public function anUnknownKeyIsNamedByThePathNotByTheMessage(): void
    {
        $error = Schema::object([])->parse(['<script>secret-token' => 1])->errors[0];

        Assert::same($error->message, 'Unknown property');
        Assert::same($error->toPath(), "$['<script>secret-token']");
    }

    public function toWireOfAnObjectIsItsWireArray(): void
    {
        Assert::same(Schema::object(['role' => Schema::enum(\Rasuvaeff\Schema\Tests\Support\Role::class)])->toWire(['role' => \Rasuvaeff\Schema\Tests\Support\Role::Admin]), ['role' => 'admin']);
        Assert::same(Schema::object([])->toWire('not an array'), 'not an array');
    }

    public function anObjectWithoutPropertiesGeneratesTheEmptyArray(): void
    {
        $arbitrary = Schema::object([])->arbitrary();

        Assert::same($arbitrary->generate(new Random(1))->value, []);
    }
}
