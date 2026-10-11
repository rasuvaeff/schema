<?php

declare(strict_types=1);

namespace Rasuvaeff\Schema\Type;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\Schema\Format;
use Rasuvaeff\Schema\JsonSchemaExport;
use Rasuvaeff\Schema\NonRepresentable;
use Rasuvaeff\Schema\ParseError;
use Rasuvaeff\Schema\ParseResult;
use Rasuvaeff\Schema\SchemaException;
use Rasuvaeff\Schema\SchemaType;

/**
 * Length-bounded string with an optional full-match pattern. Length is
 * measured in code points (`mb_strlen`), not bytes. The domain is JSON
 * strings: invalid UTF-8 is rejected up-front, before any length, format
 * or pattern check, so the code-point length and the pattern engine
 * never disagree about the input.
 *
 * @extends SchemaType<string>
 *
 * @api
 */
final class StringSchema extends SchemaType
{
    /**
     * @param int<0, max>|null $minLength
     * @param int<0, max>|null $maxLength
     * @param string|null $pattern a complete PCRE pattern with delimiters;
     *     the empty string is rejected eagerly
     * @param ArbitraryInterface<string>|null $patternGenerator the
     *     caller-supplied generator of a `regexUnchecked()` pattern —
     *     never set for `regex()` patterns, which generate through
     *     `Gen::stringMatching`
     */
    public function __construct(
        public readonly ?int $minLength = null,
        public readonly ?int $maxLength = null,
        public readonly ?string $pattern = null,
        public readonly ?Format $format = null,
        public readonly ?ArbitraryInterface $patternGenerator = null,
    ) {
        if ($pattern === '') {
            throw new \InvalidArgumentException('Pattern must not be empty');
        }

        if ($pattern !== null && $format instanceof \Rasuvaeff\Schema\Format) {
            throw new \InvalidArgumentException('Pattern and format are mutually exclusive — combine lengths with one of them');
        }

        if ($patternGenerator instanceof ArbitraryInterface && $pattern === null) {
            throw new \InvalidArgumentException('A pattern generator is only meaningful together with a pattern');
        }
    }

    public function min(int $length): self
    {
        if ($length < 0) {
            throw new \InvalidArgumentException('Minimum length must not be negative');
        }

        if ($this->maxLength !== null && $length > $this->maxLength) {
            throw new \InvalidArgumentException('Minimum length must not exceed the maximum');
        }

        return new self(minLength: $length, maxLength: $this->maxLength, pattern: $this->pattern, patternGenerator: $this->patternGenerator);
    }

    public function max(int $length): self
    {
        if ($length < 0) {
            throw new \InvalidArgumentException('Maximum length must not be negative');
        }

        if ($this->minLength !== null && $length < $this->minLength) {
            throw new \InvalidArgumentException('Maximum length must not be below the minimum');
        }

        return new self(minLength: $this->minLength, maxLength: $length, pattern: $this->pattern, patternGenerator: $this->patternGenerator);
    }

    /**
     * @param non-empty-string $pattern a PCRE pattern **without delimiters
     *     and without flags** — the convention of `Gen::stringMatching`,
     *     mirrored here so the same string drives both parse and
     *     generation. Inline groups like `(?i)` belong inside the pattern;
     *     wrapped as `($pattern)` for `preg_match`, so parens stay balanced
     *     by the grammar of the pattern itself. Invalid regex is an
     *     authoring error, rejected eagerly
     */
    public function regex(string $pattern): self
    {
        $this->assertValidPcre($pattern);

        try {
            Gen::stringMatching($pattern);
        } catch (\InvalidArgumentException $uncompilable) {
            throw new \InvalidArgumentException(
                'Pattern is valid PCRE but not generatable — the parity law requires the generator to produce matching strings; use the supported subset (e.g. `$` instead of `\z`), or bring your own generator via regexUnchecked()',
                0,
                $uncompilable,
            );
        }

        return new self(minLength: $this->minLength, maxLength: $this->maxLength, pattern: $pattern);
    }

