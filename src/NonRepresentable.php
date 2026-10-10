<?php

declare(strict_types=1);

namespace Rasuvaeff\Schema;

/**
 * One piece of schema information the JSON Schema export could not carry:
 * a refinement (the export is an honest superset) or a transform without
 * a declared codec (no correct representation exists at all). Carried as
 * segments — parents prepend theirs — exactly like {@see ParseError}.
 *
 * @api
 */
final readonly class NonRepresentable
{
    /**
     * @param list<int|string> $segments empty at the export root
     */
    public function __construct(
        public array $segments,
        public string $construct,
    ) {}

    public function withSegment(int|string $segment): self
    {
        return new self([$segment, ...$this->segments], $this->construct);
    }

    public function toPath(): string
    {
        $path = '$';

        foreach ($this->segments as $segment) {
            $path .= \is_int($segment) ? '[' . $segment . ']' : '.' . $segment;
        }

        return $path;
    }

    /**
     * `transform @ $.tags[0]` — the construct and where it lived.
     */
    public function __toString(): string
    {
        return $this->construct . ' @ ' . $this->toPath();
    }
}
