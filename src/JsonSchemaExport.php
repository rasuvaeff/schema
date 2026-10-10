<?php

declare(strict_types=1);

namespace Rasuvaeff\Schema;

/**
 * The result of `toJsonSchema()`: a pure draft 2020-12 fragment plus the
 * list of constructs the fragment could not represent. The array stays
 * clean — no magic keys — so it can be embedded into documents and fed
 * to validators as is.
 *
 * @api
 */
final readonly class JsonSchemaExport
{
    /**
     * @param array<string, mixed> $schema
     * @param list<NonRepresentable> $nonRepresentable
     */
    private function __construct(
        public array $schema,
        public array $nonRepresentable,
    ) {}

    /**
     * @param array<string, mixed> $schema
     * @param list<NonRepresentable> $nonRepresentable
     */
    public static function of(array $schema, array $nonRepresentable = []): self
    {
        return new self($schema, $nonRepresentable);
    }

    /**
     * @throws NonRepresentableException when anything was dropped
     */
    public function assertFullyRepresentable(): void
    {
        if ($this->nonRepresentable !== []) {
            throw new NonRepresentableException(
                \implode('; ', \array_map(static fn(NonRepresentable $entry): string => (string) $entry, $this->nonRepresentable)),
            );
        }
    }
}