    /**
     * The explicit opt-out for patterns outside the generatable PCRE
     * subset (`\z`, lookaround, backreferences, flags): you supply the
     * generator, the schema keeps the pattern for parsing and export.
     *
     * The parity law becomes yours to uphold: everything `$generator`
     * produces must match `$pattern` — the package can no longer check
     * it at construction. Build matching values constructively
     * (`Gen::map()` over the parts of the pattern); a `Gen::filter()` with
     * the pattern as its predicate only works when matches are common in
     * the source generator, otherwise generation is exhausted.
     *
     * @param non-empty-string $pattern a PCRE pattern in the
     *     `regex()` convention — no delimiters, no flags
     * @param ArbitraryInterface<string> $generator must produce strings
     *     matching `$pattern`
     */
    public function regexUnchecked(string $pattern, ArbitraryInterface $generator): self
    {
        $this->assertValidPcre($pattern);

        return new self(
            minLength: $this->minLength,
            maxLength: $this->maxLength,
            pattern: $pattern,
            patternGenerator: $generator,
        );
    }

    /**
     * @param non-empty-string $pattern
     */
    private function assertValidPcre(string $pattern): void
    {
        $error = null;
        \set_error_handler(static function (int $severity, string $message) use (&$error): bool {
            $error = $message;

            return true;
        });

        try {
            $probe = \preg_match('(' . $pattern . ')', '');
        } finally {
            \restore_error_handler();
        }

        if ($probe === false || $error !== null) {
            throw new \InvalidArgumentException('Invalid regular expression pattern');
        }
    }

    /**
     * A named format — a closed pair: the JSON Schema `format` keyword and
     * the generator primitive both come with the enum case.
     */
    public function format(Format $format): self
    {
        return new self(
            minLength: $this->minLength,
            maxLength: $this->maxLength,
            pattern: $this->pattern,
            format: $format,
        );
    }

    #[\Override]
    public function parse(mixed $input): ParseResult
    {
        if (!\is_string($input)) {
            return ParseResult::failed([
                new ParseError([], \sprintf('Expected string, got %s', \get_debug_type($input))),
            ]);
        }

        if (!\mb_check_encoding($input, 'UTF-8')) {
            return ParseResult::failed([
                new ParseError([], 'String is not valid UTF-8'),
            ]);
        }

        $length = \mb_strlen($input);

        if ($this->minLength !== null && $length < $this->minLength) {
            return ParseResult::failed([
                new ParseError([], \sprintf('String length must be at least %d, got %d', $this->minLength, $length)),
            ]);
        }

        if ($this->maxLength !== null && $length > $this->maxLength) {
            return ParseResult::failed([
                new ParseError([], \sprintf('String length must be at most %d, got %d', $this->maxLength, $length)),
            ]);
        }

        if ($this->format instanceof \Rasuvaeff\Schema\Format && !$this->formatAccepts($input)) {
            return ParseResult::failed([
                new ParseError([], \sprintf('String is not a valid %s', $this->format->value)),
            ]);
        }

        if ($this->pattern !== null) {
            /** @var non-empty-string $pattern the constructor rejects the empty string */
            $pattern = $this->pattern;

            $matched = \preg_match('(' . $pattern . ')', $input);

            if ($matched === false) {
                // Engine failure — compilation failure of a pattern that
                // bypassed the eager probe, or a runtime limit. Distinct
                // from a genuine mismatch; the engine's own message carries
                // no input values.
                return ParseResult::failed([
                    new ParseError(
                        [],
                        \sprintf('Pattern check failed (PCRE engine error: %s)', \preg_last_error_msg()),
                    ),
                ]);
            }

            if ($matched === 0) {
                return ParseResult::failed([
                    new ParseError([], 'String does not match the required pattern'),
                ]);
            }
        }

        return ParseResult::ok($input);
    }

