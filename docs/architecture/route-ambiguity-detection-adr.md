# ADR: Route ambiguity detection — removed, and not to be rebuilt

> **Status:** Accepted, 2026-10-09
> **Supersedes:** the route-ambiguity scan previously in `loadModuleRoutes()`
> **Related:** `0572164a` (removal), `9126dee4` (the indexing that preceded it)

---

## Context

`loadModuleRoutes()` contained a scan that walked every previously registered route pattern for
every pattern of every module, for every method, and emitted a `[cert_blocker]` diagnostic
(and, in `block` mode, refused to register the route) when two patterns appeared ambiguous.
It was added as a certification guard for cases such as `/foo/{id}` versus `/foo/bar`.

By 2026-10-09 the corpus was 1,968 route patterns. The scan performed **927,972 pairwise
comparisons per request**, on every request, on every page — roughly 200–400 ms of CPU before
any handler ran. It was the single largest per-request cost in the kernel.

## Decision

**The scan is removed. Route-ambiguity detection is not to be reintroduced.**

## Reasons

### 1. The scan could never report anything

`routePatternMatchPriority()` returns:

```php
return [$typeRank, $segmentCount, $staticCount, -$dynamicCount, $pattern];
```

The **final element is the raw pattern string**, and `compareRoutePatternsForMatching()` ends
with `strcmp()` on it. The diagnostic required
`routePatternsCouldConflictCheap() && routePatternsMayConflict() && compareRoutePatternsForMatching() === 0`.
The third term is therefore true **only when the two pattern strings are identical** — and
identical patterns were already excluded two lines earlier by the `$existingPattern === $pattern`
check.

Measured over the real corpus:

```
same-method pairs                                927,972
routePatternsMayConflict() true                      109
of those, compare()===0 on DISTINCT patterns           0
```

An adversarial search over 36 distinct conflicting pattern shapes (`/a/{x}` vs `/a/b`,
`/a/{x}/c` vs `/a/b/c`, `/{x}/b` vs `/a/b`, `/a/b/{y}/d` vs `/a/b/c/d`, …) found **0
counterexamples**. The diagnostic had never fired, for any corpus, since it was written; and
because `$blockedByAmbiguity` could only be set inside that unreachable branch, it was always
`false` — so `APP_ROUTE_AMBIGUITY_MODE=block` **never blocked anything**.

### 2. There is no ambiguity class left to detect

The dispatcher orders patterns with `compareRoutePatternsForMatching()`, which ranks literal
segments above dynamic ones. For two distinct patterns that ordering is **total**, so the
dispatcher always resolves them deterministically — `/foo/bar` wins over `/foo/{id}` by
specificity, not by accident.

The one class that would be a genuine ambiguity is two patterns of *identical shape* with
different parameter names (`/foo/{id}` vs `/foo/{slug}`): both match the same URIs, and the
winner would fall to `strcmp` on the pattern string rather than to specificity. Measured over the
corpus: **zero instances**.

Deleting the scan is therefore not a reduction in protection. Nothing it could have caught
existed, and nothing it could have caught is left undetected.

### 3. The real conflict class is already covered, cheaply

Two modules registering the **identical** pattern is a genuine conflict. It is handled by the
`$routeOwners` duplicate-ownership guard — O(1) per route, unaffected by this decision, and
verified to still reject the second registration.

## Consequences

- **`APP_ROUTE_AMBIGUITY_MODE` is a compatibility-only setting.** `warn` and `block` are both
  accepted so no deployment breaks, and both do exactly nothing to routing. This is stated in
  `.env.example` and `docs/kernel/cli-tools-reference.md` because the previous wording implied a
  protection that did not exist.
- The route map must remain byte-identical across this change — verified at
  `routes=1968 raw_sha=cf47a110930f5733`, and identical under `warn` and under `block`. The route
  map hash is the regression oracle for anything touching this area.
- `kernel/DiSyL`, `templates/`, `modules/` and `tools/` are untouched by this decision.

## Reopening this decision

Reopen only if a **concrete instance** appears, not on the theory that one might. The trigger is:

- two registered patterns that can match the same URI set where neither is more specific, i.e.
  identical segment shape with differing parameter names — report the actual pair; or
- a shipped defect traced to a route being shadowed.

If that happens, the fix is **not** a per-request scan. It is a certification check on the CLI
surface (`php ikabud routes …`, exiting non-zero so a release gate can fail on it), sharing one
predicate definition with any runtime enforcement so the two cannot diverge.

## Known, harmless, not addressed

`modules/harpp/routes.php:11` registers `'/harpp/'` alongside `'/harpp'` (`:10`), both mapped to
`harpp:harppPageMessenger`. The dispatcher normalises request URIs with `rtrim($uri, '/')`
(`public/index.php:77`, `:300`, `:504`), so `'/harpp/'` can never match and is a dead entry. It is
harmless — `'/harpp'` handles the same handler — and was deliberately left alone rather than
touching a module for a cosmetic dead route. Recorded here so it is not rediscovered as a mystery.
