<?php

declare(strict_types=1);

namespace Rasuvaeff\Schema\Tests\Support;

/**
 * A hydration target for IntoSchema: public promoted constructor
 * arguments, the value object shape. Its constructor carries a
 * post-condition the schema itself does not (age <= 150) — exactly the
 * case where hydration fails after a successful parse.
 */
final readonly class UserDto
{
    public function __construct(
        public string $name,
        public int $age,
        public Role $role,
        public string $bio = '',
    ) {
        if ($age > 150) {
            throw new \InvalidArgumentException('Age is implausible');
        }
    }
}
