<?php

declare(strict_types=1);

namespace Rasuvaeff\Schema\Bridge\YiiValidator;

use Yiisoft\Validator\Exception\UnexpectedRuleException;
use Yiisoft\Validator\Result;
use Yiisoft\Validator\RuleHandlerInterface;
use Yiisoft\Validator\RuleInterface;
use Yiisoft\Validator\ValidationContext;

/**
 * Runs {@see SchemaRule}: parses the value and maps every
 * {@see \Rasuvaeff\Schema\ParseError} to a validator error whose value
 * path is the error's segments. The message is added without post
 * processing — schema messages may contain braces (a literal's value),
 * which the validator's formatter would read as placeholders.
 *
 * @api
 */
final readonly class SchemaRuleHandler implements RuleHandlerInterface
{
    #[\Override]
    public function validate(mixed $value, RuleInterface $rule, ValidationContext $context): Result
    {
        if (!$rule instanceof SchemaRule) {
            throw new UnexpectedRuleException(SchemaRule::class, $rule);
        }

        $result = new Result();

        foreach ($rule->getSchema()->parse($value)->errors as $error) {
            $result->addErrorWithoutPostProcessing(
                $error->message,
                ['path' => $error->toPath(), 'pointer' => $error->toJsonPointer()],
                $error->segments,
            );
        }

        return $result;
    }
}
