# AI Orchestrator

External AI orchestration service connector. Provides summarization, drafting, content generation, and analysis via OpenAI and Claude backends. Designed to run as a separate worker process.

## Capabilities

| Capability | Purpose |
|-----------|---------|
| `ai.summarize@1` | Summarize content via external AI |
| `ai.draft@1` | Draft content from prompt |
| `ai.complete@1` | Complete partial content |
| `ai.analyze@1` | Analyze content for insights |

## Files

- Manifest: [`module.json`](module.json)
