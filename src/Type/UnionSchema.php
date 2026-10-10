<?php

declare(strict_types=1);

namespace Rasuvaeff\Schema\Type;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\Schema\Combinators;
use Rasuvaeff\Schema\ParseError;
use Rasuvaeff\Schema\ParseResult;
use Rasuvaeff\Schema\SchemaException;
use Rasuvaeff\Schema\SchemaInterface;

/**
 * First success wins, in declaration order. Overlapping members are an
 * authoring error the parser will silently forgive — write disjoint
 * members or discriminate through an object property.
 *
 * @implements SchemaInterface<mixed>
 *
 * @api
 */
final readonly class UnionSchema implements SchemaInterface
{
    use Combinators;

    /**
     * @param non-empty-list<SchemaInterface<mixed>> $members
     */
    public function __construct(
        public array $members,
    ) {
        if ($members === []) {
            throw new \InvalidArgumentException('Union requires at least one member schema');
        }
    }

    public function parse(mixed $input): ParseResult
    {
        $summaries = [];

        foreach ($this->members as $member) {
            $result = $member->parse($input);

            if ($result->ok) {
                return $result;
            }

            $summaries[] = $result->errors[0]->message;
        }

        return ParseResult::failed([
            new ParseError(
                [],
                \sprintf('Value does not match any of %d schemas: %s', \count($this->members), \implode('; ', $summaries)),
            ),
        ]);
    }

    public function parseOrThrow(mixed $input): mixed
    {
        $result = $this->parse($input);

        if ($result->ok) {
            return $result->value;
        }

        throw new SchemaException($result->errors);
    }

    public function arbitrary(): ArbitraryInterface
    {
        $pairs = [];

        foreach ($this->members as $member) {
            $pairs[] = [1, $member->arbitrary()];
        }

        return Gen::frequency($pairs);
    }
}
