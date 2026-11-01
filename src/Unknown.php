<?php

declare(strict_types=1);

namespace Rasuvaeff\Schema;

/**
 * What an object schema does with keys it does not declare — one mode per
 * object, set once through `Schema::object([...], unknown: ...)` or
 * `ObjectSchema::unknown()`.
 *
 * @api
 */
enum Unknown
{
    /**
     * An unknown key is a parse error at its own path. The default: a
     * payload you own should not grow silently.
     */
    case Reject;

    /**
     * Unknown keys are dropped; the result carries the declared keys only.
     * The mode for third-party payloads you read partially.
     */
    case Strip;

    /**
     * Unknown keys pass through into the result unchanged (raw, not
     * validated). The mode for maps you round-trip.
     */
    case Passthrough;
}
