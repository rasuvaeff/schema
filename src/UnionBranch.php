<?php

declare(strict_types=1);

namespace Rasuvaeff\Schema;

/**
 * One error of one union member, kept with the member's position instead
 * of being flattened into a summary line: the path inside the failing
 * branch and every error of every branch survive — a union of objects
 * stays diagnosable. Carried by {@see ParseError::$branches}; parents
 * prepend their segment to the branch's error exactly like to any other
 * error.
 *
 * @api
 */
final readonly class UnionBranch implements \Stringable
{
    /**
     * @param int $member zero-based position of the branch in the union's
     *     declaration order
     */
    public function __construct(
        public int $member,
        public ParseError $error,
    ) {}

    public function withSegment(int|string $segment): self
    {
        return new self($this->member, $this->error->withSegment($segment));
    }

    /**
     * `[0] $.name Expected string, got null` — the member position, the
     * full path into the input and the message.
     */
    #[\Override]
    public function __toString(): string
    {
        return \sprintf('[%d] %s %s', $this->member, $this->error->toPath(), $this->error->message);
    }
}
