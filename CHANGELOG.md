# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Unreleased

- Badges (stable version, downloads, build, static analysis, Psalm level,
  PHP, license) and a package description in the sibling packages' style.

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
