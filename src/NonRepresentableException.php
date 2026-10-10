<?php

declare(strict_types=1);

namespace Rasuvaeff\Schema;

/**
 * Thrown by `toJsonSchema()` in strict mode (the default) when the schema
 * contains a transform without a declared JSON Schema codec: exporting
 * the source schema would lie about the target type, and a silently empty
 * schema would be a vacuous check. Pass `lenient: true` to downgrade to
 * an empty fragment plus a {@see NonRepresentable} entry.
 *
 * @api
 */
final class NonRepresentableException extends \InvalidArgumentException {}
