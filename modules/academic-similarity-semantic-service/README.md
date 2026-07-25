# Academic Similarity Semantic Service (Python)

Provider-neutral semantic comparison service for the AISS module. Runs as an independent Python HTTP process on port 9003, communicating with the PHP runtime via Kernel OS `ServiceProxy` over the capability wire protocol.

## Backends

| Backend | Dependency | Timeout |
|---------|-----------|---------|
| `token_overlap` (default) | None (stdlib) | 30s |
| `tfidf` | scikit-learn | 30s |
| `sentence_transformers` | sentence-transformers + torch | 120s |
| `groq` | `SEMANTIC_API_KEY` env var | API-driven |

## Capabilities

- `academic_similarity.semantic.compare@1` — Compare text segments semantically
- `academic_similarity.semantic.health@1` — Health check with model info

## Limits

- Max segments per side: 500 (`SEMANTIC_MAX_SEGMENTS`)
- Max comparison pairs: 10,000 (`SEMANTIC_MAX_COMPARISONS`)
- Bearer token auth via `SEMANTIC_SERVICE_TOKEN`

## Files

- Service: [`service/app.py`](service/app.py)
- API docs: [`docs/api.md`](docs/api.md)
- Tests: [`tests/test_semantic_service.py`](tests/test_semantic_service.py)
