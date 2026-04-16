# CMS AI Content Automation

## What This Implements

This feature adds the backend foundation for recurring AI-generated CMS content:

- AI content plans stored in dedicated CMS tables
- execution run history with prompt/response snapshots
- scheduled generation via CLI cron runner
- full-content generation using the existing `ai.text.generate@1` capability
- summary and SEO generation in the same structured response
- basic uniqueness guardrails through title/body duplicate checks and recent-title avoidance
- suggested visuals from the existing CMS media library
- workflow submission into `review`
- approval emails sent to active CMS editors and administrators

## Tables

- `cms_ai_content_plans`
- `cms_ai_content_runs`

## Current API Surface

### GET

- `/api/v1/cms/ai/plans`
- `/api/v1/cms/ai/plans/{id}`
- `/api/v1/cms/ai/runs`

### POST

- `/api/v1/cms/ai/plans`
- `/api/v1/cms/ai/plans/{id}`
- `/api/v1/cms/ai/plans/{id}/toggle`
- `/api/v1/cms/ai/plans/{id}/run`

All plan endpoints currently require the CMS capability `ai.automation.manage`.

## Plan Model

Each plan stores:

- topic
- content type
- prompt template
- writing style
- target audience
- keywords
- summary enabled flag
- SEO enabled flag
- visual mode
- cadence and cadence interval
- publish offset in minutes
- next run timestamp
- active flag

## Generation Flow

1. Cron or `run now` loads a due plan.
2. The system builds a structured JSON prompt for `ai.text.generate@1`.
3. AI returns JSON with:
   - title
   - body HTML
   - summary
   - SEO title
   - SEO description
   - tags
   - visual queries
4. The response is validated and sanitized.
5. Existing media suggestions are matched from `cms_media` using the topic, keywords, and visual queries.
6. CMS content is created as `draft` with AI provenance meta.
7. Workflow transitions the content from `draft` to `review`.
8. A review email is sent to active editors and administrators.

## Publish Timing Model

Generated content is intentionally created as `draft` first, even when a future publish time is desired.

Reason:

- CMS `scheduled` status can publish automatically once `published_at` is due.
- Workflow approval must happen before that.

To avoid bypassing approval:

- the desired future publish time is stored in `_ai_desired_publish_at`
- when the approver performs the workflow `publish` action, CMS checks that AI meta
- if the desired publish time is still in the future, the content is set to `scheduled`
- the existing scheduled publishing flow then handles release at the correct time

## Cron

Run the generator with:

```bash
php modules/cms/cli/generate-ai-content.php
```

Example crontab:

```bash
*/15 * * * * cd /var/www/html/applicationkernel && php modules/cms/cli/generate-ai-content.php >> storage/logs/cron.log 2>&1
```

The existing scheduled publishing cron remains separate:

```bash
php modules/cms/cli/publish-scheduled.php
```

## AI Provenance Meta

Generated items store metadata such as:

- `_ai_generated`
- `_ai_plan_id`
- `_ai_run_id`
- `_ai_topic`
- `_ai_body_hash`
- `_ai_visual_suggestions`
- `_ai_desired_publish_at`
- `_ai_review_email_sent_at`

## Content Modes

Each plan's `content_mode` shapes the AI prompt style and preferred provider tier:

| Mode | Description | Provider Tier |
|------|-------------|---------------|
| `standard` | Default general-purpose article | free |
| `tutorial` | Step-by-step instructional content | free |
| `opinion` | Editorial / thought-leadership | paid |
| `comparison` | Structured side-by-side analysis | paid |
| `checklist` | Actionable list-based content | free |
| `expert` | Deep-expertise domain content | paid |

## Auto-Refine Policy

`auto_refine_policy` controls automatic quality improvement passes after the initial draft:

| Value | Behaviour |
|-------|-----------|
| `off` | No automatic refinement |
| `high_severity_once` | Run one refinement pass if confidence < threshold (default) |
| `always_once` | Always run one refinement pass |

## Auto-Publish Policy

`auto_publish_policy` controls whether approved AI content is published without manual intervention:

| Value | Behaviour |
|-------|-----------|
| `off` | No automatic publishing (default, recommended) |
| `high_confidence_low_sensitivity` | Publish automatically when confidence ≥ threshold and content has no sensitive flags |

