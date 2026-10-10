# CMS Page Builder — Review, 2026-10-10

Scope: the CMS page builder end to end — document model, widget registries, the editor
canvas, the public renderer, ARK/native theme integration, and the guards that protect the
builder↔theme boundary.

Method: `architect`-style recon → ChatGPT chair consult (`.ai/consult/pagebuilder-review-2026-10-10.md`)
→ probe-first defect hunting (run every builder test, then diff the two renderer registries)
→ fix → guard → Sol review of the diff. No finding below is asserted without a command or a
file:line behind it.

## What the builder actually is (measured)

| Surface | Owner | Size |
|---|---|---|
| Public renderer | PHP — `modules/cms/builder-renderers.php` | 60 `cmsRenderWidget_*` renderers, 2 925 lines |
| Render entry point | `helpers/50-builder.php:cmsBuilderRenderDocument($document, $context)` | takes an **arbitrary in-memory document** |
| Editor canvas | React — `modules/cms/builder-ui/src/builder/components/NodeRenderer.tsx` | 5 169 lines, 59 dispatched types |
| Widget vocabulary | `builder-ui/src/builder/core/types.ts` (`CMSComponentType`) | 60 types |
| Widget definitions | `builder-ui/src/builder/core/components.ts` (`CMS_COMPONENTS`) | 60 definitions |
| Theme contract | `storage/cms-themes/ark/style.css` (+ byte-identical public copy) | 58 KB |

The docs lag this badly: `docs/page-builder/page-builder-roadmap.md` still describes Phases 1–7
as "Not started"/"Proposed" while the code implements most of them. Worth correcting, because a
stale roadmap is how a working feature gets rebuilt.

## Findings

### F1 — The canvas is a parallel re-implementation with no parity guard *(architectural, open)*

`cmsBuilderRenderDocument()` is already the single server entry point for **both** the preview API
and public output — but the canvas does not call it. The canvas re-renders all 60 widgets in React,
so preview-vs-public parity is maintained by hand across two languages and two widget counts
(59 dispatched vs 60 registered).

Registry *counts* agreeing proves type coverage, not rendering parity.

Chair verdict (2026-10-10): **GO** on parity testing immediately; **GO** on an authoritative
server-rendered canvas as the architectural destination; **NO-GO** on a wholesale canvas replacement
before an interaction prototype — render one difficult interactive widget (a carousel, which
exercises width, overflow, child layout, state and pointer interaction, i.e. the exact area of the
recent ARK regression) and one deeply nested composition, and measure editing latency.

Feasibility is already established: `cmsBuilderRenderDocument()` accepts an unsaved document, and
`handlers/20-api-builder.php:cmsApiBuilderDocumentPreview()` already accepts a **posted** document
and returns a full themed HTML page for browser preview. The chair flagged this as unproven; it is
proven.

### F2 — `ai_block` was invisible in the canvas *(fixed)*

The widget is selectable (`CMS_COMPONENTS`, `types.ts:88`), has a PHP renderer
(`cmsRenderWidget_ai_block`), and has an author-time prompt/Generate UI in `PropertiesPanel.tsx`.
`NodeRenderer.tsx` had **no `case 'ai_block'`**, so it fell through to the default branch and drew a
bare `<div>` with children — the author saw nothing while the public page rendered correctly.

Fixed by adding `AIBlockRenderer` mirroring `cmsRenderWidget_ai_block()` (paragraph split on `\n\n`,
single newline → `<br>`, dashed empty-state placeholder) and the missing dispatch case. Builder UI
rebuilt (`public/assets/cms/builder/builder.js`).

### F3 — `entity_list` warned on every empty render *(fixed, kernel)*

