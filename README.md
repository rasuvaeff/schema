# rasuvaeff/schema

![Stable Version](https://img.shields.io/packagist/v/rasuvaeff/schema?label=stable)
![Total Downloads](https://img.shields.io/packagist/dt/rasuvaeff/schema?label=downloads)
![Build](https://github.com/rasuvaeff/schema/actions/workflows/build.yml/badge.svg)
![Static analysis](https://github.com/rasuvaeff/schema/actions/workflows/static-analysis.yml/badge.svg)
![Psalm level](https://img.shields.io/badge/psalm-level_1-blue.svg)
![PHP](https://img.shields.io/packagist/dependency-v/rasuvaeff/schema/php)
![License](https://img.shields.io/packagist/l/rasuvaeff/schema?label=license)

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
    'score' => Schema::float()->min(0.0)->max(1.0),       // finite floats only
    'created_at' => Schema::dateTime(),                   // RFC 3339 in, DateTimeImmutable out
    'state' => Schema::literals(['pending', 'paid']),     // a value set without a PHP enum
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
// SchemaException extends InvalidArgumentException — an invalid input,
// not a runtime failure. Consumers with an InvalidArgumentException
// contract can let it propagate as is.

$result = $user->parseJson($body);       // decode + parse; invalid JSON is
                                         // an error at '$', not a null type error
$value = $result->orThrow();             // the value, or SchemaException
$result->summary();                      // '$.age Int must be at least 18; $.tags[2] …'
                                         // first 3 errors + '(+N more)'; '' when ok
(string) $error;                         // '$.tags[2] Expected string, got int'
```

Object semantics, pinned: an absent optional key is **absent from the result**
(not `null`); a defaulted key is always present, carrying the validated,
normalized default (an enum default may be given as its backed value and is
stored as the instance). `nullable()` is about the value; `optional()` is
about the key.

### Unknown keys

Every object has one mode for undeclared keys, an `Unknown` case:

| Mode | Unknown key | Export | Use for |
|---|---|---|---|
| `Unknown::Reject` (default) | parse error at its own path | `additionalProperties: false` | payloads you own |
| `Unknown::Strip` | dropped from the result | `additionalProperties: true` + `strip` entry | third-party payloads you read partially |
| `Unknown::Passthrough` | kept in the result, raw | `additionalProperties: true` | maps you round-trip |

```php
use Rasuvaeff\Schema\Unknown;

Schema::object(['id' => Schema::int()], unknown: Unknown::Strip);
$schema->unknown(Unknown::Passthrough);   // the same shape, another mode
```

The mode belongs to one object: a nested object has its own (default:
reject).

### Composing objects

```php
$order = Schema::object([
    'id' => Schema::int()->positive(),
    'email' => Schema::string()->optional(),
    'phone' => Schema::string()->optional(),
])->requireAnyOf('email', 'phone');   // at least one present; calls accumulate

$order->extend(['note' => Schema::string()->optional()]);   // add or replace
$order->pick('id', 'email');                                // keep only these
$order->omit('phone');                                      // drop these
$order->partial();                                          // every key optional (PATCH body)
```

The unknown-key mode survives every step. `pick()`/`omit()` remove dropped
names from `requireAnyOf` groups and drop an emptied group; an undeclared
name is an `InvalidArgumentException`. A defaulted property satisfies a
group (it is always present). The generator satisfies the groups
constructively; the export adds `anyOf: [{required: [email]}, {required: [phone]}]`.

### Typed results: the Psalm plugin

Without the plugin, an object parses to `array<string, mixed>`, and the
consumer writes `/** @var array{...} */` after every parse. The plugin infers
the shape from the declaration:

```xml
<!-- psalm.xml -->
<plugins>
    <pluginClass class="Rasuvaeff\Schema\Psalm\Plugin"/>
</plugins>
```

```php
$payment = Schema::object([
    'state' => Schema::literals(['pending', 'succeeded']),
    'amountCents' => Schema::int()->nonNegative(),
    'reference' => Schema::string()->optional(),
], unknown: Unknown::Strip)->parseJson($body)->orThrow();
// array{state: 'pending'|'succeeded', amountCents: int, reference?: string}
```

| Declaration | Key in the shape |
|---|---|
| bare schema, `->default($v)` | `name: T` (always present) |
| `->optional()` | `name?: T` |
| a variable holding an `ObjectProperty` | `name?: T` (the plugin cannot see whether it has a default) |
| `Unknown::Passthrough` | the shape stays open: `...<string, mixed>` |

`extend()`, `pick()`, `omit()`, `partial()` and `unknown()` carry the shape;
`into(Dto::class)` already yields the DTO type without the plugin. Union,
tuple, discriminated, lazy and json values stay `mixed` / `list<mixed>` /
`array<string, mixed>`. A declaration the plugin cannot read (properties built
in a loop) falls back to `array<string, mixed>`. Requires `vimeo/psalm` ^6.

### Recipe: an event is one schema

A message type needs a parser (`fromPayload`), a serializer (`toPayload`) and
a generator for its property tests. With `into()` and `toWire()` the schema
is all three — `parse()` is `fromWire()`:

```php
final readonly class OrderPaid
{
    public function __construct(public string $orderId, public Currency $currency) {}

    /** @return IntoSchema<self> */
    public static function schema(): IntoSchema
    {
        return Schema::object([
            'orderId' => Schema::string()->min(1),
            'currency' => Schema::enum(Currency::class),
        ], unknown: Unknown::Strip)->into(self::class);
    }

    public static function fromJson(string $json): self
    {
        return self::schema()->parseJson($json)->orThrow();
    }

    public function toPayload(): array
    {
        return self::schema()->toWire($this);   // ['orderId' => ..., 'currency' => 'EUR']
    }
}

// the property test, once per message type:
$event = Gen::draw(OrderPaid::schema()->arbitrary());
RoundTrip::assertWireRoundTrip(OrderPaid::schema(), $event);
```

No hand-written `toPayload()` can drift from the parser: both are the same
tree. `toWire()` over `into()` reads the DTO's public properties
(`get_object_vars()`), which is why the DTO takes them as promoted
constructor arguments. See [examples/event-roundtrip.php](examples/event-roundtrip.php).

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

### Export

```php
$export = $user->toJsonSchema();          // strict (default): a transform
                                          // without a declared codec throws
$export->schema;                          // pure draft 2020-12 fragment
$export->nonRepresentable;                // list<NonRepresentable>: what
                                          // was dropped, with paths
$export->assertFullyRepresentable();      // throws listing every entry

$user->toJsonSchema(lenient: true);       // a codec-less transform exports
                                          // as {} + an entry instead
```

Refinements always export as the inner schema — an honest superset, recorded
in `nonRepresentable`. A transform with a declared codec exports it, and the
package's own law 2 checks the codec against the generator's actual output.
An object's `default` annotation goes through the wire seam: an enum default
instance is exported as its backed value.

### Wire: `toWire()`

`parse`/`arbitrary` live in the PHP domain (an enum stays an instance);
queues, HTTP and JSON storage need the wire form. `toWire()` is the
projection: an enum instance becomes its backed value, containers convert
recursively, a transform's declared `wire` callback is applied, everything
else passes through.

```php
$wire = $user->toWire($parsed);   // ['role' => Role::Admin, ...] → ['role' => 'admin', ...]
```

The inverse pair of the generator's domain — `arbitrary()` produces PHP
values, `toWire()` hands them to the wire. Found by the first consumer
pilot (keppio): the queue property test needed exactly this projection.

### Combinators

```php
Schema::int()->min(0)->transform(static fn (int $v): string => (string) $v);
// generation maps into the target space; shrinking stays in the source space

Schema::int()->min(1)->nullable();   // instance form == Schema::nullable(...)

Schema::string()->refine(static fn (string $v): bool => !str_contains($v, ' '), 'No spaces');
// prefer constraints — a refinement filters the generator and burns budget
```

Order rule: combinators first, property modifiers last —
`->min(1)->transform(...)->optional()`.

### Hydrating into a DTO: `into()`

```php
$user = Schema::object([
    'name' => Schema::string()->min(1),
    'role' => Schema::enum(Role::class),
])->into(UserDto::class);

$dto = $user->parseOrThrow($payload);   // UserDto, role already a Role case
$user->toWire($dto);                    // ['name' => ..., 'role' => 'admin']
```

The parsed properties are passed to the constructor as named arguments,
after defaults and `Unknown::Strip` are settled. The DTO takes its properties as
public promoted constructor arguments — that is what makes `toWire()`
lossless. A constructor that throws becomes a parse error carrying the class
names only. The export is the object schema's own. Keep the DTO's own
invariants within the schema: a constructor stricter than the schema breaks
the generator.

### Patterns outside the generatable subset: `regexUnchecked()`

`regex()` only accepts patterns `Gen::stringMatching` can generate — `\z`,
lookaround and backreferences are rejected at construction. `regexUnchecked()`
is the explicit opt-out: you supply the generator, the schema keeps the
pattern for parsing and export.

```php
Schema::string()->regexUnchecked(
    '^a+\z',
    Gen::map(Gen::intBetween(1, 8), static fn (int $n): string => str_repeat('a', $n)),
);
```

The parity law is then yours: everything the generator produces must match.
Build matching values constructively; a `Gen::filter()` with the pattern as
predicate only works when matches are common. The export records a
`regexUnchecked` entry in `nonRepresentable` — nothing vouches that a JSON
Schema (ECMA-262) validator reads the PCRE pattern the same way.

### Testing: `Testing\RoundTrip`

The parity laws, defined once next to the schema instead of being copied
into every consumer's test support. Framework-agnostic: each method checks
one value and throws `Testing\RoundTripException` on violation, so it plugs
into a property test (feed it `$schema->arbitrary()`) or a PHPUnit loop.

```php
RoundTrip::assertParses($schema, $value);          // generated ⇒ parses
RoundTrip::assertWireRoundTrip($schema, $value);   // toWire → parse → toWire is lossless
RoundTrip::assertConsumerRoundTrip(                // your hydrate/wire pair
    $schema,
    $payload,
    static fn (mixed $p): object => Message::fromPayload($p),
    static fn (object $m): array => $m->toPayload(),
);
```

`assertConsumerRoundTrip()` checks that `hydrate($payload)` succeeds, that
the schema's `toWire($payload)` equals your wire form of the hydrated object,
and that wire → hydrate → wire is lossless. Failure messages carry the stage
and value kinds only, never the values. `assertWireRoundTrip()` needs an
invertible schema: a transform without a `wire` inverse, or a union whose
members overlap (first success wins, so the wire form may re-parse through
another member), breaks it by construction — prefer `Schema::discriminated()`.

### `yiisoft/validator` bridge

A schema as a validator rule: the property's value must parse, every schema
error lands at its own path below the property.

```php
use Rasuvaeff\Schema\Bridge\YiiValidator\SchemaRule;
use Yiisoft\Validator\Validator;

$result = (new Validator())->validate($data, [
    'payload' => [new SchemaRule(Schema::object([
        'items' => Schema::arrayOf(Schema::object(['sku' => Schema::string()->min(1)])),
    ]))],
]);

$result->getErrorMessagesIndexedByPath();
// ['payload.items.1.sku' => ['String length must be at least 1, got 0']]
```

`skipOnEmpty`, `skipOnError` and `when` work as on any `yiisoft/validator`
rule. Error parameters carry `path` (`$.items[1].sku`) and `pointer`
(`/items/1/sku`). Messages are added without translation or formatting — they
are the schema's English messages, never contain input values, and may
contain braces (a literal's value) the formatter would misread. Requires
`yiisoft/validator` ^2.6.

A Respect bridge is not shipped: `respect/validation` 3 requires PHP 8.5,
while this package supports 8.3. For Respect inside yiisoft/validator see
[`rasuvaeff/yii3-respect-validation`](https://github.com/rasuvaeff/yii3-respect-validation).

## Stability contract

What 1.x keeps (a change is a major release):

- the facade and every `@api` type, method and public property;
- parse semantics: accepted and rejected inputs, normalization (enum →
  instance, `allowInt` → float, `coerce` → typed value, defaults present,
  absent optional keys absent, declaration order of object keys), the three
  `Unknown` modes, first-success-wins for `union`, tag selection for
  `discriminated`;
- error **paths** (`ParseError::$segments`, `toPath()`, `toJsonPointer()`)
  and the rule that messages never contain input values;
- the parity law (everything `arbitrary()` generates parses) and the wire
  law (`toWire()` output validates against `toJsonSchema()`);
- `toWire()` as the inverse of `parse()`: enum → backed value, `dateTime` →
  RFC 3339 with milliseconds, `into()` → the object's wire form, a
  transform's declared `wire`;
- the JSON Schema dialect (draft 2020-12) and the list of `NonRepresentable`
  constructs: `refine`, `transform` (codec-less, lenient), `strip`, `coerce`,
  `regexUnchecked`, `dateTime bounds`;
- the strict/lenient export contract.

What may change in a minor release:

- message **texts** (match on paths and `segments`, not on wording);
- the exact JSON Schema fragment for the same accepted set (e.g. a keyword
  order or an added annotation);
- the distribution and shrinking of generated values (the set stays the
  same);
- anything `@internal` (`Internal\*`, the Psalm plugin's provider class).

## Security

Error messages never embed input values — kinds and sizes only
(`get_debug_type`, lengths, counts). Payloads may carry secrets; errors
travel to logs and Sentry. A `transform` callback that throws contributes
its class name only.

Strings are JSON's domain: `string()` rejects invalid UTF-8 up-front
(`mb_check_encoding`), before any length, format or pattern check, so the
code-point length and the pattern engine never disagree about the input. A
PCRE engine failure (e.g. backtrack limit exhausted) is reported as its own
error — `Pattern check failed (PCRE engine error: …)` — never as a plain
pattern mismatch.

## API

The `Schema` facade is the single entry point; every factory returns a
`SchemaType` subclass, all of them `@api`:

| Type | Factory | Notes |
|---|---|---|
| `Type\StringSchema` | `Schema::string()` | code-point `min`/`max`, `regex`, `regexUnchecked` (own generator), `format`; rejects invalid UTF-8 |
| `Type\IntSchema` | `Schema::int()` | `min`/`max`, `positive()` (≥1), `nonNegative()` (≥0), `coerce()` |
| `Type\FloatSchema` | `Schema::float()` | finite only (NaN/INF rejected); `min`/`max`, `allowInt()`, `coerce()`; export `type: number` |
| `Type\DateTimeSchema` | `Schema::dateTime()` | RFC 3339 wire ↔ `DateTimeImmutable` domain; inclusive `min`/`max` |
| `Type\BoolSchema` | `Schema::bool()` | `coerce()` (`"true"`/`"1"` → `true`) |
| `Type\LiteralSchema` | `Schema::literal($v)` | strict `===` |
| `Type\LiteralsSchema` | `Schema::literals(['a', 'b'])` | one of a set, strict `===`; export `enum` |
| `Type\EnumSchema` | `Schema::enum(Role::class)` | backed; instance or backed value in, instance out |
| `Type\JsonValueSchema` | `Schema::json()` | the `json_decode` domain |
| `Type\ArraySchema` | `Schema::arrayOf($el)` | list; `minCount`/`maxCount` |
| `Type\MapSchema` | `Schema::mapOf($v)` | string keys; `minCount`/`maxCount` |
| `Type\ObjectSchema` | `Schema::object([...], unknown: Unknown::Strip)` | `optional`/`default` properties; `unknown()`, `requireAnyOf()`, `extend()`, `pick()`, `omit()`, `partial()`, `into()` |
| `Type\UnionSchema` | `Schema::union([...])` | first success wins |
| `Type\DiscriminatedSchema` | `Schema::discriminated('kind', ['a' => $obj, ...])` | tag selects the branch; disjoint by construction (required literal tag); export `oneOf` |
| `Type\TupleSchema` | `Schema::tuple([...])` | fixed-length heterogeneous list; export `prefixItems` + `items: false` |
| `Type\NullableSchema` | `Schema::nullable($s)` | value-level null |
| `Type\LazySchema` | `Schema::lazy($fn)` | recursion, memoized |
| `Combinator\TransformSchema` | `->transform($fn)` | target-space generation, source-space shrinking |
| `Combinator\RefineSchema` | `->refine($fn, $msg)` | post-condition; filtered generation |
| `Type\ObjectProperty` | `->optional()` / `->default($v)` | property modifiers; default validated on construction |
| `Combinator\IntoSchema` | `Schema::object([...])->into(Dto::class)` | hydrates the parsed properties into a DTO (named arguments); export and wire stay the object's |

Testing helpers: `Testing\RoundTrip` (`assertParses`, `assertWireRoundTrip`,
`assertConsumerRoundTrip`) and `Testing\RoundTripException`.

Results: `ParseResult` (`orThrow()`, `summary()`) / `ParseError` (segments →
`$.tags[2]` and RFC 6901, `Stringable`), `SchemaException`, `JsonSchemaExport`
+ `NonRepresentable` / `NonRepresentableException` (strict export). Every
schema has `parse()`, `parseJson()`, `parseOrThrow()`, `arbitrary()`,
`toJsonSchema()`, `toWire()`.

Unknown-key modes: `Unknown` (`Reject`, `Strip`, `Passthrough`).

Integrations (optional dependencies): `Psalm\Plugin` (shape inference, needs
`vimeo/psalm`), `Bridge\YiiValidator\SchemaRule` + `SchemaRuleHandler`
(needs `yiisoft/validator`).

## Examples

- [examples/queue-payload.php](examples/queue-payload.php) — object schema
  with enum/default, error paths, generation from the same schema.
- [examples/event-roundtrip.php](examples/event-roundtrip.php) — an event
  class whose parser, serializer and generator are one schema.
- [examples/external-api.php](examples/external-api.php) — a third-party
  answer: `parseJson()`, `Unknown::Strip`, `literals()`, `requireAnyOf()`,
  `summary()`.
- [examples/yii-validator.php](examples/yii-validator.php) — a schema as a
  `yiisoft/validator` rule.

## Development

No PHP/Composer on the host — Docker:

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 composer build
```

See [AGENTS.md](AGENTS.md) for the full command set and invariants.

## License

BSD-3-Clause. See [LICENSE.md](LICENSE.md).