## Search Grounding

When a search provider is configured, the system fetches live web results before
generation so the AI can cite real, verifiable sources.

**Settings** (stored in the AI module settings):

| Key | Description |
|-----|-------------|
| `search_grounding_provider` | `brave` / `tavily` / `serper` (empty = disabled) |
| `search_grounding_api_key` | API key for the chosen provider |
| `search_grounding_max_results` | 1–10, default 5 |

**Per-plan override** (`search_grounding_enabled`):
- `null` — defer to global (enabled when provider + key are set)
- `true` — force on for this plan (still requires global key/provider)
- `false` — force off for this plan

## Content Refine API

### POST /api/v1/cms/content/{id}/ai/refine

Runs an on-demand AI refinement pass on existing CMS content.
Requires the CMS capability `ai.automation.manage`.

Returns updated content fields (title, body, summary, seo_title, seo_description).

## Admin UI

The AI Automation admin page is available at `/cms/admin/ai-automation`.
It requires the `ai.automation.manage` CMS capability.

The page provides:
- Create / edit / delete plans
- Enable / disable individual plans
- Trigger a manual run
- View run history
- Search grounding configuration status

## Featured Image Media Browser

The CMS content editor includes a media browser modal for selecting and managing featured images with integrated free web image search.

### Modes

- **Uploaded**: Browse and search local media library
- **Free Web**: Search and import free images from multiple sources

### Free Image Sources

The system aggregates free images from:

| Source | Provider | Coverage | Availability |
|--------|----------|----------|---------------|
| **Openverse** | Creative Commons licensed | 690M+ images | Always available |
| **Wikimedia Commons** | Wikimedia Foundation | 90M+ images | Always available |
| **Pexels** | Pexels Inc. | 500K+ high-quality photos | Requires `PEXELS_API_KEY` env var |

### Search APIs

#### GET /api/v1/cms/media/free-search

Free web image search across Openverse and Wikimedia Commons.

**Query Parameters:**
- `q` (required): Search query string
- `limit` (optional, default 30): Maximum results per source

**Response:**
```json
{
  "ok": true,
  "data": [
    {
      "url": "https://...",
      "thumbnail_url": "https://...",
      "original_name": "filename",
      "source": "openverse",
      "external": true,
      "attribution": "Author name",
      "license": "CC BY-SA 4.0"
    }
  ]
}
```

#### POST /api/v1/cms/ai/featured-image-suggest

AI-powered featured image suggestion using content metadata.

**Request Body:**
```json
{
  "title": "Content title",
  "excerpt": "Short description",
  "tags": "tag1, tag2",
  "type": "article|product|...",
  "search_keywords": "optional override keywords"
}
```

**Response:**
```json
{
  "ok": true,
  "suggestions": [
    {
      "url": "https://...",
      "source": "openverse",
      "external": true,
      ...additional fields
    }
  ],
  "openverse_count": 12,
  "wikimedia_count": 8,
  "pexels_count": 0
}
```

### Source Filtering

The media browser includes a dropdown filter in web mode (All / Openverse / Wikimedia) to bias results by source library. This allows users to control visual style consistency—for example, selecting Wikimedia for more encyclopedic imagery or Openverse for diverse Creative Commons content.

The filter is applied client-side and resets to "All" when opening the modal or starting a new search.

### Import Workflow

When a free web image is selected, the system:
1. Downloads the image to local storage via the import handler
2. Creates a `cms_media` entry with source attribution and license metadata
3. Links the featured image to the content's `featured_image_id`
4. Stores the original external URL in media metadata for reference

This ensures featured images remain available even if the external source changes.

### Keyword Override

Users can provide custom keywords via the `featured_image_keywords` field in the content editor, which overrides AI-generated suggestions when using the web search or AI suggest buttons. This is useful when the content title alone doesn't capture the desired visual style.

## Current Limitations

- No per-plan recipient override yet; approval goes to active editors/administrators
- No AI image generation yet; visuals are suggested from existing media only
- Duplicate detection is intentionally basic in v1s
- Free image sources are limited to Openverse, Wikimedia, and Pexels; additional providers would require API integration and key management