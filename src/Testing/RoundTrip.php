<?php

declare(strict_types=1);

namespace Rasuvaeff\Schema\Testing;

use Rasuvaeff\Schema\SchemaInterface;

/**
 * The consumer-side parity laws, defined once next to the schemas
 * instead of being copied into every consumer's test support.
 *
 * Framework-agnostic on purpose: each method asserts one law for a
 * single value and throws {@see RoundTripException} on violation, so it
 * plugs into any runner — a Testo property test hands it
 * `$schema->arbitrary()` (see the package's own parity suite for the
 * shape), a PHPUnit test loops over examples the same way. No PHPUnit
 * dependency is introduced: if you use
 * `rasuvaeff/property-testing-phpunit`, the generators wire up
 * identically.
 *
 * Failure messages carry the failing stage and value kinds only — never
 * the values. The counterexample itself is your runner's business; a
 * property framework shrinks it for you.
 *
 * @api
 */
final class RoundTrip
{
    private function __construct() {}

    /**
     * Law 1, the package's own: everything the generator produces must
     * parse.
     */
    public static function assertParses(SchemaInterface $schema, mixed $value): void
    {
        $result = $schema->parse($value);

        if (!$result->ok) {
            throw RoundTripException::stage('parse of a generated value', $result->errors);
        }
    }

    /**
     * The wire round trip: `toWire()` of a value re-parses, and the wire
     * projection of the reparsed value is the same wire again — no loss
     * in either direction. This is the law a decode-style type
     * (`Schema::dateTime()`, a bare transform) pins instead of the
     * direct parse.
     */
    public static function assertWireRoundTrip(SchemaInterface $schema, mixed $value): void
    {
        $wire = $schema->toWire($value);

        $reparsed = $schema->parse($wire);

        if (!$reparsed->ok) {
            throw RoundTripException::stage('parse of the wire form', $reparsed->errors);
        }

        $rewired = $schema->toWire($reparsed->value);

        if ($rewired !== $wire) {
            throw new RoundTripException(
                'wire round trip is lossy: toWire(parse(toWire(value))) differs from toWire(value) '
                . \sprintf('(%s vs %s)', \get_debug_type($wire), \get_debug_type($rewired)),
            );
        }
    }

    /**
     * The consumer law of a hydrate/wire pair (the keppio shape): for a
     * payload from the schema's generator —
     *
     *  1. `hydrate($payload)` succeeds (typically the consumer's
     *     `fromPayload()` calling `parseOrThrow()`);
     *  2. the schema's wire projection equals the consumer's own wire
     *     form of the hydrated object;
     *  3. wire → hydrate → wire is lossless.
     *
     * @param \Closure(mixed): object $hydrate payload → domain object
     * @param \Closure(object): mixed $wire domain object → its wire form
     */
    public static function assertConsumerRoundTrip(
        SchemaInterface $schema,
        mixed $payload,
        \Closure $hydrate,
        \Closure $wire,
    ): void {
        try {
            $object = $hydrate($payload);
        } catch (\Throwable $exception) {
            throw new RoundTripException('hydrate of a generated payload failed (' . $exception::class . ')', code: 0, previous: $exception);
        }

        $schemaWire = $schema->toWire($payload);
        $consumerWire = $wire($object);

        if ($schemaWire !== $consumerWire) {
            throw new RoundTripException(
                \sprintf(
                    'the schema wire projection and the consumer wire form disagree (%s vs %s)',
                    \get_debug_type($schemaWire),
                    \get_debug_type($consumerWire),
                ),
            );
        }

        try {
            $rehydrated = $hydrate($consumerWire);
        } catch (\Throwable $exception) {
            throw new RoundTripException('hydrate of the wire form failed (' . $exception::class . ')', code: 0, previous: $exception);
        }

        if ($wire($rehydrated) !== $consumerWire) {
            throw new RoundTripException('the consumer round trip wire → hydrate → wire is lossy');
        }
    }
}
