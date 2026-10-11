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
 * of truth. Root-level errors carry an empty segment list.
 *
 * A union failure additionally carries every branch error in
 * {@see $branches} — the member position, the path inside the failing
 * branch and all of its errors — instead of flattening them into the
 * message.
 *
 * @api
 */
final readonly class ParseError
{
    /**
     * @param list<int|string> $segments
     * @param list<UnionBranch> $branches empty outside union failures
     */
    public function __construct(
        public array $segments,
        public string $message,
        public array $branches = [],
    ) {}

    /**
     * The same error one level deeper: a parent (object property, list
     * index) prepends its segment to errors coming from below — and to
     * the branch errors of a nested union, so their paths stay complete.
     */
    public function withSegment(int|string $segment): self
    {
        $branches = [];

        foreach ($this->branches as $branch) {
            $branches[] = $branch->withSegment($segment);
        }

        return new self([$segment, ...$this->segments], $this->message, $branches);
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
     * The root document is the empty string `''`.
     */
    public function toJsonPointer(): string
    {
        $parts = [];

        foreach ($this->segments as $segment) {
            $parts[] = \str_replace(['~', '/'], ['~0', '~1'], (string) $segment);
        }

        if ($parts === []) {
            return '';
        }

        return '/' . \implode('/', $parts);
    }
}
