<?php

declare(strict_types=1);

namespace Rasuvaeff\Schema;

/**
 * A named string format. A closed pair by contract: every case carries
 * both the JSON Schema `format` keyword and the generator primitive that
 * produces matching values — adding a format means both sides plus tests,
 * in the spirit of the monorepo's closed-set rule.
 *
 * @api
 */
enum Format: string
{
    case Email = 'email';

    case Uuid = 'uuid';

    case Url = 'uri';

    case DateTime = 'date-time';

    case Ipv4 = 'ipv4';
}
