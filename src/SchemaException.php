<?php

declare(strict_types=1);

namespace Rasuvaeff\Schema;

/**
 * Thrown by {@see SchemaInterface::parseOrThrow()} when the input does not
 * match the schema. Carries every collected error, rendered one per line
 * in the message (paths + messages, never input values).
 *
 * An invalid input, not a runtime failure: the exception extends
 * `\InvalidArgumentException`, so a consumer whose own contract already
 * declares `InvalidArgumentException` can let it propagate instead of
 * re-wrapping it in a try/catch. Code that caught it as a
 * `\RuntimeException` (pre-0.4 parent, which sat in the SPL logic branch)
 * must catch `SchemaException` itself or `\InvalidArgumentException`.
 *
 * @api
 */
final class SchemaException extends \InvalidArgumentException
{
    /**
     * @param list<ParseError> $errors never empty by construction
     */
    public function __construct(
        public readonly array $errors,
    ) {
        parent::__construct($this->render($errors));
    }

    /**
     * @param list<ParseError> $errors never empty by construction
     */
    private function render(array $errors): string
    {
        $lines = [];

        foreach ($errors as $error) {
            $lines[] = $error->toPath() . ' ' . $error->message;

            foreach ($error->branches as $branch) {
                $lines[] = '  ' . $branch->__toString();
            }
        }

        return \implode("\n", $lines);
    }
}
