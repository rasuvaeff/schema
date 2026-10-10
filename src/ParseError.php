<?php

declare(strict_types=1);

namespace Rasuvaeff\Schema;

/**
 * One validation problem: a structured path into the input plus a message
 * that never embeds the offending value.
 *
 * The path is carried as segments, not a string: {@see toPath()} renders
 * the JSONPath-like notation this monorepo's tooling uses
 * (`$.tags[2]`), {@see toJsonPointer()} renders RFC 6901 (`/tags/2`) for
 * the outside world. Both are projections; the segments are the source
 * of truth.
 *
 * @api
 */
final readonly class ParseError
{
    /**
     * @param non-empty-list<int|string> $segments
     */
    public function __construct(
        public array $segments,
        public string $message,
    ) {}

    /**
     * The same error one level deeper: a parent (object property, list
     * index) prepends its segment to errors coming from below.
     */
    public function withSegment(int|string $segment): self
    {
        return new self([$segment, ...$this->segments], $this->message);
    }

    /**
     * JSONPath-like rendering with a mandatory `$` root, the notation of
     * `Assert::json` in the monorepo: `$.tags[2].name`.
     */
    public function toPath(): string
    {
        $path = '$';

        foreach ($this->segments as $segment) {
            $path .= \is_int($segment) ? '[' . $segment . ']' : '.' . $segment;
        }

        return $path;
    }

    /**
     * RFC 6901 JSON Pointer: `/tags/2/name`, with `~` and `/` escaped.
     */
    public function toJsonPointer(): string
    {
        $parts = [];

        foreach ($this->segments as $segment) {
            $parts[] = \str_replace(['~', '/'], ['~0', '~1'], (string) $segment);
        }

        return '/' . \implode('/', $parts);
    }
}
