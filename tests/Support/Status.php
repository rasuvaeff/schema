<?php

declare(strict_types=1);

namespace Rasuvaeff\Schema\Tests\Support;

/**
 * A small backed enum for tuple/wire tests.
 */
enum Status: string
{
    case Active = 'active';
    case Muted = 'muted';
}
