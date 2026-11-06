# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 1.1.1 — 2026-11-06

- Fixed: `arbitrary()` of a recursive `Schema::lazy()` schema exhausted memory — the lazy schema was resolved while the generator tree was still being built, which re-entered the same schema forever. The recursion is unrolled at `generate()` time now, bounded by a depth budget of 32 levels; a schema whose every path through the recursion is mandatory (no finite value exists) fails with `GenerationExhaustedException` instead (#47).

## 1.1.0 — 2026-11-04

- Fixed: the JSON Schema export of `requireAnyOf()` demanded a key that a defaulted member satisfies without being present, so the export rejected input `parse()` accepts. A group with a defaulted member is left out of the export now (#43).
- Changed: the Psalm plugin targets Psalm 7. On Psalm 7 it crashed (`Call to private Psalm\Type\Atomic\TKeyedArray::__construct()`); it builds shapes through `TKeyedArray::make()` now (#41). `vimeo/psalm` in `require-dev` is `^7.0`. Psalm 6 is no longer tested; before 6.19 it has no `TKeyedArray::make()`, so the plugin fails there.
- Mutation testing now counts uncovered code (`composer mutation` runs Infection with `--with-uncovered`), and tests were added for the code it exposed: `parseOrThrow()` on every schema type, `NonRepresentable`, `toWire()` branches, JSON Schema export fragments, generator bounds, and `#[Covers]` targets on the tests that already exercised them. No `src/` changes.

## 1.0.1 — 2026-11-01

- Fixed: `StringSchema::min()`, `max()`, `regex()` and `regexUnchecked()` no longer discard a format set earlier in the chain (`Schema::string()->format(Format::Email)->min(3)` accepted any string and exported no `format`). `regex()` / `regexUnchecked()` on a schema that already has a format now throw the "mutually exclusive" `InvalidArgumentException`, as `format()` after `regex()` always did. Fixes #38.

## 1.0.0 — 2026-11-01

- Stable release. The public API and behaviour are those of 0.7.0, checked
  in the consumer pilot (cska-one, ecosystem-sandbox); from here on SemVer
  applies strictly.

## 0.7.0 — 2026-11-01

- `ParseResult::valueOr($default)`: typed access without an exception
  (`TValue|TDefault`). Psalm cannot narrow `->value` by `->ok`, so
  `$r->ok ? $r->value[...] : null` stayed nullable; `valueOr(null)` plus a
  null check keeps the plugin's exact shape (ecosystem-sandbox pilot).
- README: the "event is one schema" recipe needs `schema()` declared as
  `IntoSchema` for the typed `toWire()`; an interface's `SchemaInterface`
  return type may be narrowed in the implementation.

## 0.6.0 — 2026-11-01

Findings of the consumer pilot (ecosystem-sandbox, cska-one) before 1.0:

- **Behavior change:** `ParseError::toPath()` quotes keys that are not plain
  names (`[A-Za-z_][A-Za-z0-9_]*`): `$['a.b']`, `$['user@example.com']`,
  `$['0']` — `$.a.b` was ambiguous between the key `a.b` and the keys `a`,
  `b`. Segments and `toJsonPointer()` are unchanged.
- **Behavior change:** an unknown key is reported as `Unknown property` at
  its path; the message no longer repeats the key. Input keys appear in
  paths only, never in messages (stability contract wording updated).
- `ParseResult::$value` is typed `TValue|null`, so the shape the Psalm
  plugin infers is visible on the result, not only through `orThrow()`.
- `toWire()` of `into()` and object schemas is typed `array<string, mixed>`
  for an object / array input: the "event is one schema" recipe needs no
  `@var`.
- README: the plugin does not infer constraints (`int<0, max>`,
  `non-empty-string`); annotate a schema returned from a helper method;
  the recipe pays off for multi-field payloads; `parseJson()` attaches no
  `JsonException`.

## 0.5.0 — 2026-11-01

- **BC:** one unknown-key mode per object, the new `Unknown` enum
  (`Reject` — the default, unchanged — `Strip`, `Passthrough`), set by
  `Schema::object([...], unknown: Unknown::Strip)` or `->unknown(...)`.
  Replaces the `bool $additionalProperties` factory argument, the
  `additionalProperties()` and `strip()` methods and the
  `$additionalProperties`/`$strip` properties. Migration:
  `additionalProperties: true` / `->additionalProperties()` →
  `unknown: Unknown::Passthrough`; `->strip()` → `unknown: Unknown::Strip`.
  Parse, export and wire behaviour of each mode is unchanged.
- **BC:** `SchemaInterface::parseJson(string $json)`: decode + parse, invalid
  JSON is a root error `Invalid JSON (…)` instead of a misleading type error
  about `null`. Custom `SchemaInterface` implementations must add it
  (`SchemaType` subclasses inherit it).
- **Behavior change:** `int`/`float` bound messages no longer echo the
  input (`Int must be at least 18`, was `…, got 17`) — the package's own
  rule that messages carry kinds and sizes, never values. Lengths and
  counts stay in the messages.
- Fix: numeric property names (`'0'`, which PHP stores as int `0`) work
  everywhere — `arbitrary()` no longer dies with a `TypeError`, error
  segments stay strings (`$.0`, not `$[0]`).
- `ParseResult::orThrow()` (the value or a `SchemaException`) and
  `ParseResult::summary(int $limit = 3, string $separator = '; ')` — the
  one-line `$.path message; … (+N more)` consumers kept re-implementing.
  `ParseError` is `Stringable` (`$.path message`).
- `Schema::literals([...])` → `Type\LiteralsSchema`: one of a fixed set of
  constants, strict `===`, export `enum` — the value set without a PHP enum.
- `ObjectSchema` composition: `extend()`, `pick()`, `omit()`, `partial()`;
  the unknown-key mode survives every step.
- `ObjectSchema::requireAnyOf(...)`: at least one of the named properties
  must be present (a default counts); groups accumulate; the generator
  satisfies them constructively; export `anyOf`/`allOf` of `required`.
- `ObjectSchema` is generic (`ObjectSchema<TShape>`), and the package ships a
  Psalm plugin, `Rasuvaeff\Schema\Psalm\Plugin`: `Schema::object([...])`
  infers the exact `array{...}` shape (optional keys `name?:`, passthrough
  objects open), carried through `extend`/`pick`/`omit`/`partial`/`unknown`.
  `vimeo/psalm` is a suggested dependency.
- `yiisoft/validator` bridge: `Bridge\YiiValidator\SchemaRule` +
  `SchemaRuleHandler` — a schema as a validator rule, errors at their own
  paths (`payload.items.1.sku`). `yiisoft/validator` is a suggested
  dependency.
- README: unknown-key modes, composition, the Psalm plugin, the
  "an event is one schema" recipe (`into()` + `toWire()`; `parse()` is the
  inverse, no separate `fromWire()`), the validator bridge and the 1.x
  stability contract. Three new runnable examples; `queue-payload.php`
  checks parity through the wire form (a `dateTime` generates
  `DateTimeImmutable`, so the direct parse it printed was `false`).
- Internal docs: AGENTS.md no longer calls JSON Schema export "planned for
  1.1".

## 0.4.0 — 2026-10-11

- Badges (stable version, downloads, build, static analysis, Psalm level,
  PHP, license) and a package description in the sibling packages' style.
- `string()` rejects invalid UTF-8 up-front, before any length, format or
  pattern check — the domain is JSON strings, so the code-point length and
  the pattern engine never disagree about the input (#12).
- A PCRE engine failure during a pattern check (e.g. backtrack limit
  exhausted) is reported as its own error — `Pattern check failed (PCRE
  engine error: …)` — never as a plain pattern mismatch (#11).
- **BC:** `SchemaException` now extends `\InvalidArgumentException`
  (invalid input, semantically) instead of `\RuntimeException`. Code
  catching it as `\RuntimeException` must catch `SchemaException` or
  `\InvalidArgumentException`; everything else is unchanged (#16). Fits
  the pre-1.0 SemVer allowance.
- `IntSchema::positive()` (>= 1) and `nonNegative()` (>= 0) — the named
  intent of ids and counters, thin wrappers over `min()` with identical
  parse, generator and JSON Schema export (#17).
- `Schema::float()` — the number type: finite floats only (NaN/INF
  rejected), inclusive `min()`/`max()`, `allowInt()` widening parse to
  whole numbers (normalized to float), generator over the same range,
  export `type: number` with `minimum`/`maximum` (#6).
- `Schema::dateTime()` — RFC 3339 string on the wire, `DateTimeImmutable`
  in the PHP domain, inclusive `min()`/`max()` bounds, generator over the
  same range, export `type: string, format: date-time` (bounds recorded
  as a `NonRepresentable` entry — pure draft 2020-12 has no keyword for
  them), `toWire()` formats back to the string (#15).
- `Format::DateTime` strictness aligned with `Schema::dateTime()`:
  rolled-over components (`25:00`) and trailing data are now rejected
  instead of silently rolling over (#15).
- `Schema::discriminated('kind', [...])` — a tagged union: the
  discriminator property selects the branch (checked eagerly as a
  required literal equal to the branch key, so branches are disjoint by
  construction), an unknown or missing tag is its own error at the tag's
  path, and errors inside the branch keep their full paths. Export is
  `oneOf`. Int and string tags both work (#9).
- `Schema::tuple([...])` — a fixed-length heterogeneous list
  (`prefixItems` + `items: false`), errors carrying their element index
  (`$[1]`) (#9).
- `ObjectSchema::strip()` — the third unknown-key mode: drop unknown keys
  and keep the known ones (the shape for parsing external payloads you
  only trust partially). Mutually exclusive with
  `additionalProperties()`; the export keeps `additionalProperties: true`
  and records the drop as a `NonRepresentable` `strip` entry (#13).
- **Behavior change:** `additionalProperties()` now passes unknown keys
  **through into the parse result** (the mode's documented purpose —
  round-tripping partially known maps); previously they were tolerated
  but silently dropped, making it indistinguishable from strip (#13).
- Optional `coerce()` on `Schema::int()`, `Schema::float()` and
  `Schema::bool()`: canonical number/bool strings from query strings and
  forms are accepted and normalized to the typed value (bounds apply
  after the coercion; no leading zeros, no whitespace, no overflow; bool
  strings are the four unambiguous `true`/`false`/`1`/`0`). The
  generator's domain stays typed; each export keeps its canonical type
  and records a `NonRepresentable` `coerce` entry (#8).
- `UnionSchema` failures now keep structured branch errors:
  `ParseError->branches` (`list<UnionBranch>` with the member index and
  the branch's own error paths) instead of a flattened first-error
  summary; `SchemaException` renders them indented, and parents prepend
  their segment to branch paths (#10).
- `ObjectSchema::into(Dto::class)` → `Combinator\IntoSchema`: optional
  hydration of the parsed properties into a DTO via its named-argument
  constructor (after defaults and `strip()`); a throwing constructor is a
  parse error carrying class names only; the export and the wire form stay
  the object schema's own (#14).
- `StringSchema::regexUnchecked($pattern, $generator)`: an explicit opt-out
  for valid PCRE patterns outside the generatable subset (`\z`, lookaround,
  backreferences) — the caller supplies the generator, the export records a
  `regexUnchecked` `NonRepresentable` entry; the `regex()` rejection message
  points to it (#7).
- `Testing\RoundTrip` (+ `Testing\RoundTripException`): framework-agnostic
  parity assertions for consumers — `assertParses()`, `assertWireRoundTrip()`
  and `assertConsumerRoundTrip()` for a hydrate/wire pair — so the law is
  defined once next to the schema (#18).
- `Schema::dateTime()` generator emits millisecond-precision moments (the
  wire precision) inside the bounds aligned inwards; a value generated at a
  sub-millisecond bound no longer falls outside it after `toWire()`. Bounds
  that admit no millisecond-precision moment are rejected at construction.


## 0.3.0 — 2026-10-10

- Instance `->nullable()` on every schema — the chained twin of the
  `Schema::nullable(...)` facade factory (`Schema::int()->min(1)->nullable()`),
  symmetric with the other combinators. Found by the keppio message wave: the
  facade-only form tripped a consumer mid-chain on day one. Both forms stay
  valid; no behavior change.

## 0.2.0 — 2026-10-10

- Public `toWire()` port on `SchemaInterface`: the PHP→wire projection is
  out of `Internal\` — an enum instance becomes its backed value, containers
  convert recursively, a transform's declared `wire` callback is applied,
  everything else passes through. The inverse pair of `arbitrary()`:
  generated or parsed PHP values handed to queues, HTTP and JSON storage.
  Found by the first consumer pilot (keppio): a queue property test needed
  exactly this projection. Law 2 now dogfoods the public port.

## 0.1.0 — 2026-10-10

- Initial release: schema-as-code combinators with three ports wired to one
  definition — `parse()` (runtime validation with structured error paths,
  rendered as `$.tags[2]` and RFC 6901 `/tags/2`), `arbitrary()`
  (a `rasuvaeff/property-testing-core` generator over the exact same value
  set) and `toJsonSchema()` (a pure draft 2020-12 fragment through
  `JsonSchemaExport`). The parity between the ports is the package's law,
  pinned by its own property suite over random schema trees: generated
  values parse (source domain), and their wire projection validates against
  the export through opis (wire domain).
- Types: `string` (code-point length bounds, full-match pattern, named
  formats email/uuid/uri/date-time/ipv4), `int` (bounds), `bool`,
  `literal`, `enum` (backed; accepts an instance or the backed value,
  returns the instance), `json` (the `json_decode` domain), `arrayOf`
  (list, count bounds), `mapOf` (string keys, count bounds), `object`
  (required/optional/default properties, `additionalProperties`), `union`
  (first success wins), `nullable`, `lazy` (recursive shapes, factory
  memoized).
- Combinators: `transform` (target-space generation, source-space shrinking,
  optional inverse for round-trip laws, optional `wire` PHP→JSON converter
  and `jsonSchema` codec — strict export throws without a codec, the
  declared codec is checked against the generator's actual output),
  `refine` (post-condition; filtered generation; exports as an honest
  superset plus a `NonRepresentable` entry).
- Object semantics: an absent optional key is absent from the result (not
  null); `default()` implies `optional()`, is validated against the schema
  at construction and stored normalized (an enum default may be given as
  its backed value). The `default` JSON Schema annotation goes through the
  wire seam. Error messages never embed input values — kinds and sizes
  only.
- `regex()` accepts the `Gen::stringMatching` convention (no delimiters)
  and eagerly probes generatability: a pattern the generator cannot
  compile is an authoring error at construction, not an explosion in
  `arbitrary()`.
