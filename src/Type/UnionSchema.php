<?php

declare(strict_types=1);

namespace Rasuvaeff\Schema\Type;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\Schema\JsonSchemaExport;
use Rasuvaeff\Schema\ParseError;
use Rasuvaeff\Schema\ParseResult;
use Rasuvaeff\Schema\SchemaException;
use Rasuvaeff\Schema\SchemaInterface;
use Rasuvaeff\Schema\SchemaType;
use Rasuvaeff\Schema\UnionBranch;

/**
 * First success wins, in declaration order. Overlapping members are an
 * authoring error the parser will silently forgive — write disjoint
 * members or discriminate through an object property.
 *
 * @extends SchemaType<mixed>
 *
 * @api
 */
final class UnionSchema extends SchemaType
{
    /**
     * @param list<SchemaInterface<mixed>> $members tried in declaration
     *     order, first success wins; overlapping members are an authoring
     *     error the parser will silently forgive — the first member wins
     */
    public function __construct(
        public readonly array $members,
    ) {
        if ($members === []) {
            throw new \InvalidArgumentException('Union requires at least one member schema');
        }
    }

    #[\Override]
    public function parse(mixed $input): ParseResult
    {
        $branches = [];

        foreach ($this->members as $index => $member) {
            $result = $member->parse($input);

            if ($result->ok) {
                return $result;
            }

            foreach ($result->errors as $error) {
                $branches[] = new UnionBranch($index, $error);
            }
        }

        return ParseResult::failed([
            new ParseError(
                [],
                \sprintf('Value does not match any of %d schemas', \count($this->members)),
                $branches,
            ),
        ]);
    }

    #[\Override]
    public function parseOrThrow(mixed $input): mixed
    {
        $result = $this->parse($input);

        if ($result->ok) {
            return $result->value;
        }

        throw new SchemaException($result->errors);
    }

    #[\Override]
    public function arbitrary(): ArbitraryInterface
    {
        $pairs = [];

        foreach ($this->members as $member) {
            $pairs[] = [1, $member->arbitrary()];
        }

        return Gen::frequency($pairs);
    }

    #[\Override]
    public function toJsonSchema(bool $lenient = false): JsonSchemaExport
    {
        $anyOf = [];
        $nonRepresentable = [];

        foreach ($this->members as $member) {
            $child = $member->toJsonSchema($lenient);
            $anyOf[] = $child->schema;

            foreach ($child->nonRepresentable as $entry) {
                $nonRepresentable[] = $entry;
            }
        }

        return JsonSchemaExport::of(['anyOf' => $anyOf], $nonRepresentable);
    }
}
