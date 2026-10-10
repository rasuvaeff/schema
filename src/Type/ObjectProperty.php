<?php

declare(strict_types=1);

namespace Rasuvaeff\Schema\Type;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\Schema\JsonSchemaExport;
use Rasuvaeff\Schema\ParseResult;
use Rasuvaeff\Schema\SchemaInterface;

/**
 * A schema plus object-level modifiers: the key may be absent
 * (`optional`) or filled with a validated default when it is
 * (`default`). Only meaningful inside {@see ObjectSchema} — the phrase
 * "missing key" exists nowhere else.
 *
 * Combinators are deliberately absent here: `->min(1)->transform(...)`
 * first, `->optional()`/`->default()` last. Wrapping order mistakes
 * cannot silently drop modifiers that are not there.
 *
 * @template TValue
 *
 * @implements SchemaInterface<TValue>
 *
 * @api
 */
final readonly class ObjectProperty implements SchemaInterface
{
    /**
     * @param SchemaInterface<TValue> $schema
     */
    public function __construct(
        public SchemaInterface $schema,
        public bool $optional = false,
        public bool $hasDefault = false,
        public mixed $default = null,
    ) {}

    /**
     * Idempotent: an already optional property stays as it was.
     */
    public function optional(): self
    {
        if ($this->optional) {
            return $this;
        }

        return new self(schema: $this->schema, optional: true, hasDefault: $this->hasDefault, default: $this->default);
    }

    /**
     * The default is validated against the inner schema right here — an
     * invalid default is an authoring error, not a runtime surprise on
     * the first missing key. The stored default is the parsed
     * (normalized) value: an enum default may be given as its backed
     * value but is kept as the instance. Implies optional.
     *
     *
     * @psalm-param TValue $value
     */
    public function default(mixed $value): self
    {
        $result = $this->schema->parse($value);

        if (!$result->ok) {
            $error = $result->errors[0];

            throw new \InvalidArgumentException(
                \sprintf('Default value does not satisfy the schema at %s: %s', $error->toPath(), $error->message),
            );
        }

        return new self(schema: $this->schema, optional: true, hasDefault: true, default: $result->value);
    }

    #[\Override]
    public function parse(mixed $input): ParseResult
    {
        return $this->schema->parse($input);
    }

    #[\Override]
    public function parseOrThrow(mixed $input): mixed
    {
        return $this->schema->parseOrThrow($input);
    }

    #[\Override]
    public function arbitrary(): ArbitraryInterface
    {
        return $this->schema->arbitrary();
    }

    #[\Override]
    public function toJsonSchema(bool $lenient = false): JsonSchemaExport
    {
        return $this->schema->toJsonSchema($lenient);
    }

    #[\Override]
    public function toWire(mixed $value): mixed
    {
        return $this->schema->toWire($value);
    }
}
