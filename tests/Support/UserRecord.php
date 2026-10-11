<?php

declare(strict_types=1);

namespace Rasuvaeff\Schema\Tests\Support;

/**
 * A one-property domain object for the consumer round-trip test.
 */
final readonly class UserRecord
{
    public function __construct(
        public string $name,
    ) {}
}
