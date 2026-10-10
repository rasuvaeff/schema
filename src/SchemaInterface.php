<?php

declare(strict_types=1);

namespace Rasuvaeff\Schema;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;

/**
 * A schema describes a set of values: it validates runtime input
 * ({@see parse()}), produces a generator over the exact same set
 * ({@see arbitrary()}), and from 1.1 exports its wire form
 * ({@see toJsonSchema()}).
 *
 * The two ports are one contract: everything {@see arbitrary()} produces
 * must pass {@see parse()} — the parity law, pinned by this package's own
 * property suite. A schema whose validator and generator have drifted
 * apart fails its own tests, not somebody else's production.
 *
 * @template TValue
 *
 * @api
 */
interface SchemaInterface
{
    /**
     * Validate `$input` against this schema. Never throws for a validation
     * failure: every problem is reported through {@see ParseResult::$errors}
     * with a structured path. Messages never embed input values — only
     * kinds and sizes — because payloads may carry secrets.
     *
     * @param mixed $input
     *
     * @return ParseResult<TValue>
     */
    public function parse(mixed $input): ParseResult;

    /**
     * Validate `$input` and return the parsed value, or throw a single
     * exception carrying every error.
     *
     * @param mixed $input
     *
     * @return TValue
     *
     * @throws SchemaException when `$input` does not match the schema.
     */
    public function parseOrThrow(mixed $input): mixed;

    /**
     * A generator over the set of values {@see parse()} accepts. Shrinking
     * happens in the source domain; {@see Combinator\TransformSchema}
     * re-applies its callback, so shrunk counterexamples stay valid.
     *
     * @return ArbitraryInterface<TValue>
     */
    public function arbitrary(): ArbitraryInterface;
}
