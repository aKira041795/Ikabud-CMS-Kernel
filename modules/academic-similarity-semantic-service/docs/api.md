# Academic Similarity Semantic Service — API Reference

## Overview

Provider-neutral semantic comparison service for the Academic Integrity & Similarity module. Exposes capabilities through the Kernel OS `ServiceProxy` wire protocol.

**Service**: `academic-similarity-semantic-service`  
**Protocol**: HTTP+JSON on port 9003 (configurable via `SEMANTIC_SERVICE_PORT`)  
**Runtime**: Python 3.9+ (stdlib or FastAPI)  
**Auth**: Bearer token via `SEMANTIC_SERVICE_TOKEN` env var (empty = disabled)

---

## Endpoints

### `GET /health`

Health check. Returns service status, version, loaded model info, and error count.

**Response:**
```json
{
  "ok": true,
  "data": {
    "ok": true,
    "service": "academic-similarity-semantic-service",
    "version": "1.0.0",
    "model": {
      "backend": "token_overlap",
      "model_version": "1.0.0"
    },
    "uptime_seconds": 1234.5,
    "recent_errors": 0
  }
}
```

### `POST /capability/call`

Main capability dispatch endpoint. Accepts capability calls and returns results.

**Request Headers:**
| Header | Required | Description |
|---|---|---|
| `Content-Type` | Yes | `application/json` |
| `Authorization` | When configured | `Bearer <token>` |

**Request Body:**
```json
{
  "capability_id": "academic_similarity.semantic.compare@1",
  "payload": { ... },
  "caller": {
    "module": "academic-similarity",
    "request_id": "req_abc123",
    "tenant_id": "tenant_01",
    "user": { "id": "42", "role": "admin" }
  }
}
```

**Response** (success):
```json
{
  "ok": true,
  "data": { ... }
}
```

**Response** (error):
```json
{
  "ok": false,
  "error": "Descriptive error message"
}
```

---

## Capabilities

### `academic_similarity.semantic.compare@1`

Compare submission text segments against source text segments using semantic (embedding-based) similarity.

**Priority**: 100 (disabled by default in the academic-similarity module)  
**Privacy gate**: Only segment text is sent — full documents are never transmitted  
**Quota gate**: Counts against plan's semantic comparison quota  
**Plan gate**: Requires `semantic_enabled=1` on the subscription plan  
**Setting gate**: Requires `semantic_match_enabled=1` in tenant settings

**Input:**
| Field | Type | Required | Description |
|---|---|---|---|
| `submission_segments` | `array[string]` | Yes | Text segments from the submission (1-500 segments) |
| `source_segments` | `array[string]` | Yes | Text segments from source documents (1-500 segments) |
| `model_profile` | `object` | No | Optional model/backend override |
| `model_profile.provider` | `string` | No | Backend name: `token_overlap`, `tfidf`, or `sentence_transformers` |
| `model_profile.model_name` | `string` | No | Model name (for sentence_transformers backend) |
| `model_profile.threshold` | `number` | No | Similarity threshold override (default 0.70) |

**Output:**
| Field | Type | Description |
|---|---|---|
| `comparisons` | `array` | Per-segment-pair comparison results |
| `comparisons[].submission_segment_index` | `integer` | Index in submission_segments |
| `comparisons[].source_segment_index` | `integer` | Index in source_segments |
| `comparisons[].similarity_score` | `number` | Normalized score [0.0, 1.0] |
| `comparisons[].above_threshold` | `boolean` | Whether score >= 0.70 |
| `model` | `object` | Model/version metadata |
| `model.provider` | `string` | Backend provider name |
| `model.model_name` | `string` | Model name |
| `model.model_version` | `string` | Model version string |
| `summary` | `object` | Aggregate statistics |
| `summary.total_comparisons` | `integer` | Total pairs compared |
| `summary.above_threshold_count` | `integer` | Pairs above threshold |
| `summary.average_similarity` | `number` | Mean similarity across all pairs |

**Example Request:**
```bash
curl -X POST http://127.0.0.1:9003/capability/call \
  -H "Content-Type: application/json" \
  -d '{
    "capability_id": "academic_similarity.semantic.compare@1",
    "payload": {
      "submission_segments": [
        "The quick brown fox jumps over the lazy dog",
        "This is a unique paragraph about biology"
      ],
      "source_segments": [
        "The quick brown fox jumps over the lazy dog",
        "Quantum physics theory and applications"
      ],
      "tenant_id": "tenant_01",
      "institution_id": 42
    },
    "caller": {
      "module": "academic-similarity",
      "request_id": "req_001"
    }
  }'
```

**Example Response:**
```json
{
  "ok": true,
  "data": {
    "comparisons": [
      {
        "submission_segment_index": 0,
        "source_segment_index": 0,
        "similarity_score": 1.0,
        "above_threshold": true
      },
      {
        "submission_segment_index": 0,
        "source_segment_index": 1,
        "similarity_score": 0.0,
        "above_threshold": false
      },
      {
        "submission_segment_index": 1,
        "source_segment_index": 0,
        "similarity_score": 0.0,
        "above_threshold": false
      },
      {
        "submission_segment_index": 1,
        "source_segment_index": 1,
        "similarity_score": 0.0,
        "above_threshold": false
      }
    ],
    "model": {
      "provider": "token_overlap",
      "model_name": "token_overlap",
      "model_version": "1.0.0"
    },
    "summary": {
      "total_comparisons": 4,
      "above_threshold_count": 1,
      "average_similarity": 0.25
    }
  }
}
```

### `academic_similarity.semantic.health@1`

Health check capability. Returns service status, version, and model info.

**Input:** Empty object `{}`

**Output:** Same as `GET /health` response data.

---

## Embedding Backends

| Backend | Dependencies | Speed | Quality | Use Case |
|---|---|---|---|---|
| `token_overlap` (default) | None (stdlib) | Fastest | Basic | MVP, dev, testing |
| `tfidf` | scikit-learn (optional, has fallback) | Fast | Medium | Production baseline |
| `sentence_transformers` | sentence-transformers, torch | Slow | Best | Production semantic |

Set backend via `SEMANTIC_EMBEDDING_BACKEND` env var.

---

## Error Codes

| HTTP Status | `ok` | Meaning |
|---|---|---|
| 200 | `true` | Success |
| 400 | `false` | Invalid request (empty body, malformed JSON) |
| 401 | `false` | Unauthorized (missing/invalid auth token) |
| 404 | `false` | Unknown capability ID |
| 422 | `false` | Validation failed (missing required fields, limit exceeded) |
| 500 | `false` | Service error (comparison failure, backend crash) |
