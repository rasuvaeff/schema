# rasuvaeff/schema

![License](https://img.shields.io/packagist/l/rasuvaeff/schema) ![PHP](https://img.shields.io/packagist/dependency-v/rasuvaeff/schema/php)

Schema-as-code for PHP: one definition, two ports. The same `Schema::object([...])`
tree validates runtime input **and** feeds property-based tests a generator over
the exact same value set — so the validator and the tests can never drift apart.

> Using an AI coding assistant? [llms.txt](llms.txt) is a compact API reference
> designed for LLMs.

[Русская версия](README.ru.md)

## Why

Validation rules and test generators are usually written separately, in different
places, by different versions of you. They drift: the queue consumer accepts
`status: 'pending'`, the property test never generates it, and the tests are
blind to what production actually lets through. `rasuvaeff/schema` kills the
drift by construction: **one schema** defines the set, `parse()` checks it,
`arbitrary()` generates from it — the parity is pinned by the package's own
property suite over random schema trees.

The pattern is proven elsewhere (pydantic + hypothesis in Python,
Zod + fast-check in TypeScript); this is the PHP take, built on
[`rasuvaeff/property-testing-core`](https://github.com/rasuvaeff/property-testing-core).

## Requirements

- PHP 8.3 / 8.4 / 8.5
- `ext-mbstring`
- `rasuvaeff/property-testing-core` ^1.2 (runtime — the generator port is the
  product, not a test utility)

## Installation

```bash
composer require rasuvaeff/schema
```

## Usage

### Define

```php
use Rasuvaeff\Schema\Schema;

$user = Schema::object([
    'email' => Schema::string()->min(3)->max(254),
    'age' => Schema::int()->min(18)->max(120),
    'role' => Schema::enum(Role::class),        // backed enum
    'tags' => Schema::arrayOf(Schema::string()->min(1))->maxCount(20),
    'bio' => Schema::string()->max(1000)->default(''),   // default ≡ optional
    'meta' => Schema::mapOf(Schema::json())->optional(),
]);
```

### Validate

```php
$result = $user->parse($payload);

if (!$result->ok) {
    foreach ($result->errors as $error) {
        // '$.tags[2]' / RFC 6901 '/tags/2' — the segments are the source of truth
        $error->toPath();
        $error->toJsonPointer();
        $error->message;  // kinds and sizes only, never the offending value
    }
}

$value = $user->parseOrThrow($payload);  // SchemaException carries every error
```

Object semantics, pinned: an absent optional key is **absent from the result**
(not `null`); a defaulted key is always present, carrying the validated,
normalized default (an enum default may be given as its backed value and is
stored as the instance). `nullable()` is about the value; `optional()` is
about the key.

### Generate

```php
use Rasuvaeff\PropertyTesting\Random;

$random = new Random(42);
$value = $user->arbitrary()->generate($random)->value;  // always parses
```

In property tests (with `rasuvaeff/property-testing-testo`) the schema is the
generator:

```php
#[Property(runs: 300)]
public function queuePayloadIsHandled(SchemaInterface $payload): void
{
    $value = Gen::draw($payload->arbitrary());
    // ...
}
```

### Combinators

```php
Schema::int()->min(0)->transform(static fn (int $v): string => (string) $v);
// generation maps into the target space; shrinking stays in the source space

Schema::string()->refine(static fn (string $v): bool => !str_contains($v, ' '), 'No spaces');
// prefer constraints — a refinement filters the generator and burns budget
```

Order rule: combinators first, property modifiers last —
`->min(1)->transform(...)->optional()`.

## Security

Error messages never embed input values — kinds and sizes only
(`get_debug_type`, lengths, counts). Payloads may carry secrets; errors
travel to logs and Sentry. A `transform` callback that throws contributes
its class name only.

## Examples

- [examples/queue-payload.php](examples/queue-payload.php) — object schema
  with enum/default, error paths, generation from the same schema.

## Development

No PHP/Composer on the host — Docker:

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 composer build
```

See [AGENTS.md](AGENTS.md) for the full command set and invariants.

## License

BSD-3-Clause. See [LICENSE.md](LICENSE.md).
