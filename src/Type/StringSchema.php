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
 * Length-bounded string with an optional full-match pattern. Length is
 * measured in code points (`mb_strlen`), not bytes.
 *
 * @implements SchemaInterface<string>
 *
 * @api
 */
final readonly class StringSchema implements SchemaInterface
{
    use Combinators;

    public function __construct(
        public ?int $minLength = null,
        public ?int $maxLength = null,
        public ?string $pattern = null,
    ) {}

    public function min(int $length): self
    {
        if ($length < 0) {
            throw new \InvalidArgumentException('Minimum length must not be negative');
        }

        if ($this->maxLength !== null && $length > $this->maxLength) {
            throw new \InvalidArgumentException('Minimum length must not exceed the maximum');
        }

        return new self(minLength: $length, maxLength: $this->maxLength, pattern: $this->pattern);
    }

    public function max(int $length): self
    {
        if ($length < 0) {
            throw new \InvalidArgumentException('Maximum length must not be negative');
        }

        if ($this->minLength !== null && $length < $this->minLength) {
            throw new \InvalidArgumentException('Maximum length must not be below the minimum');
        }

        return new self(minLength: $this->minLength, maxLength: $length, pattern: $this->pattern);
    }

    /**
     * @param non-empty-string $pattern a complete PCRE pattern with
     *     delimiters, validated eagerly: an invalid regex is an authoring
     *     error, not a parse-time surprise
     */
    public function regex(string $pattern): self
    {
        $error = null;
        \set_error_handler(static function (int $severity, string $message) use (&$error): bool {
            $error = $message;

            return true;
        });

        try {
            $probe = \preg_match($pattern, '');
        } finally {
            \restore_error_handler();
        }

        if ($probe === false || $error !== null) {
            throw new \InvalidArgumentException('Invalid regular expression pattern');
        }

        return new self(minLength: $this->minLength, maxLength: $this->maxLength, pattern: $pattern);
    }

    public function parse(mixed $input): ParseResult
    {
        if (!\is_string($input)) {
            return ParseResult::failed([
                new ParseError([], \sprintf('Expected string, got %s', \get_debug_type($input))),
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

        if ($this->pattern !== null && \preg_match($this->pattern, $input) !== 1) {
            return ParseResult::failed([
                new ParseError([], 'String does not match the required pattern'),
            ]);
        }

        return ParseResult::ok($input);
    }

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

    public function arbitrary(): ArbitraryInterface
    {
        if ($this->pattern !== null) {
            $arbitrary = Gen::stringMatching($this->pattern);

            if ($this->minLength !== null || $this->maxLength !== null) {
                $min = $this->minLength ?? 0;
                $max = $this->maxLength ?? max($min, 100);

                $arbitrary = Gen::filter(
                    $arbitrary,
                    static fn (string $value): bool => \mb_strlen($value) >= $min && \mb_strlen($value) <= $max,
                );
            }

            return $arbitrary;
        }

        $min = $this->minLength ?? 0;
        $max = $this->maxLength ?? 100;

        return Gen::stringOf($min, $max);
    }
}
