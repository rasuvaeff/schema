# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
