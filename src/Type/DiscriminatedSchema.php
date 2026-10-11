<?php

declare(strict_types=1);

namespace Rasuvaeff\Schema\Type;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\Schema\JsonSchemaExport;
use Rasuvaeff\Schema\ParseError;
use Rasuvaeff\Schema\ParseResult;
use Rasuvaeff\Schema\SchemaException;
use Rasuvaeff\Schema\SchemaType;

/**
 * A tagged union: the discriminator property selects the branch, the
 * branch parses the whole object. Unlike {@see UnionSchema} there is no
 * first-success-wins forgiveness — the tag pins the branch exactly, an
 * unknown or missing tag is its own error, and an error inside the
 * branch keeps its full path (`$.payload.name`, not a flattened summary).
 *
 * Branches are disjoint by construction: every branch must declare the
 * discriminator property as a required literal equal to its key — checked
 * eagerly, at build time, so the export can honestly say `oneOf`.
 *
 * @extends SchemaType<array<string, mixed>>
 *
 * @api
 */
final class DiscriminatedSchema extends SchemaType
{
    /**
     * @param string $property the discriminator key, e.g. `'type'`
     * @param array<int|string, ObjectSchema> $branches tag → branch; each
     *     branch must declare `$property` as a required literal equal to
     *     its key
     */
    public function __construct(
        public readonly string $property,
        public readonly array $branches,
    ) {
        if ($property === '') {
            throw new \InvalidArgumentException('Discriminator property name must be a non-empty string');
        }

        if ($branches === []) {
            throw new \InvalidArgumentException('Discriminated union requires at least one branch');
        }

        foreach ($branches as $tag => $branch) {
            if (!$branch instanceof ObjectSchema) {
                throw new \InvalidArgumentException('Discriminated branches must be object schemas');
            }

            $declared = $branch->properties[$property] ?? null;

            if (
                $declared === null
                || $declared->optional
                || $declared->hasDefault
                || !$declared->schema instanceof LiteralSchema
                || $declared->schema->value !== $tag
            ) {
                throw new \InvalidArgumentException(
                    \sprintf(
                        'Discriminated branch "%s" must declare property "%s" as a required literal equal to the branch key',
                        \is_int($tag) ? (string) $tag : $tag,
                        $property,
                    ),
                );
            }
        }
    }

    #[\Override]
    public function parse(mixed $input): ParseResult
    {
        if (!\is_array($input)) {
            return ParseResult::failed([
                new ParseError([], \sprintf('Expected object, got %s', \get_debug_type($input))),
            ]);
        }

        if (!\array_key_exists($this->property, $input)) {
            return ParseResult::failed([
                new ParseError([$this->property], \sprintf('Discriminator property "%s" is missing', $this->property)),
            ]);
        }

        $tag = $input[$this->property];

        if (
            (!\is_string($tag) && !\is_int($tag))
            || !\array_key_exists($tag, $this->branches)
        ) {
            return ParseResult::failed([
                new ParseError(
                    [$this->property],
                    \sprintf('Unknown "%s" discriminator value', $this->property),
                ),
            ]);
        }

        return $this->branches[$tag]->parse($input);
    }

    #[\Override]
    public function parseOrThrow(mixed $input): array
    {
        $result = $this->parse($input);

        if ($result->ok) {
            /** @var array<string, mixed> $value */
            $value = $result->value;

            return $value;
        }

        throw new SchemaException($result->errors);
    }

    #[\Override]
    public function arbitrary(): ArbitraryInterface
    {
        $pairs = [];

        foreach ($this->branches as $branch) {
            $pairs[] = [1, $branch->arbitrary()];
        }

        return Gen::frequency($pairs);
    }

    #[\Override]
    public function toJsonSchema(bool $lenient = false): JsonSchemaExport
    {
        $oneOf = [];
        $nonRepresentable = [];

        foreach ($this->branches as $tag => $branch) {
            $child = $branch->toJsonSchema($lenient);

            $oneOf[] = $child->schema;

            foreach ($child->nonRepresentable as $entry) {
                $nonRepresentable[] = $entry->withSegment((string) $tag);
            }
        }

        // oneOf, not anyOf: the branches are disjoint by construction —
        // the eager literal check is what buys the stronger keyword.
        return JsonSchemaExport::of(['oneOf' => $oneOf], $nonRepresentable);
    }
}
