<?php

declare(strict_types=1);

namespace Rasuvaeff\Schema;

/**
 * Thrown by {@see SchemaInterface::parseOrThrow()} when the input does not
 * match the schema. Carries every collected error, rendered one per line
 * in the message (paths + messages, never input values).
 *
 * @api
 */
final class SchemaException extends \RuntimeException
{
    /**
     * @param list<ParseError> $errors never empty by construction
     */
    public function __construct(
        public readonly array $errors,
    ) {
        parent::__construct(self::render($errors));
    }

    /**
     * @param list<ParseError> $errors never empty by construction
     */
    private static function render(array $errors): string
    {
        $lines = [];

        foreach ($errors as $error) {
            $lines[] = $error->toPath() . ' ' . $error->message;
        }

        return \implode("\n", $lines);
    }
}
