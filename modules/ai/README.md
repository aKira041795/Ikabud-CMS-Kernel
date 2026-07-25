# AI

Kernel-level AI capability providers for automation, suggestions, and text generation. Provides a unified interface for downstream modules to request AI-powered features without coupling to any specific provider.

## Capabilities

| Capability | Purpose |
|-----------|---------|
| `ai.capability.suggest@1` | Suggest relevant capabilities based on context |
| `ai.text.generate@1` | Generate text via configured provider |

## Files

- Manifest: [`module.json`](module.json)
- Providers: [`helpers/`](helpers/)