`kernel/DiSyL/Component/ComponentRenderer.php:2111` read `$attrs['empty']` unguarded in the empty-state
branch, so `{ikb_entity_list}` with no rows and no `empty` attribute emitted
`Undefined array key "empty"` into the error log on every render. One-line fix; semantics unchanged
(falsy still falls through to the view's `empty_state`).

### F4 — ARK restyled builder nodes, contrary to stable contract §12 *(fixed)*

§12 (`docs/kernel/kernel-stable-contracts.md`) owns builder nodes to the builder: *"the theme may
style around them, never restyle them"*, and *"a theme rule whose selector names a `cms-builder-*`
class is a finding, not a fix"*.

On **2026-10-07**, ten days after §12 was written, ARK gained:

```css
.cms-builder-document>.cms-builder-node--section { max-width: var(--ark-max-width, 1280px); … }
.cms-builder-document>.cms-builder-node--section:has([style*="--ikb-fullbleed-width"]) { max-width: none; }
```

The **intent was right** and the accompanying CSS comment documents it well — ARK released its own
shell cap for builder documents, sections kept the ARK gutter, and a full-bleed section was released.
But §12 says the fix belongs in the builder even when a theme rule is the smaller patch.

Resolved by moving the gutter into `public/assets/cms/cms-public.css` (the builder's own stylesheet)
and having ARK publish the gutter as a **design token on its own element**:

```css
/* ARK — its own element, no builder class in the selector */
.ark-main { --ikb-content-max-width: var(--ark-max-width, 1280px); }

/* cms-public.css — the builder's node, the builder's rule */
.cms-builder-document > .cms-builder-node--section {
    max-width: var(--ikb-content-max-width, none);
    margin-inline: auto;
}
```

Computed result for ARK is identical (verified: the only other `max-width` rules in ARK target
`.ark-topbar__inner`, `.ark-content-area`, `.ark-content--standalone`, `.ark-region-footer__inner`,
`.ark-region-footer-bar__inner` — none is a section). Non-ARK themes never declared the token, so the
`none` fallback plus `width: 100%` resolves the auto margins to zero — a no-op.

ARK now contains **zero** `.cms-builder-node--*` selectors; it references `.cms-builder-document`
only inside `:has()` on its own `.ark-content-area`/`.ark-main`.

**Nuance, so the principle is not over-read.** `native-default` carries a large body of
`.cms-builder-node--*` styling of its own (`themes/native-default/css/components/_page-builder.css`)
— typography, `img`, button hover. That is the theme expressing its design language *underneath* the
author's intent, which is not the §12 failure. The §12 failure was **precedence**: ARK's
`max-width: 1280px` overrode an author's full-bleed instruction, which is why the fix needed a
`:has()` release clause. A rule banning a theme from naming any builder class would be stricter than
§12 is and would have condemned `native-default` too; what the guards assert is the arrangement ARK
now uses, and what the review argues is that *overriding authored intent from the theme* is the thing
to avoid.

Verified inert elsewhere: `--ikb-content-max-width` is declared by ARK only, so for every other theme
the new rule resolves to `none`; `native-default` never set `max-width` on a section and does not
declare `--ikb-fullbleed-*`, so it keeps the legacy escape — its behaviour is unchanged.

### F5 — The fallback CMS public layout never loaded the builder stylesheet *(fixed)*

`templates/modules/cms/layouts/public.disyl` linked `theme_style_url` but not `cms_public_css_url`.
The ARK layout's own comment states the requirement — *"any theme that renders builder content must
load it"* — and this is the layout used when a theme supplies none. Every builder remedy that §12
relocated into `cms-public.css` (node geometry, prose typography, the lightbox, the eyebrow contrast
fix, and now the section gutter) was silently inert on any page served through it.

Fixed by linking `cms_public_css_url` before `theme_style_url`, matching the ARK layout.

### F6 — Two guards punished the correct fix *(fixed/refined)*

`tests/builder_precedence_over_theme_test.php` asserted the mechanism the 2026-10-07 fix **replaced**:
a literal `$style['width'] = '100vw'`, and `.ark-main:has([data-node-id]) { overflow-x: visible }`,
plus a blanket "no `cms-builder-*` text in ARK". Six assertions were therefore red against correct
code. A guard that is red for a wrong reason is worse than no guard, because it teaches people to
ignore red.

Rewritten to assert the **ownership boundary and its consequences** — the §12 table, the
token-driven gutter, the theme-overridable full-bleed escape, the *retained* overflow guard (the
superseded mechanism disabled it, which let off-canvas carousel tracks create a real horizontal
scrollbar), stylesheet delivery, the AA eyebrow remedy, and source/published byte-parity. 21
assertions, all green, and each of them can fail.

`tests/ark_ux_quality_test.php` carried the same blanket ban. Refined to ban **styling** builder
output while allowing a theme to **detect** a builder document from its own element — the exact
distinction the chair drew ("do not ban theme selectors targeting `.cms-builder-document`; that goes
too far").

### F7 — Four themes still never load the builder stylesheet *(open)*

`minimalist`, `minimal`, `entity-native` and `entity-commerce-poc` all ship a
`layouts/public.disyl` that omits `cms_public_css_url`. ARK and `native-default` load it. Builder
content rendered under any of those four gets no builder CSS. Not fixed here: adding the stylesheet
changes their appearance, and it is not established that they render builder content or are
installed at all. Decide per theme.

### F8 — Load order is inconsistent and undocumented *(open)*

ARK loads `cms-public.css` **before** the theme stylesheet, explicitly so *"the theme still wins
every conflict by source order"*. `native-default` loads it **after**. §12 precedence is in fact
enforced by the ownership boundary, not by source order, so both work — but the inconsistency should
be a written decision rather than an accident of two layouts.

### F9–F11 — Pre-existing failures outside this scope (verified at HEAD)

Confirmed red with the working tree stashed, i.e. **not** caused by anything here:

| Test | Result at HEAD |
|---|---|
| `tests/ark_ux_quality_test.php` | 16 passed, 6 failed — 5 are missing ARK storefront colour tokens (`.ark-commerce-muted`/`-accent`/`-success`/`-warning`/`-danger`), ecommerce scope |
| `tests/ark_renderer_runtime_test.php` | 13 passed, 4 failed — article-grid/article-page template resolution |
| `tests/architecture_component_integration_test.php` | fatal: `ResolvedEntityContext::__construct(): Argument #22 ($sourceSchema) not passed` |

## Guards added

`tests/builder_render_parity_test.php` — the cheap, reliable half of parity:

- CMS widget vocabulary, canvas dispatch table and PHP renderer registry describe the **same** set
  of widgets;
- every registered renderer names an existing function, is callable with empty props, returns a
  string, and emits no PHP warning;
- a composed document covering 43 widget families renders through `cmsBuilderRenderDocument()`.

It caught F2 and F3 on its first run. It deliberately does **not** claim visual or prop-level parity:
a prop diff across two languages produced false positives on the first attempt (PHP reads some
props from `$style` where React reads them from `node.props`), and an untrustworthy guard is worse
than none.

## Editor feature inventory vs Elementor-class (closing the chair's gap 7)

The chair asked which Elementor-class features are *genuinely* missing. Measured from the editor
code, not from the (stale) roadmap:

**Already present** — drag & drop with governed nesting constraints (`resolveGovernedParent`,
`mergeArkConstraints`), layers/navigator, structure mode, parent-of-selection cues, resize handles
with flex-aware sizing, **undo/redo** (Ctrl+Z / Ctrl+Shift+Z), duplicate (Ctrl+D), **copy/paste
nodes** (Ctrl+C/V) with governed-parent validation *before* paste, **per-breakpoint styles**
(desktop/tablet/mobile) with viewport preview, global styles (colour, typography, spacing, buttons,
page) plus style presets, templates, reusable blocks, version history, SEO panel, capability panel,
media library with search, inline text editing, context menu, widget search and categories,
ARK/governed block integration with contract validation.

So the roadmap's Phases 2–4 are substantially *delivered* while still described as "Proposed".
Refreshing that document is worth doing, because a stale roadmap is how working features get rebuilt.

**Genuinely missing (verified by absence in the source):**

| Gap | Evidence |
|---|---|
| **Style-only copy/paste** | no `copyStyle` / `pasteStyle` / `applyStyle` / `styleClipboard` anywhere — **implemented, see below** |
| Multi-select **bulk style editing** | no bulk style action; the chair's fallback if style copy/paste had existed |
| Revision **diff/compare** | `VersionHistory` restores, but there is no side-by-side compare |
| Global **find/replace** across a document | absent |
| Dedicated "hide on mobile" affordance | `NodeStyle.visibility` exists, but no purpose-built control |

### Gap 4 closed: the style schema supports generic style operations

The chair made Copy/Paste Style conditional on the schema being uniform enough to avoid a
cross-widget translator. It is:

```ts
export interface NodeStyle {          // types.ts:246 — ONE flat interface, ~60 keys,
  display?: …; padding?: …; color?: …; // every field optional, every value a string/enum
  tablet?: Partial<Omit<NodeStyle, 'tablet' | 'mobile'>>;
  mobile?: Partial<Omit<NodeStyle, 'tablet' | 'mobile'>>;
}
```

`NodeStyle` is not per-widget shaped; responsive variants are nested uniform sub-objects; and
`cmsRenderWidget_*` already maps style keys to `--b-*` custom properties generically. The chair's
falsifier — "if styles are stored in incompatible widget-specific property shapes … do not build a
large cross-widget translator" — does not fire, so the feature is cheap.

## Delivered: token-aware Copy Style / Paste Style

Following the chair's P3 recommendation and its explicit contract (*style only, not arbitrary widget
properties; preserve token references and responsive variants; one paste is one undo step; no
renderer-specific clipboard logic*).

- `types.ts` — `styleClipboard: NodeStyle | null` on `BuilderState`; `COPY_STYLE` / `PASTE_STYLE` actions.
- `useBuilderState.ts` — both cases in `builderReducer`, plus `copyStyle` / `pasteStyle` /
  `hasStyleClipboard`. Paste **merges** (`UPDATE_STYLE` semantics: copied keys win, target-only keys
  survive) so it cannot silently drop a layout the author set. Applying to every selected target in
  one action keeps one paste as one undo step, because history records document identity, not
  individual mutations. `builderReducer` is now exported so the document mutations are testable
  without a React renderer.
- `ContextMenu.tsx` / `PageBuilder.tsx` — "Copy Style" / "Paste Style" entries, and `Ctrl+Alt+C` /
  `Ctrl+Alt+V`. The existing node-clipboard bindings now ignore the Alt chord, so `Ctrl+Alt+C` cannot
  also copy the node.

**Verification:** `modules/cms/builder-ui/scripts/verify-builder-core.cjs`, wired as
`npm run test:core` — compiles `src/builder/core` and exercises the reducer directly. 18 assertions,
all green, covering: style-only capture (props and identity never move), responsive override
transfer, merge-not-replace, source node untouched, exactly one document mutation per paste, and
no-op behaviour for an empty clipboard, an empty target list and unknown targets.

**Not claimed:** no rendered/browser verification. The feature is verified at the state layer, where
the correctness lives; the visual result needs the browser harness below. This is the same boundary
the Sol review drew for the parity guard.

### P1 prerequisite closed: the node-ID join contract

The chair warned that a geometry-parity harness is worthless if node IDs do not survive both render
paths — *"if node IDs do not survive both rendering paths consistently, that prerequisite must be
fixed first."* Measured rather than assumed:

| Observation | Result |
|---|---|
| Markers emitted vs nodes declared | sound — **no invented ids**, **no duplicates** |
| Document root | **had no marker at all** — the editor marks the root, public output did not |
| `visibility: hidden` / `display: none` / condition-false nodes | no marker — **correct**, they render nothing |

So the marker was a sound and unique join key but **not complete**: the root was the one node that
existed in the canvas with no key in the published HTML. Fixed — the root wrapper now carries
`data-node-id` like every other node.

The contract is now asserted, so the harness can rely on it rather than re-derive it: the root is
marked; every emitted marker belongs to a declared node; no node is emitted twice; every node that
renders is marked; and the only unmarked nodes are the deliberately excluded ones.

### A landmine found on the way: the renderer was not self-sufficient

Chasing the root marker surfaced a real defect in the render path.

`cmsBuilderRenderNode()` calls `cmsBuilderGetAnimationAttrs()` on **every** node, but that helper lives
in `modules/cms/animation-definitions.php`, which `helpers/50-builder.php` never loaded — it was only
loaded by `helpers/00-bootstrap.php`. Any caller that loaded just `50-builder.php` +
`builder-renderers.php` — a CLI job, a test, the natural "just the renderer" pair — **fataled on the
first rendered node**. Fixed with a `require_once` in `50-builder.php`, which is idempotent against
`helpers/00-bootstrap.php`.

**This had been hiding inside the new parity guard itself.** Its composed render only passed because
the renderer loop ran first and `cmsRenderWidget_entity_list` happened to pull `helpers/00-bootstrap.php`
in as a side effect — traced by bisecting the 60 renderers. A guard that passes because of an
incidental include inside a loop is not a guard, so the render path is now asserted directly: it must
render **from a cold load, before any renderer has run**.

That is the most useful thing to come out of this pass. The lesson generalises: when a guard's
assertions are reached through an ordering that production does not guarantee, the guard is measuring
its own scaffolding.

## Live builder page under ARK — `/cms/page/ark` (owner report, 2026-10-10)

Content #15077, tenant #1, `content_mode=builder`, `_builder_enabled=1`, `_template=default`.

### Fixed and verified: headings were emitted with a paragraph inside them

The stored heading content for one node is literally `<p>This is a test</p>` — the inline editor
(TinyMCE) saves block-wrapped content — and the heading renderer dropped it in verbatim:

```html
<h2 class="cms-builder-node--heading" …><p>This is a test</p></h2>
```

A `<p>` inside `<h2>` is **invalid HTML**, so the parser hoists it out, leaving an **empty heading**
and the text as an orphan paragraph. The heading was therefore visually wrong (plain centred text)
and semantically absent — no heading for assistive tech or search engines.

Fixed by `cmsBuilderInlineContent()`, which flattens block wrappers for elements that may only hold
inline content, and used by the `heading` and `button` renderers. Inline markup (`<strong>`, `<em>`,
`<a>`) is preserved. Guarded by four assertions in `tests/builder_render_parity_test.php`
(22 passing), and confirmed on the live page: `<h2 …>This is a test</h2>`.

**A reasoning error worth recording.** While chasing this I concluded "it is not the page cache"
because `pageCacheKey()` includes the query string — then spent several cycles looking for stale
compiled code, a second copy of the renderer, and a fragment cache. The fix had been correct from the
start; the *first* fetches were serving cached HTML. Proven by temporarily marking the renderer's
output (`data-renderer-probe`), which appeared live on the next fetch. Reading the cache key was not
the same as observing a cache miss.

### Diagnosed, NOT fixed: the theme draws article chrome over a builder-composed page

The live page renders, in order:

```html
<main class="ark-main">
  <article class="ark-detail">
    <h1 class="ark-detail__title">ARK</h1>                  <!-- the page title -->
    <div class="ark-detail__body ark-content"> …builder document… </div>
    <div style="margin-top:var(--spacing-lg);">
      <a href="javascript:history.back()" …>&larr; Back</a> <!-- article chrome -->
    </div>
  </article>
</main>
```

Three problems, all one cause — a **builder-composed page still receives the theme's detail/article
treatment**:

1. the theme's `<h1>` **duplicates the author's hero `<h1>`** ("The Future of Web Design") — two `<h1>`
   per page, and a large stray "ARK" title above the composition;
2. a **`← Back` history link** that has no meaning on a composed page;
3. the builder document is wrapped in `ark-content`, i.e. treated as article prose.

Candidate templates (from their output markers): `storage/cms-themes/ark/public/entity.view.disyl`
and `storage/cms-themes/ark/entity-views/default-detail.disyl`. The fix belongs in the theme and
mirrors what `full-width.disyl` already does — branch on `builder_enabled` so the theme does not draw
its own headline/meta/nav over a page the builder composed.

**Why it is not fixed here.** I gated the title and back-link in `entity.view.disyl` on
`!builder_enabled`; the live page did not change, and a temporary in-template context probe
(`builder_enabled`, `post_html`, `content_mode`, `public_route_kind`) **did not render at all** —
consistent with either the flag not reaching that template's context, or the compiled-DiSyL cache
serving stale output. I could not determine which without more cycles, so **the edit was reverted**
rather than shipped unverified. Determining which template actually serves a builder page — by
instrumenting the render path, not by inferring from CSS classes — is the next step.

### Also observed: a builder page is confined to a sidebar column

`.ark-content-area` is a flex row; the tenant has a sidebar region, so `.ark-main` is the remaining
**column** (1108px of a 1440px viewport) with an unrelated search widget beside it. An author's
full-width hero is therefore squeezed into 1108px with a large empty area to its right. Same cause as
the sidebar note in the parity-harness section above; whether a builder page should inherit the site
sidebar is a product decision, not a renderer bug.

## Defect reported by the owner: governed nesting blocked top-level composition

**Symptom.** Setting a page with the page builder failed with
`Cannot insert template element section in that governed location.`

**Reproduced before changing anything** (`PageBuilder.tsx:1825` →
`insertNodeWithGovernedFeedback` → `resolveGovernedParent` → `findValidParent` →
`isValidInsertion = canAcceptChild && canBeChildOf`). Measured on the real payload:

| Check | Result |
|---|---|
| `canAcceptChild('document','section')` | **true** — the root's schema lists `section` |
| `canBeChildOf('section','document')` | **false** — governed `allowed_parents` is `["page"]` |
| Verdict | **REJECTED**, with exactly the reported message |

**Root cause — a vocabulary mismatch in the governed layer.** A theme nesting rule takes **precedence**
over the component definition, so a rule expressed in a vocabulary the builder does not have silently
makes placement impossible. ARK's block definitions name the page root **`page`**; the builder's root
node type is **`document`**. ARK's own `page-composition.schema.json` says `root_node.type = "document"`,
so `page` is stale vocabulary — and **24 of ARK's 57 block definitions** carry it, including
`section.json` (`allowed_parents: ["page"]`, and nothing else).

So the root accepted a section and the section rejected the root, and **nothing** could be placed at the
top level — via a template, the insert panel, or a block.

**Fix — at the translation layer, not in 24 theme files.** `cmsBuilderLoadNestingConstraints()` is the
layer whose job is mapping theme vocabulary onto builder vocabulary, so it now translates the root alias
`page` → `document` for both `allowed_parents` and `allowed_children`. Rewriting the theme JSON would
have been a per-file patch of a systemic problem, and `page` may be meaningful to the theme's other
consumers.

**Verification, in the browser, on the reported flow.** Logged into `cmsnew.test`, opened the builder,
Templates tab → Hero → "Simple Hero": **5 nodes inserted, no governed error**. With the alias
translation neutered, the same run reproduces the reported failure (`GOVERNED ERROR SHOWN: true`, zero
nodes added) — so both directions are proven, not just the happy path.

**Guard:** `tests/builder_governed_nesting_test.php` (9 assertions). It asks whether top-level
composition is *possible* — section/container/layout_container/row insertable at the root, no governed
rule still naming the builder-less alias — rather than whether a particular string was rewritten.

One assertion of mine was wrong and was corrected rather than dropped: I first asserted `column` is
insertable at the root, because ARK's page-level schema lists it. `column.json` declares
`allowed_parents: ["row"]`, so a column outside a row is correctly refused; the intersection of the two
rules is the real contract, and that is now asserted as intended behaviour.

### Hazard for repeating this verification: CMS login rate limiting

Five logins inside 300 seconds trip `auth.login_rate_limited` (`max_attempts: 5`,
`window_seconds: 300`, `retry_after: 18`) and the login page returns **HTTP 429**. Any spec that logs in
repeatedly — including the live parity gate — must reuse a session or clear the limit
(`browserClearLoginRateLimit()` does this for the tenant) rather than logging in per assertion. It costs
one failed run to discover otherwise.

## The render-parity harness (chair P1)

`npm run test:parity` → `tests/browser/builder-render-parity.spec.js`, with the fixture generated by
`tools/builder-parity-render.php`.

**Two gates, because they answer different questions.**

*`npm run test:parity` — the renderer, deterministically.* Layout comparison needs the same document,
the same CSS, the same order and the same viewport, repeatably. This renders through the same
authoritative entry point (`cmsBuilderRenderDocument()`), inlines the same stylesheets in the same order
the ARK layout links them, and reproduces ARK's content shell (`.ark-content-area > .ark-main`). The
result is a `file://` page: no server, no database, no login, no cache.

*`npm run test:parity:live` — the canvas against the published page* (below), which is the chair's actual
question and needs a running host.

### Correction: my earlier justification for the static fixture was wrong

I reported that the live path was unusable because *"the render path is tenant- and meta-gated — a
seeded builder page returned 200 with zero node markers"*. **That experiment was invalid and the
conclusion was wrong.** Two independent mistakes:

1. **Wrong host.** I seeded a page into *this* repository's database and then requested it through
   `akiracms.test` — whose vhost `DocumentRoot` is `/var/www/html/**ikabudsix**/public`, a **different
   codebase**, tenant #670 `akira-001`. `cmsnew.test` is the host that serves *this* workspace
   (tenant #1 `applicationos`). The two are not interchangeable.
2. **The fixture was never published.** `browserSeedBuilderDocument()` writes the builder document with
   `status = 'draft'` only, while the public path (`cmsPageBuilderRenderedHtml`) loads
   `status = 'published'`. So the seeded page could not render publicly *on any host*. That — not
   tenant gating — is why there were no markers.

The seed is otherwise fine for what it exists to do (the editor journey spec), but it cannot serve a
public-render comparison, which is why `tools/cms-ensure-builder-page.php` writes **both** rows and is
host-parameterised. With that, the live page renders all 15 node markers.

Worth stating plainly: the static gate would have passed the whole time while the live page was broken
in a way the static fixture did not reproduce — see the sidebar note below.

**Two independent kinds of assertion, on purpose.**

*Structural*, encoding the failure classes that actually occurred — so a regression fails even if
nobody has refreshed the baseline:

- the row's columns sit **side by side** (they stacked when a spurious `max-width` hit flex children);
- they split **33/67** as authored;
- a full-bleed section **reaches the viewport edge**;
- ordinary content **keeps the theme gutter**.

*Baseline*, so any other drift is reported per node instead of discovered by a user.

Viewport is **1600**, not 1280, deliberately: the ARK gutter is 1280, so at 1600 "reached the edge" and
"stopped at the cap" are distinguishable. At 1280 both measure ~1280 and the gate could not tell the
bug from the fix.

### What the harness found immediately

1. **A real, pre-existing rendering bug.** An author's **full-bleed *section*** was capped at the theme
   gutter. The escape style lands on the section element itself
   (`--b-width:var(--ikb-fullbleed-width, 100vw)`), but the release selector was
   `:section:has([style*="--ikb-fullbleed-width"])` — and `:has()` matches a *descendant*, never the
   element itself. So a section authored full-width stopped at 1280px. Fixed in `cms-public.css` by
   also matching the self form; the baseline now shows the section at **1280px** and the full-bleed band
   at **1600px**. This defect predated the §12 ownership move — ARK carried the same `:has()`-only form.
2. **An unfalsifiable assertion in my own design.** I had written a document-level horizontal-overflow
   check (`scrollWidth <= innerWidth`). Injecting an 1800px-wide node proved it **cannot fail**: ARK
   retains `overflow-x: clip` on `.ark-main`, so an over-wide child is clipped and the invariant holds
   no matter how wrong the layout is. Contract §12 says exactly this — *"verify with rendered pixels,
   not `getBoundingClientRect()`; a clipped element still reports its unclipped layout geometry"*. It is
   replaced by the pixel assertions above.
3. **A defect in the PHP parity guard.** The rendered bundle embeds responsive CSS whose selectors are
   written `[data-node-id="…"]`, so counting markers over the raw HTML string sees phantom nodes and can
   report duplicates that are not elements. Both the guard and the tool now strip `<style>` blocks
   before counting.

**Must-refuse evidence.** The stacking injection fails with
`33/67 columns are stacked, not side by side (dy 0 vs 74)` plus a failure screenshot. The full-bleed
assertion failed for real before the fix and passes after it — the strongest form of proof, since it
found a genuine bug. The gutter assertion also failed on a deliberately wrong target while being
calibrated, so it is falsifiable too.

**What the static gate does NOT cover, stated plainly.** It measures the **public renderer's layout**
without site chrome. It does not reproduce the tenant's real wrapper chain, so it cannot see anything
the theme's own shell does — which is exactly how it passed while the live page differed (below). The
corpus is layout-focused by design (rows, nesting, full-bleed, leaf variety), not one node per widget
type, and no screenshot diffing is done: geometry relationships are compared, so a pure
colour/typography change passes.

### The live gate: canvas vs published page (chair P1, the actual question)

`tests/browser/builder-live-parity.spec.js`. Prerequisites are two idempotent tools:

```bash
php tools/cms-ensure-admin.php        --host=cmsnew.test   # creates/refreshes the CMS admin
php tools/cms-ensure-builder-page.php --host=cmsnew.test   # plants the fixture, PUBLISHED + draft
```

It measures the same document on both surfaces, joined on `data-node-id`, and asserts the contract
**both** must satisfy: every node renders; the 33/67 columns share a row; their shares are 33/67; the
two columns together fill the row; and both surfaces expose **the same node ids** — if they did not,
the comparison would be between two different documents.

**Everything it asserts is relative, and that is a deliberate correction.** My first version asserted
*"the full-bleed band is wider than the ordinary section"*. On the live page that failed — and the
renderer was right. The live page has a governed **sidebar**, `.ark-content-area` is `display: flex`, so
`.ark-main` is the remaining **column** (1268px at a 1600 viewport) and both section and full-bleed
render at that column width. An absolute expectation there is a statement about the *site's chrome*,
not about the renderer. It was replaced with the parent-relative shares, which must agree between the
surfaces.

**Its second correction was mechanism-vs-outcome.** I asserted `p_row` is `display: flex`. The canvas
failed while its columns were correctly side by side, because the element carrying `data-node-id` is the
flex box publicly but a plain wrapper in the canvas (an inner element is the flex box). Asserting
`display` on the join-key element tests the implementation, not the layout; it is replaced by "the two
columns together fill the row", which is the outcome.

**Two hazards the gate had to be taught about.**

1. **Public page caching defeats it silently.** While proving the gate can fail, the injected stacking
   regression appeared in the canvas but *not* on the cached published page — so the gate would have
   compared the canvas against a stale document. The public fetch is now cache-busted per run.
2. **The canvas does not reproduce the theme's shell.** The canvas renders in a fixed-width preview that
   has no `.ark-main`, so it does not resolve `--ikb-content-max-width` or `--ikb-fullbleed-*` the way
   the theme does. Widths therefore differ legitimately and are **reported** as an annotation rather
   than asserted; only the authored shares are compared.

**Finding worth carrying forward (not a bug, a product decision).** On a page with a governed sidebar,
a `fullWidth` section renders at exactly the content column width — identical to an ordinary section.
The author's edge-to-edge instruction has no visible effect. It cannot reach the viewport when a
sidebar owns a column, but it is not obvious that it should be indistinguishable from ordinary content
either. Recorded, not silently accepted.

**Must-refuse evidence (both gates).** Injecting `flexDirection: column` on the row into the shared
corpus makes both gates fail with `33/67 columns are stacked, not side by side (dy 0 vs 74)`. The
full-bleed assertion had already failed for real before its fix, and the gutter assertion failed on a
deliberately wrong target while being calibrated — so these are falsifiable, not decorative.

## Sol review of the diff (2026-10-10)

Dispatched through the shared model chain (`tools/model-chain.txt`). Sol returned a full review; the
lane wrapper was killed by an output cap afterwards, but the review itself is preserved at
`.ai/review/sol-run-openai-codex_gpt-5.6-sol.log`.

**Accepted and fixed:**

1. **Duplicate `style` attribute (real bug, PHP).** `cmsRenderWidget_ai_block()`'s editor empty state
   emitted `cmsBuilderStyleAttr($style)` **and** a literal
   `style="padding:16px;border:1px dashed …"` — two `style` attributes on one element. That is invalid
   HTML, and a browser keeps the first, silently discarding the dashed placeholder. Fixed by merging
   the placeholder defaults into the authored style (authored wins), matching React. Verified: exactly
   one `style` attribute, and the placeholder now flows through the builder's `--b-*` custom-property
   pipeline like every other widget. This also satisfies the repo rule that builder style handling must
   not create duplicate/conflicting HTML attributes.
2. **Whitespace-only content diverged.** The canvas tested `!content.trim()` while PHP tested
   `content === ''`, so `"   "` showed the canvas placeholder but published `<p>   </p>`. The canvas now
   matches PHP exactly; the fallback paragraph uses untrimmed content like `nl2br($content)`, and the
   inner split handles `\r\n`/`\r`/`\n` as `nl2br()` does. Public output is unchanged.
3. **Precedence-test loophole.** The detector allowance accepted any `.ark-…:has(…)` selector, so
   `.ark-main:has(.cms-builder-document) p` passed while styling a descendant inside builder output.
   The rule now also requires every compound remaining after the `:has()` group is stripped to be
   theme-owned. **Must-refuse proven:** injecting that selector into the ARK stylesheet turns the guard
   red, and removing it turns it green again.
4. **Link-presence check was comment-permissive.** Both layouts mention `cms_public_css_url` in prose,
   so `str_contains()` would pass with the `<link>` deleted. Now requires a real `<link>` tag.
5. **Sweep narrowed unintentionally.** The rewritten guard read a single ARK stylesheet where the old
   one swept the theme; the recursive sweep is restored so a second stylesheet cannot escape.
6. **Parity guard asserted the type union, not the registry.** It now also parses `CMS_COMPONENTS` and
   asserts the *selectable* registry matches the vocabulary — the difference between "may exist" and
   "the editor offers".
7. **Composed-render warnings were unchecked.** The error handler now stays installed through
   `cmsBuilderRenderDocument()`, with an assertion that fails on any warning it raises.

**Accepted as a stated limitation, not papered over:** the parity guard cannot assert *which* React
component a `case` returns, nor prop-level or visual equivalence. It asserts set agreement and
callability — what a static guard can honestly prove. Visual parity needs the browser harness below.

**Deliberately not actioned:** the load-order inconsistency (F8) and the four themes that omit the
stylesheet (F7). Both change rendered behaviour, and neither can be verified without pixels.

## Recommended next steps

1. **Browser layout-parity harness** (the chair's P1). One fixture per widget type plus nested
   containers, theme blocks, full-bleed sections, narrow viewports and interactive widgets; compare
   `data-node-id`-keyed bounding boxes and computed layout/typography between the canvas and the
   public page. This is the only thing that can actually prove "what the owner sees is what gets
   published".
2. **Resolve the node-ID prerequisite first** — the chair's warning: if node IDs do not survive both
   rendering paths consistently, geometry comparison has nothing to join on.
3. **Prototype the server-rendered canvas behind a flag** on the carousel and one deeply nested
   composition before migrating any widget (F1).
4. **Formalise the theme contract** — `data-content-engine="cms-builder"` on the content region as an
   explicit rendering-intent attribute, instead of relying on `:has(.cms-builder-document)`.
5. **Copy/Paste Style** (chair's P3) — token-aware, schema-governed, routed through the existing
   document mutation/history so one paste is one undo step. Cheap because it manipulates the existing
   document model rather than adding a widget family.
6. Refresh `docs/page-builder/page-builder-roadmap.md` to match the delivered code.

---

## Addendum — ARK composition presentation and two root causes found while verifying it

Owner directive driving this section: *"if it's a patch, then it's a wrong fix. Fix ARK. our flow when
there are issues: fix at the root cause. if ARK is the issue, improve it."*

### A1. ARK now names its two presentations (the fix)

`storage/cms-themes/ark/public/entity.view.disyl` is the **canonical presenter** for pages, posts AND
entities — `cmsPublicPage()` renders it via `public/entity.view.disyl` (cache prefix
`cms:page:entity_contract_v3:`), so article chrome was being applied to a builder-composed page
unconditionally. It now branches **once** on `builder_enabled`:

| Presentation | Trigger | Title banner | Meta / featured image | `history.back()` | Wrapper |
|---|---|---|---|---|---|
| `data-ark-presentation="composition"` | `builder_enabled` | none | none | none | `ark-composition__body` (NOT `.ark-content`) |
| `data-ark-presentation="article"` | otherwise | `<h1 class="ark-detail__title">` | yes | yes | `.ark-content` |

Not being wrapped in `.ark-content` is the point: its prose rules would restyle authored blocks. The
builder owns its own gutter (`--ikb-content-max-width`, cms-public.css); ARK styles around it. Naming
the presentations is what stops article chrome leaking back onto a composed page as a list of
accidental negations.

Verified on **fresh renders**, both directions:

- `/cms/page/ark` (has a builder document) → `ark-detail__title` 0, back link 0, composition 1
- `/cms/product/wms-bridge-fixture-58463147` (no builder document) → title 1, back link 1, article 1
- **must-refuse**: forcing the condition false returned title 1 / back link 1 / article 1

### A2. ROOT CAUSE — the cache key read the template mtime through a symlink

`cmsPublicResolvedTemplateCacheKey()` built its fingerprint from
`BASE_PATH . '/templates/' . $templatePath`, where `$templatePath` is the alias
`_cms_active_theme/public/entity.view.disyl`. `filemtime()` **follows that symlink**, so the mtime
belonged to whatever theme the symlink pointed at — not the active theme. Editing the active theme's
template therefore never changed the fingerprint, and the cached page was served indefinitely.

This is why a correct ARK fix appeared to work and then appeared to stop working. Fixed by resolving
the alias to the active theme's real file before fingerprinting:

```php
if (function_exists('cmsResolveThemeTemplateAliasPath')) {
    $aliasAbsolute = cmsResolveThemeTemplateAliasPath($templatePath);
    if ($aliasAbsolute !== '' && is_file($aliasAbsolute)) {
        $templateAbsolutePath = $aliasAbsolute;
    }
}
```

Evidence: a temporary fresh-render marker appeared on the **first** attempt after this change, and on
none of eight attempts before it.

### A3. Verification trap — two ways a green result lied

1. **APCu in php-fpm is unreachable from CLI.** `apcu_clear_cache()` in CLI clears a different pool
   than the workers serving HTTP, and deleting `storage/cache/pagecache_t1/*` removes only `.idx` and
   `.flush` markers — the HTML lives in APCu. Roughly an hour was lost concluding the template had not
   changed. The remedy is to prove freshness, not to assume it: add a unique marker and re-fetch.
   `templates/_cms_active_theme` → `entity-commerce-poc` while `active_theme = "ark"` made this worse.
2. **DiSyL `{if <undefined-identifier>}` is TRUTHY.** `{if builder_enabled_MUST_REFUSE_PROBE}` took the
   TRUE branch, so the first must-refuse test could not fail and proved nothing. Falsify a DiSyL
   condition with a guaranteed-false comparison (`{if public_route_kind == 'MUST_REFUSE_PROBE'}`).

### A4. Latent defect found, deliberately NOT fixed here

`cmsEnsureThemeSymlink()` — documented as *"Ensure the theme symlink is current on each request
(lightweight check)"* — has **zero callers**. Consequently `templates/_cms_active_theme` still points
at `entity-commerce-poc` while `tenant_module_settings.active_theme = "ark"`. Rendering is currently
correct because `cmsResolveThemeTemplateAliasPath()` resolves by `cmsActiveTheme()` directly, but the
symlink remains the **fallback** in its final `return CMS_THEME_SYMLINK . '/' . $relativePath;`, where
a theme lacking an override can silently inherit a different theme's file.

Left unfixed on purpose: wiring a single global per-tenant symlink that flips per request is racy
across workers, so it needs a decision (make alias resolution authoritative and retire the symlink, or
scope the symlink per tenant), not a patch.

