<?php

declare(strict_types=1);

namespace Rasuvaeff\Schema\Bridge\YiiValidator;

use Closure;
use Rasuvaeff\Schema\SchemaInterface;
use Yiisoft\Validator\Rule\Trait\SkipOnEmptyTrait;
use Yiisoft\Validator\Rule\Trait\SkipOnErrorTrait;
use Yiisoft\Validator\Rule\Trait\WhenTrait;
use Yiisoft\Validator\RuleInterface;
use Yiisoft\Validator\SkipOnEmptyInterface;
use Yiisoft\Validator\SkipOnErrorInterface;
use Yiisoft\Validator\ValidationContext;
use Yiisoft\Validator\WhenInterface;

/**
 * A `yiisoft/validator` rule backed by a schema: the value of the
 * property (or the whole data set) must parse. Every schema error becomes
 * a validator error at the schema's own path below the property —
 * `payload.items.2.sku` — so `getErrorMessagesIndexedByPath()` points at
 * the exact field.
 *
 * ```php
 * $result = (new Validator())->validate($form, [
 *     'payload' => [new SchemaRule(Schema::object(['sku' => Schema::string()->min(1)]))],
 * ]);
 * ```
 *
 * Requires `yiisoft/validator` ^2.6 (a suggested dependency). Messages are
 * the schema's own English ones, added without translation — they never
 * carry input values.
 *
 * @api
 */
final class SchemaRule implements RuleInterface, SkipOnEmptyInterface, SkipOnErrorInterface, WhenInterface
{
    use SkipOnEmptyTrait;
    use SkipOnErrorTrait;
    use WhenTrait;

    /**
     * @param bool|callable(mixed, bool): bool|null $skipOnEmpty see
     *     `yiisoft/validator` {@see SkipOnEmptyInterface}
     * @param Closure(mixed, ValidationContext): bool|null $when
     */
    public function __construct(
        private readonly SchemaInterface $schema,
        bool|callable|null $skipOnEmpty = null,
        private bool $skipOnError = false,
        private ?Closure $when = null,
    ) {
        $this->skipOnEmpty = $skipOnEmpty;
    }

    public function getSchema(): SchemaInterface
    {
        return $this->schema;
    }

    #[\Override]
    public function getHandler(): string
    {
        return SchemaRuleHandler::class;
    }
}
