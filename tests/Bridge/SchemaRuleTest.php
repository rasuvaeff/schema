<?php

declare(strict_types=1);

namespace Rasuvaeff\Schema\Tests\Bridge;

use Rasuvaeff\Schema\Bridge\YiiValidator\SchemaRule;
use Rasuvaeff\Schema\Bridge\YiiValidator\SchemaRuleHandler;
use Rasuvaeff\Schema\Schema;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;
use Yiisoft\Validator\Rule\Required;
use Yiisoft\Validator\Validator;

#[Test]
#[Covers(SchemaRule::class)]
#[Covers(SchemaRuleHandler::class)]
final class SchemaRuleTest
{
    public function aValidValueHasNoErrors(): void
    {
        $result = (new Validator())->validate(['payload' => ['sku' => 'A-1']], [
            'payload' => [new SchemaRule(Schema::object(['sku' => Schema::string()->min(1)]))],
        ]);

        Assert::true($result->isValid());
    }

    public function schemaErrorsLandAtTheirPathBelowTheProperty(): void
    {
        $schema = Schema::object([
            'items' => Schema::arrayOf(Schema::object(['sku' => Schema::string()->min(1)])),
        ]);

        $result = (new Validator())->validate(['payload' => ['items' => [['sku' => 'a'], ['sku' => '']]]], [
            'payload' => [new SchemaRule($schema)],
        ]);

        Assert::false($result->isValid());
        Assert::same($result->getErrorMessagesIndexedByPath(), [
            'payload.items.1.sku' => ['String length must be at least 1, got 0'],
        ]);
        Assert::same($result->getErrors()[0]->getParameters(), ['path' => '$.items[1].sku', 'pointer' => '/items/1/sku']);
    }

    public function everyErrorIsReported(): void
    {
        $result = (new Validator())->validate(['payload' => ['a' => 'x', 'b' => 'y']], [
            'payload' => [new SchemaRule(Schema::object(['a' => Schema::int(), 'b' => Schema::int()]))],
        ]);

        Assert::same(\array_keys($result->getErrorMessagesIndexedByPath()), ['payload.a', 'payload.b']);
    }

    public function bracesInAMessageAreNotReadAsPlaceholders(): void
    {
        $result = (new Validator())->validate(['kind' => 'x'], [
            'kind' => [new SchemaRule(Schema::literals(['{a}', '{b}']))],
        ]);

        Assert::same($result->getErrorMessages(), ["Expected one of: '{a}', '{b}'"]);
    }

    public function validatesTheWholeDataSetWithoutAProperty(): void
    {
        $result = (new Validator())->validate(['id' => 'x'], new SchemaRule(Schema::object(['id' => Schema::int()])));

        Assert::same($result->getErrorMessagesIndexedByPath(), ['id' => ['Expected int, got string']]);
    }

    public function skipOptionsWork(): void
    {
        $rule = new SchemaRule(Schema::int(), skipOnEmpty: true);

        Assert::true((new Validator())->validate(['n' => ''], ['n' => [$rule]])->isValid());
        Assert::false((new Validator())->validate(['n' => 'x'], ['n' => [$rule]])->isValid());

        $afterError = new SchemaRule(Schema::int(), skipOnError: true);
        $result = (new Validator())->validate(['n' => null], ['n' => [new Required(), $afterError]]);

        Assert::same(\count($result->getErrors()), 1);
        Assert::true($afterError->shouldSkipOnError());

        $never = new SchemaRule(Schema::int(), when: static fn(): bool => false);

        Assert::true((new Validator())->validate(['n' => 'x'], ['n' => [$never]])->isValid());
        Assert::true($never->getWhen() instanceof \Closure);
    }

    public function exposesTheSchemaAndTheHandler(): void
    {
        $schema = Schema::int();
        $rule = new SchemaRule($schema);

        Assert::same($rule->getSchema(), $schema);
        Assert::same($rule->getHandler(), SchemaRuleHandler::class);
    }

    public function theHandlerRejectsAForeignRule(): void
    {
        try {
            (new SchemaRuleHandler())->validate(1, new Required(), new \Yiisoft\Validator\ValidationContext());
        } catch (\Yiisoft\Validator\Exception\UnexpectedRuleException) {
            Assert::true(actual: true);

            return;
        }

        throw new \LogicException('No exception');
    }
}
