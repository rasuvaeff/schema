<?php

declare(strict_types=1);

namespace Rasuvaeff\Schema\Type;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\Schema\JsonSchemaExport;
use Rasuvaeff\Schema\NonRepresentable;
use Rasuvaeff\Schema\ParseError;
use Rasuvaeff\Schema\ParseResult;
use Rasuvaeff\Schema\SchemaException;
use Rasuvaeff\Schema\SchemaType;

/**
 * A date-time: an RFC 3339 string on the wire (exactly the shape the
 * generator emits — milliseconds included, numeric offset or `Z`), a
 * `DateTimeImmutable` in the PHP domain. Out-of-range components that
 * PHP would silently roll over (`25:00`) are rejected, and so is
 * trailing data. Optional inclusive `min()`/`max()` bounds compare the
 * parsed moment.
 *
 * The domain split is the one a transform carries: `arbitrary()`
 * produces `DateTimeImmutable` instances (the PHP domain), `parse()`
 * accepts the wire string — so the direct parity law gives way to the
 * wire round trip (generated → `toWire()` → parse), pinned by this
 * package's tests.
 *
 * @extends SchemaType<\DateTimeImmutable>
 *
 * @api
 */
final class DateTimeSchema extends SchemaType
{
    public function __construct(
        public readonly ?\DateTimeImmutable $min = null,
        public readonly ?\DateTimeImmutable $max = null,
    ) {}

    public function min(\DateTimeImmutable $value): self
    {
        if ($this->max !== null && $value > $this->max) {
            throw new \InvalidArgumentException('Minimum must not exceed the maximum');
        }

        return new self(min: $value, max: $this->max);
    }

    public function max(\DateTimeImmutable $value): self
    {
        if ($this->min !== null && $value < $this->min) {
            throw new \InvalidArgumentException('Maximum must not be below the minimum');
        }

        return new self(min: $this->min, max: $value);
    }

    #[\Override]
    public function parse(mixed $input): ParseResult
    {
        if (!\is_string($input)) {
            return ParseResult::failed([
                new ParseError([], \sprintf('Expected date-time string, got %s', \get_debug_type($input))),
            ]);
        }

        $parsed = \DateTimeImmutable::createFromFormat(\DATE_RFC3339_EXTENDED, $input);
        $problems = \DateTimeImmutable::getLastErrors();

        if ($parsed === false || $problems !== false) {
            return ParseResult::failed([
                new ParseError([], 'String is not a valid date-time'),
            ]);
        }

        if ($this->min !== null && $parsed < $this->min) {
            return ParseResult::failed([
                new ParseError(
                    [],
                    \sprintf('Date-time must be at least %s', $this->min->format(\DATE_RFC3339_EXTENDED)),
                ),
            ]);
        }

        if ($this->max !== null && $parsed > $this->max) {
            return ParseResult::failed([
                new ParseError(
                    [],
                    \sprintf('Date-time must be at most %s', $this->max->format(\DATE_RFC3339_EXTENDED)),
                ),
            ]);
        }

        return ParseResult::ok($parsed);
    }

    #[\Override]
    public function parseOrThrow(mixed $input): \DateTimeImmutable
    {
        $result = $this->parse($input);

        if ($result->ok) {
            /** @var \DateTimeImmutable $value */
            $value = $result->value;

            return $value;
        }

        throw new SchemaException($result->errors);
    }

    #[\Override]
    public function arbitrary(): ArbitraryInterface
    {
        return Gen::datetime($this->min, $this->max);
    }

    #[\Override]
    public function toJsonSchema(bool $lenient = false): JsonSchemaExport
    {
        $schema = ['type' => 'string', 'format' => 'date-time'];

        if ($this->min === null && $this->max === null) {
            return JsonSchemaExport::of($schema);
        }

        // Pure draft 2020-12 has no keyword for date-time bounds, so the
        // fragment is an honest superset and the drop is recorded — the
        // refine precedent, an entry rather than a strict-mode throw.
        return JsonSchemaExport::of($schema, [new NonRepresentable([], 'dateTime bounds')]);
    }
}
