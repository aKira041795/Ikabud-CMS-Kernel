# Entity-presentation bridge implementation report

## Answers

**answer 1: yes, the settings reach the entity renderer context, but were previously ignored.**
`ComponentRenderer` already forwarded the unmodified template context to `renderList()` at `kernel/DiSyL/Component/ComponentRenderer.php:2109` (and likewise to `renderDetail()`). `DefaultEntityRenderer` now consumes `entity_presentation_settings`, resolves sparse `by_type` values from explicit context type hints and namespaced sources, and uses the result for both list and detail rendering at `kernel/EntityContext/DefaultEntityRenderer.php:97-104`, `:308-356`, and `:1123-1213`.

**answer 2: no before this change; yes after it.**
The generated CSS only targeted `.cms-entity-list__grid` and `.cms-entity-card__*`, while emitted cards used `.ikb-entity-list--grid` and `.ikb-entity-card`. The renderer now emits stable `.ikb-entity-card__body`, `__title`, and `__excerpt` hooks at `kernel/EntityContext/DefaultEntityRenderer.php:580-588`. The generated presentation CSS retains its existing CMS selectors and additionally targets those emitted IKB hooks at `modules/cms/helpers/80-customizer.php:2818-2827`.

The renderer also emits the resolved density class, presentation type, and per-render CSS variables. Card excerpt visibility/length and detail max width/profile now consume the canonical settings. Explicit component attributes still take precedence. No public `{ikb_entity_list}` or `{ikb_entity_detail}` signature changed.

## Discriminating repro

Test: `tests/entity_presentation_renderer_bridge_test.php`

The test renders the exact same template,

```text
{ikb_entity_list source="cms.post" view="card_grid" /}
```

twice with contexts differing only in `entity_presentation_settings`.

### Base-tree failure (verbatim)

```text
=== ENTITY PRESENTATION RENDERER BRIDGE ===
  FAIL same list differs when only entity_presentation_settings differs
  FAIL post by_type density is emitted on the list wrapper
  FAIL global density is emitted without a post override
  ok   falsification: removing the override restores byte-identical markup
  FAIL card markup exposes the hooks targeted by presentation CSS
  FAIL generated presentation CSS targets emitted renderer markup

1 passed, 5 failed


Command exited with code 1
```

### Post-fix result

```text
=== ENTITY PRESENTATION RENDERER BRIDGE ===
  ok   same list differs when only entity_presentation_settings differs
  ok   post by_type density is emitted on the list wrapper
  ok   global density is emitted without a post override
  ok   falsification: removing the override restores byte-identical markup
  ok   card markup exposes the hooks targeted by presentation CSS
  ok   generated presentation CSS targets emitted renderer markup

6 passed, 0 failed
```

Falsification is assertion 4: removing the `post` override restores byte-identical compact markup.

## Live tenant proof

Surface: `http://cmsnew.test/cms/blog/`, ARK `public/entity.list.disyl`, which actually emits `{ikb_entity_list source="cms_post.recent" view="card_grid" ...}`.

Before, the emitted wrapper contained:

```html
class="ikb-entity-list ... ikb-entity-list--density-comfortable ..."
data-ikb-presentation-type="post"
style="--theme-entity-list-gap:1.5rem;--theme-entity-list-card-padding:1rem;--theme-entity-list-excerpt-size:0.9rem;..."
```

I temporarily added the sparse native `entity_presentation.by_type.post` values `entity_list_card_density=airy` and `entity_list_excerpt_length=40`, flushing the full cache before the request. The emitted wrapper changed to:

```html
class="ikb-entity-list ... ikb-entity-list--density-airy ..."
data-ikb-presentation-type="post"
style="--theme-entity-list-gap:2rem;--theme-entity-list-card-padding:1.25rem;--theme-entity-list-excerpt-size:0.96rem;..."
```

I restored the original `settings_json` byte-for-byte, flushed again, and observed `ikb-entity-list--density-comfortable` and the original `1.5rem`/`1rem`/`0.9rem` variables again. The active theme symlink is restored to ARK.

## Verification

- `entity_presentation_renderer_bridge` -> **6 passed, 0 failed**
- `ark_declared_control_honour` -> **107 passed, 0 failed**
- `ark_sidebar_targeting` -> **3 passed, 0 failed**
- `footer_bar_contrast` -> **32 passed, 0 failed**
- `cms_customizer_widgets_bridge` -> **21 passed, 0 failed**
- `cms_per_type_presentation` -> **18 passed, 0 failed**
- `theme_studio_preset_capability` -> **7 passed, 0 failed**
- `ark_region_persistence_vocabulary` -> **3 passed, 0 failed**
- `ark_footer_colour_vocabulary` -> **14 passed, 0 failed**
- `guidance_entity_view_test` -> **44 passed, 0 failed**
- `php -l kernel/EntityContext/DefaultEntityRenderer.php` -> no syntax errors
- `php -l modules/cms/helpers/80-customizer.php` -> no syntax errors
- `php -l tests/entity_presentation_renderer_bridge_test.php` -> no syntax errors
- `git diff --check` -> clean

I also attempted the broader `cms_theme_test.php`; it reached the DB-backed section and stopped because this checkout's `applicationostest` database lacks `cms_theme_customizer` (`SQLSTATE[42S02]`). This is an environment prerequisite failure, not an assertion regression. Its temporary active-theme symlink change was restored.

## Logs / blocked

- `storage/logs/app.log`: test capability calls are successful. Existing warnings remain for missing `applicationostest.theme_studio_presets` and the known strict `section_settings.template_rules` warning.
- `storage/logs/error.log`: existing PHP 8.5 `ReflectionMethod::setAccessible()` deprecations remain. It also records one CLI setup mistake from the live probe (`discoverModules()` unavailable before I added `src/helpers/module-manager.php`); subsequent live checks succeeded and restoration completed.
- No implementation blocker. The `colors` / `theme` vocabulary divergence was deliberately not changed, per scope.
