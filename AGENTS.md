# AGENTS.md — schema

Guidance for AI agents working on this package. Read before changing code.

## What this is

`rasuvaeff/schema` (namespace `Rasuvaeff\Schema`) is schema-as-code: one
`Schema::object([...])` (or any other combinator tree) defines a set of values
with **two ports wired to the same definition** — `parse()` validates runtime
input (queue payloads, webhooks, third-party responses) and `arbitrary()`
yields a `rasuvaeff/property-testing-core` generator over the exact same set.
A JSON Schema export port (`toJsonSchema()`, `JsonSchemaExport`) is planned
for 1.1. Entry point is the `Schema` facade; types live in `Type\`,
combinators in `Combinator\`; `ParseResult`/`ParseError` carry structured
paths (segments — rendered as `$.tags[2]` and RFC 6901 `/tags/2`).

## Golden rules

1. **Verification is mandatory.** Never claim "done" without a fresh green
   `composer build`. "Should work" does not count.
2. **No suppressions.** No `@psalm-suppress`, no baseline. Fix the root cause.
3. **The parity law is the product.** Everything `->arbitrary()` produces must
   pass `->parse()` — for every type, every constraint, every combinator
   (`SchemaParityPropertyTest` pins this over random schema trees). When you
   touch a type's constraints you touch both sides in the same commit: a
   constraint the parser enforces but the generator ignores (or vice versa)
   is not a missing feature, it is a broken package. `default($v)` is
   validated against the schema at construction — an authoring error, not a
   runtime surprise.
4. **Preserve the public contract.** Update README.md + README.ru.md + llms.txt + tests with any API change.

## Commands

No PHP/Composer on the host — run in Docker via the `composer:2` image.

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 composer build
docker run --rm -v "$PWD":/app -w /app composer:2 composer cs:fix
docker run --rm -v "$PWD":/app -w /app composer:2 composer psalm
docker run --rm -v "$PWD":/app -w /app composer:2 composer test
docker run --rm -v "$PWD":/app -w /app composer:2 composer release-check
```

Or with Make:

```bash
make build
make cs-fix
make psalm
make test
make test-coverage
make mutation
make release-check
```

`composer.lock` is gitignored (library).
`make test-coverage` and `make mutation` bootstrap `pcov` inside the
`composer:2` container because the base image has no coverage driver.

## Invariants & gotchas

- **Two domains: PHP values and wire.** `parse`/`arbitrary` work with PHP
  values (an enum stays an instance); anything JSON-flavoured goes through
  a `toWire` projection (`Internal\Wire`) — an enum instance is never fed
  to a JSON Schema validator directly. Law 1 (generated ⇒ parses) runs in
  the source domain — a bare transform's generator produces the target
  space, so transform-bearing trees belong to law 2 only; law 2 (wire of
  generated validates against the opis-checked export) runs in the wire
  domain with schema-aware stdClass conversion (opis reads PHP arrays as
  JSON arrays, stdClass as objects; an empty map is `{}`, never `[]`).
- **optional ≠ nullable.** `optional()` is about the presence of an object
  key (absent key stays absent in the parse result); `nullable()` is about
  the value. `default()` implies `optional()` and stores the parsed,
  normalized value (enum default given as a backed value is kept as the
  instance).
- **Error messages never embed input values** — kinds and sizes only
  (`get_debug_type`, lengths, counts). Payloads may carry secrets; errors
  travel to logs and Sentry. A transform callback that throws contributes
  its class name only.
- **Union: first success wins, in declaration order.** Overlapping members
  are an authoring error the parser forgives silently.
- **Combinator order:** `->min(1)->transform(...)->optional()` — property
  modifiers last; `ObjectProperty` has no combinators by design.
- **Gen mapping conventions:** union → `Gen::frequency` of members
  (`Gen::oneOf` takes values, not generators); transform → `Gen::map`
  (shrinks in the source domain — the core's guarantee, do not re-wrap);
  refine → `Gen::filter` (prefer a constraint; refinements burn generation
  budget on discards); enum → `Gen::enum` (instances).
- **`regex()` accepts the core's pattern convention** — no delimiters, no
  flags — and eagerly probes `Gen::stringMatching`: a pattern the
  generator cannot compile (`\z`, and anything else outside the core's
  PCRE subset) is an authoring error at construction, not an explosion in
  `arbitrary()`. Use `$` (the `$`-vs-`\z` trailing-newline nuance is the
  price of guaranteed parity).
- **Property generators live in `tests/Support/*` as `public static`**
  providers (`SchemaTrees`) — a private static helper in a test class turns
  rector red on release-check.
- **Test doubles come from understudy** (`Understudy::for`, `when(...)->returns/throws`)
  — never hand-written fakes.
- Code: `declare(strict_types=1)`, `final readonly class` (LazySchema is the
  memoization exception), `#[\Override]`, explicit types.
- `examples/` is part of the public contract: keep scripts runnable and update
  `examples/README.md` when example usage changes.
- **CI workflows are SHA-pinned.** Every `uses:` in `.github/workflows/*.yml`
  references a 40-char commit SHA with a `# vN` trailing comment
  (e.g. `actions/checkout@<sha> # v4`). Never revert to floating `@vN` tags.
  Updates go through Dependabot, which bumps the SHA and preserves the comment.
  Workflows also carry `permissions: { contents: read }` at workflow level and
  `persist-credentials: false` on every `actions/checkout` step. Verify with
  `zizmor --persona=auditor .github/` — must report no `unpinned-uses`,
  `excessive-permissions`, or `artipacked` findings.

## When you finish

- Update `README.md` **and `README.ru.md`** (both languages, same commit;
  and `examples/` if usage changed); update `CHANGELOG.md` when releasing.
- Re-run `composer build`; if the change affects public API or release safety,
  also run `make release-check`. Paste the output.
