# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Unreleased

- Initial release: schema-as-code combinators with two ports wired to one
  definition — `parse()` (runtime validation with structured error paths,
  rendered as `$.tags[2]` and RFC 6901 `/tags/2`) and `arbitrary()`
  (a `rasuvaeff/property-testing-core` generator over the exact same value
  set). The parity between the two is the package's law, pinned by its own
  property suite over random schema trees.
- Types: `string` (code-point length bounds, full-match pattern),
  `int` (bounds), `bool`, `literal`, `enum` (backed; accepts an instance or
  the backed value, returns the instance), `json` (the `json_decode`
  domain), `arrayOf` (list, count bounds), `mapOf` (string keys, count
  bounds), `object` (required/optional/default properties,
  `additionalProperties`), `union` (first success wins), `nullable`,
  `lazy` (recursive shapes, factory memoized).
- Combinators: `transform` (target-space generation, source-space shrinking,
  optional inverse for round-trip laws), `refine` (post-condition; filtered
  generation).
- Object semantics: an absent optional key is absent from the result (not
  null); `default()` implies `optional()`, is validated against the schema
  at construction and stored normalized (an enum default may be given as its
  backed value). Error messages never embed input values — kinds and sizes
  only.