    private function formatAccepts(string $input): bool
    {
        \assert($this->format instanceof \Rasuvaeff\Schema\Format);

        return match ($this->format) {
            Format::Email => \filter_var($input, \FILTER_VALIDATE_EMAIL) !== false,
            Format::Uuid => \preg_match('(^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z)i', $input) === 1,
            Format::Url => \filter_var($input, \FILTER_VALIDATE_URL) !== false,
            Format::DateTime => $this->rfc3339($input) instanceof \DateTimeImmutable,
            Format::Ipv4 => \filter_var($input, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4) !== false,
        };
    }

    /**
     * The shared RFC 3339 reader: strict about rolled-over components
     * (`25:00`) and trailing data, both of which `createFromFormat`
     * alone would forgive. The same rule lives in
     * {@see DateTimeSchema::parse()} — one wire format, one verdict.
     */
    private function rfc3339(string $input): ?\DateTimeImmutable
    {
        $parsed = \DateTimeImmutable::createFromFormat(\DATE_RFC3339_EXTENDED, $input);

        if ($parsed === false || \DateTimeImmutable::getLastErrors() !== false) {
            return null;
        }

        return $parsed;
    }

    #[\Override]
    public function parseOrThrow(mixed $input): string
    {
        $result = $this->parse($input);

        if ($result->ok) {
            /** @var string $value */
            $value = $result->value;

            return $value;
        }

        throw new SchemaException($result->errors);
    }

    #[\Override]
    public function arbitrary(): ArbitraryInterface
    {
        if ($this->format instanceof \Rasuvaeff\Schema\Format) {
            $arbitrary = match ($this->format) {
                Format::Email => Gen::email(),
                Format::Uuid => Gen::uuid(),
                Format::Url => Gen::url(),
                Format::Ipv4 => Gen::ipv4(),
                Format::DateTime => Gen::map(
                    Gen::datetime(),
                    static fn(\DateTimeImmutable $dateTime): string => $dateTime->format(\DATE_RFC3339_EXTENDED),
                ),
            };

            if ($this->minLength !== null || $this->maxLength !== null) {
                $min = $this->minLength ?? 0;
                $max = $this->maxLength ?? \max($min, 100);

                $arbitrary = Gen::filter(
                    $arbitrary,
                    static fn(string $value): bool => \mb_strlen($value) >= $min && \mb_strlen($value) <= $max,
                );
            }

            return $arbitrary;
        }

        if ($this->pattern !== null) {
            $arbitrary = $this->patternGenerator ?? Gen::stringMatching($this->pattern);
            if ($this->minLength !== null || $this->maxLength !== null) {
                $min = $this->minLength ?? 0;
                $max = $this->maxLength ?? max($min, 100);

                $arbitrary = Gen::filter(
                    $arbitrary,
                    static fn(string $value): bool => \mb_strlen($value) >= $min && \mb_strlen($value) <= $max,
                );
            }

            return $arbitrary;
        }

        $min = $this->minLength ?? 0;
        $max = $this->maxLength ?? 100;

        if ($max === 0) {
            return Gen::constant('');
        }

        /** @var int<1, max> $maxLength the zero case returned above */
        $maxLength = $max;

        return Gen::stringOf($min, $maxLength);
    }

    #[\Override]
    public function toJsonSchema(bool $lenient = false): JsonSchemaExport
    {
        $schema = ['type' => 'string'];

        if ($this->minLength !== null) {
            $schema['minLength'] = $this->minLength;
        }

        if ($this->maxLength !== null) {
            $schema['maxLength'] = $this->maxLength;
        }

        if ($this->pattern !== null) {
            $schema['pattern'] = $this->pattern;
        }

        if ($this->format instanceof \Rasuvaeff\Schema\Format) {
            $schema['format'] = $this->format->value;
        }

        // A regexUnchecked() pattern sits outside the checked subset on
        // purpose (`\z`, lookaround, backreferences) — exported as written,
        // but nothing vouches that a JSON Schema (ECMA-262) validator reads
        // it the way PCRE does, so the drop of that guarantee is recorded.
        if ($this->patternGenerator instanceof ArbitraryInterface) {
            return JsonSchemaExport::of($schema, [new NonRepresentable([], 'regexUnchecked')]);
        }

        return JsonSchemaExport::of($schema);
    }
}
