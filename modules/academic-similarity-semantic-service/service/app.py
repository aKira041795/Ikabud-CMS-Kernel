#!/usr/bin/env python3
"""
academic-similarity-semantic-service — Provider-neutral semantic comparison service.

Implements the Kernel OS capability wire protocol:
  POST /capability/call  →  {"capability_id":"...", "payload":{...}, "caller":{...}}
  Response: {"ok": true, "data": {...}} or {"ok": false, "error": "..."}

Capabilities:
  academic_similarity.semantic.compare@1 — Compare text segments semantically
  academic_similarity.semantic.health@1  — Health check with model info

Architecture:
  - Provider-neutral design: embedding backend is pluggable via EMBEDDING_BACKEND env var
  - Built-in backends: "token_overlap" (default, zero-dependency), "tfidf" (scikit-learn)
  - External backends: "sentence_transformers" (requires sentence-transformers package)
  - All backends return normalized similarity scores in [0.0, 1.0]
  - No data is persisted — pure computation service
  - Segment-only payloads: full documents are never sent (privacy gate)

Start: python3 -m uvicorn app:app --host 127.0.0.1 --port 9003
       or: python3 app.py  (uses uvicorn programmatically)
"""

import json
import os
import sys
import time
import signal
import hashlib
import math
import urllib.error
import urllib.request
from http.server import HTTPServer, BaseHTTPRequestHandler
from typing import Any

PORT = int(os.environ.get("SEMANTIC_SERVICE_PORT", 9003))
HOST = os.environ.get("SEMANTIC_SERVICE_HOST", "127.0.0.1")
AUTH_TOKEN = os.environ.get("SEMANTIC_SERVICE_TOKEN", "")
EMBEDDING_BACKEND = os.environ.get("SEMANTIC_EMBEDDING_BACKEND", "token_overlap")

# Version
SERVICE_VERSION = "1.0.0"
SEMANTIC_MODEL_VERSION = "1.0.0"

# Limits
MAX_COMPARISONS = int(os.environ.get("SEMANTIC_MAX_COMPARISONS", 10000))
BACKEND_TIMEOUT = int(os.environ.get("SEMANTIC_BACKEND_TIMEOUT", 30))
BACKEND_TIMEOUT_ST = int(os.environ.get("SEMANTIC_BACKEND_TIMEOUT_ST", 120))

# ── Embedding Backends ───────────────────────────────────────────


def _tokenize(text: str) -> set:
    """Simple word tokenizer — lowercased, punctuation-stripped."""
    normalized = "".join(c.lower() for c in text if c.isalnum() or c.isspace())
    return set(normalized.split())


def _jaccard_similarity(tokens_a: set, tokens_b: set) -> float:
    """Jaccard similarity between two token sets."""
    intersection = tokens_a & tokens_b
    union = tokens_a | tokens_b
    if not union:
        return 0.0
    return len(intersection) / len(union)


def _cosine_similarity(vec_a: dict, vec_b: dict) -> float:
    """Cosine similarity between two sparse vectors (dicts of token->weight)."""
    dot_product = 0.0
    norm_a = 0.0
    norm_b = 0.0

    for token, weight in vec_a.items():
        norm_a += weight * weight
        if token in vec_b:
            dot_product += weight * vec_b[token]

    for weight in vec_b.values():
        norm_b += weight * weight

    if norm_a == 0.0 or norm_b == 0.0:
        return 0.0

    return dot_product / (math.sqrt(norm_a) * math.sqrt(norm_b))


def _tfidf_vectorize(text: str, idf: dict | None = None) -> dict:
    """Simple TF-IDF vectorization without external deps."""
    normalized = "".join(c.lower() for c in text if c.isalnum() or c.isspace())
    tokens = normalized.split()
    total = len(tokens) if tokens else 1
    tf = {t: tokens.count(t) / total for t in set(tokens)}
    if idf:
        return {t: tf[t] * idf.get(t, 1.0) for t in tf}
    return tf


# ── Backend: Token Overlap (default, zero-dependency) ─────────────


def compare_token_overlap(segments_a: list[str], segments_b: list[str], threshold: float = 0.70) -> list[dict]:
    """
    Compare segments using Jaccard similarity on word tokens.
    Deterministic, fast, zero dependencies. Good baseline for MVP.
    """
    comparisons = []

    for i, seg_a in enumerate(segments_a):
        tokens_a = _tokenize(seg_a)
        for j, seg_b in enumerate(segments_b):
            tokens_b = _tokenize(seg_b)
            score = _jaccard_similarity(tokens_a, tokens_b)

            comparisons.append({
                "submission_segment_index": i,
                "source_segment_index": j,
                "similarity_score": round(score, 4),
                "above_threshold": score >= threshold,
            })

    return comparisons


# ── Backend: TF-IDF Cosine (requires scikit-learn or built-in fallback) ──


def compare_tfidf(segments_a: list[str], segments_b: list[str], threshold: float = 0.70) -> list[dict]:
    """
    Compare segments using TF-IDF cosine similarity.
    Uses built-in TF-IDF if scikit-learn is not available.
    """
    try:
        from sklearn.feature_extraction.text import TfidfVectorizer

        all_texts = segments_a + segments_b
        vectorizer = TfidfVectorizer(
            analyzer="word",
            token_pattern=r"(?u)\b\w+\b",
            max_features=5000,
        )
        tfidf_matrix = vectorizer.fit_transform(all_texts)
        n_a = len(segments_a)

        comparisons = []
        for i in range(n_a):
            for j in range(n_b := len(segments_b)):
                vec_a = tfidf_matrix[i].toarray().flatten()
                vec_b = tfidf_matrix[n_a + j].toarray().flatten()

                dot = sum(a * b for a, b in zip(vec_a, vec_b))
                norm_a = math.sqrt(sum(a * a for a in vec_a))
                norm_b = math.sqrt(sum(b * b for b in vec_b))

                score = dot / (norm_a * norm_b) if norm_a > 0 and norm_b > 0 else 0.0

                comparisons.append({
                    "submission_segment_index": i,
                    "source_segment_index": j,
                    "similarity_score": round(float(score), 4),
                    "above_threshold": score >= threshold,
                })

        return comparisons

    except ImportError:
        # Fallback to built-in TF-IDF
        return _compare_tfidf_builtin(segments_a, segments_b, threshold)


def _compare_tfidf_builtin(segments_a: list[str], segments_b: list[str], threshold: float = 0.70) -> list[dict]:
    """Built-in TF-IDF fallback (no scikit-learn dependency)."""
    comparisons = []

    for i, seg_a in enumerate(segments_a):
        vec_a = _tfidf_vectorize(seg_a)
        for j, seg_b in enumerate(segments_b):
            vec_b = _tfidf_vectorize(seg_b)
            score = _cosine_similarity(vec_a, vec_b)

            comparisons.append({
                "submission_segment_index": i,
                "source_segment_index": j,
                "similarity_score": round(score, 4),
                "above_threshold": score >= threshold,
            })

    return comparisons


# ── Backend: Sentence Transformers (requires sentence-transformers) ──


def compare_sentence_transformers(
    segments_a: list[str], segments_b: list[str], model_name: str | None = None,
    threshold: float = 0.70
) -> list[dict]:
    """
    Compare segments using sentence-transformers embeddings.
    Falls back to token_overlap if the package is not installed.
    """
    try:
        from sentence_transformers import SentenceTransformer  # type: ignore
        from numpy import dot
        from numpy.linalg import norm

        model_name = model_name or os.environ.get("SEMANTIC_MODEL_NAME", "all-MiniLM-L6-v2")
        model = SentenceTransformer(model_name)

        all_texts = segments_a + segments_b
        embeddings = model.encode(all_texts)
        n_a = len(segments_a)

        comparisons = []
        for i in range(n_a):
            emb_a = embeddings[i]
            for j in range(n_b := len(segments_b)):
                emb_b = embeddings[n_a + j]
                score = dot(emb_a, emb_b) / (norm(emb_a) * norm(emb_b) + 1e-10)
                score = max(0.0, min(1.0, float(score)))

                comparisons.append({
                    "submission_segment_index": i,
                    "source_segment_index": j,
                    "similarity_score": round(score, 4),
                    "above_threshold": score >= threshold,
                })

        return comparisons

    except ImportError:
        print(
            f"[semantic-service] sentence-transformers not available, "
            f"falling back to token_overlap",
            file=sys.stderr,
        )
        return compare_token_overlap(segments_a, segments_b, threshold)


# ── Backend: Groq LLM Judge (paid provider via OpenAI-compatible API) ──


def _clamp_score(value: Any) -> float:
    try:
        score = float(value)
    except (TypeError, ValueError):
        return 0.0
    return max(0.0, min(1.0, score))


def _extract_json_object(text: str) -> dict:
    try:
        parsed = json.loads(text)
    except json.JSONDecodeError:
        start = text.find("{")
        end = text.rfind("}")
        if start < 0 or end < start:
            raise ValueError("Groq response did not contain JSON")
        parsed = json.loads(text[start:end + 1])
    if not isinstance(parsed, dict):
        raise ValueError("Groq response JSON must be an object")
    return parsed


def compare_groq(
    segments_a: list[str],
    segments_b: list[str],
    model_name: str | None = None,
    api_key: str | None = None,
    threshold: float = 0.70,
    max_pairs: int = 20,
) -> list[dict]:
    """Compare segment pairs with Groq chat completions returning strict JSON scores.

    Pre-filters pairs using fast Jaccard token overlap — only the top
    ``max_pairs`` candidates by token similarity are sent to the LLM.
    This avoids N×M API calls (thousands) for typical academic submissions.
    """
    api_key = api_key or os.environ.get("SEMANTIC_API_KEY") or os.environ.get("GROQ_API_KEY")
    if not api_key:
        raise RuntimeError("Groq backend requires SEMANTIC_API_KEY or GROQ_API_KEY")

    model_name = model_name or os.environ.get("SEMANTIC_MODEL_NAME", "llama-3.1-8b-instant")
    endpoint = os.environ.get("GROQ_API_BASE", "https://api.groq.com/openai/v1").rstrip("/")
    timeout = float(os.environ.get("GROQ_TIMEOUT_SECONDS", "30"))

    # ── Step 1: pre-filter with fast token overlap ──────────────
    scored: list[tuple[int, int, float]] = []
    for i, seg_a in enumerate(segments_a):
        tokens_a = _tokenize(seg_a)
        for j, seg_b in enumerate(segments_b):
            tokens_b = _tokenize(seg_b)
            score = _jaccard_similarity(tokens_a, tokens_b)
            scored.append((i, j, score))

    # Sort by token similarity descending, take top max_pairs
    scored.sort(key=lambda t: t[2], reverse=True)
    candidates = scored[:max_pairs]

    # ── Step 2: send only top candidates to Groq ────────────────
    comparisons: list[dict] = []
    for i, j, token_score in candidates:
        seg_a = segments_a[i]
        seg_b = segments_b[j]
        prompt = (
            "Return only JSON with a numeric similarity_score between 0 and 1. "
            "Score semantic academic similarity, not writing quality or AI authorship.\n\n"
            f"Submission segment:\n{seg_a}\n\nSource segment:\n{seg_b}"
        )
        request_body = json.dumps({
            "model": model_name,
            "temperature": 0,
            "max_tokens": 64,
            "response_format": {"type": "json_object"},
            "messages": [
                {
                    "role": "system",
                    "content": "You are a strict academic similarity scoring service. Return only JSON.",
                },
                {"role": "user", "content": prompt},
            ],
        }).encode("utf-8")
        request = urllib.request.Request(
            f"{endpoint}/chat/completions",
            data=request_body,
            headers={
                "Authorization": f"Bearer {api_key}",
                "Content-Type": "application/json",
                "User-Agent": "Ikabud/1.0 (Academic Similarity Service)",
            },
            method="POST",
        )
        try:
            with urllib.request.urlopen(request, timeout=timeout) as response:
                raw = response.read().decode("utf-8")
        except urllib.error.HTTPError as e:
            body = e.read().decode("utf-8", errors="replace")
            raise RuntimeError(f"Groq API HTTP {e.code}: {body[:240]}") from e
        except urllib.error.URLError as e:
            raise RuntimeError(f"Groq API request failed: {e.reason}") from e

        data = json.loads(raw)
        content = (((data.get("choices") or [{}])[0].get("message") or {}).get("content") or "")
        parsed = _extract_json_object(content)
        score = _clamp_score(parsed.get("similarity_score"))
        comparisons.append({
            "submission_segment_index": i,
            "source_segment_index": j,
            "similarity_score": round(score, 4),
            "above_threshold": score >= threshold,
        })

    return comparisons


# ── Backend Router ────────────────────────────────────────────────

BACKENDS = {
    "token_overlap": compare_token_overlap,
    "tfidf": compare_tfidf,
    "sentence_transformers": compare_sentence_transformers,
    "groq": compare_groq,
}


def get_backend(name: str):
    """Get the comparison backend by name, defaulting to token_overlap."""
    backend = BACKENDS.get(name)
    if backend is None:
        print(
            f"[semantic-service] Unknown backend '{name}', using token_overlap",
            file=sys.stderr,
        )
        return BACKENDS["token_overlap"]
    return backend


# ── Capability Handlers ──────────────────────────────────────────

_start_time = time.time()
_error_count = 0
_error_timestamps: list[float] = []
_ERROR_WINDOW_HOURS = 1


class _TimeoutError(Exception):
    """Raised when a backend execution times out."""
    pass


def _run_with_timeout(func, args, timeout_seconds: int = 30):
    """Run a function with a timeout using SIGALRM."""
    def _handler(signum, frame):
        raise _TimeoutError(f"Backend execution timed out after {timeout_seconds}s")
    old_handler = signal.signal(signal.SIGALRM, _handler)
    signal.alarm(timeout_seconds)
    try:
        return func(*args)
    finally:
        signal.alarm(0)
        signal.signal(signal.SIGALRM, old_handler)


def _prune_old_errors():
    """Remove error timestamps older than _ERROR_WINDOW_HOURS."""
    global _error_timestamps
    cutoff = time.time() - _ERROR_WINDOW_HOURS * 3600
    _error_timestamps = [ts for ts in _error_timestamps if ts >= cutoff]
    return len(_error_timestamps)


def _record_error():
    """Record an error timestamp."""
    global _error_timestamps, _error_count
    _error_timestamps.append(time.time())
    _error_count = _prune_old_errors()


def handle_semantic_compare(payload: dict) -> dict:
    """Handle academic_similarity.semantic.compare@1."""
    global _error_count

    submission_segments: list[str] = payload.get("submission_segments", [])
    source_segments: list[str] = payload.get("source_segments", [])
    model_profile: dict | None = payload.get("model_profile")

    if not submission_segments:
        raise ValueError("submission_segments is required and must be non-empty")
    if not source_segments:
        raise ValueError("source_segments is required and must be non-empty")

    # Max segment count safety limit
    max_segments = int(os.environ.get("SEMANTIC_MAX_SEGMENTS", 500))
    if len(submission_segments) > max_segments:
        raise ValueError(
            f"submission_segments exceeds limit of {max_segments} "
            f"(got {len(submission_segments)})"
        )
    if len(source_segments) > max_segments:
        raise ValueError(
            f"source_segments exceeds limit of {max_segments} "
            f"(got {len(source_segments)})"
        )

    # Max comparisons cap
    pair_count = len(submission_segments) * len(source_segments)
    if pair_count > MAX_COMPARISONS:
        raise ValueError(
            f"Segment pair count {pair_count} exceeds limit of {MAX_COMPARISONS}. "
            f"Reduce the number of segments or increase SEMANTIC_MAX_COMPARISONS."
        )

    # Determine backend and threshold from the requested model profile.
    backend_name = EMBEDDING_BACKEND
    provider = backend_name
    model_name = os.environ.get("SEMANTIC_MODEL_NAME", backend_name)
    api_key = None
    threshold = 0.70

    if model_profile:
        requested_provider = str(model_profile.get("provider", "")).strip()
        requested_model = str(model_profile.get("model_name", "")).strip()
        if requested_provider:
            provider = requested_provider
            backend_name = requested_provider
        if requested_model:
            model_name = requested_model
            if not requested_provider and requested_model not in BACKENDS:
                backend_name = "sentence_transformers"
                provider = "sentence_transformers"
        if "threshold" in model_profile:
            try:
                threshold = float(model_profile["threshold"])
            except (TypeError, ValueError):
                threshold = 0.70
        requested_api_key = str(model_profile.get("api_key", "")).strip()
        if requested_api_key:
            api_key = requested_api_key
    threshold = max(0.0, min(1.0, threshold))

    backend = get_backend(backend_name)

    # Determine timeout based on backend
    if backend_name == "sentence_transformers":
        timeout_seconds = BACKEND_TIMEOUT_ST
    else:
        timeout_seconds = BACKEND_TIMEOUT

    try:
        if backend_name in {"sentence_transformers", "groq"}:
            if backend_name == "groq":
                comparisons = backend(submission_segments, source_segments, model_name, api_key, threshold)
            else:
                comparisons = _run_with_timeout(
                    backend, (submission_segments, source_segments, model_name, threshold),
                    timeout_seconds,
                )
        else:
            comparisons = _run_with_timeout(
                backend, (submission_segments, source_segments, threshold),
                timeout_seconds,
            )
    except Exception as e:
        _record_error()
        raise RuntimeError(f"Comparison failed: {e}") from e

    for comparison in comparisons:
        score = float(comparison.get("similarity_score", 0.0))
        comparison["above_threshold"] = score >= threshold

    # Compute summary
    total = len(comparisons)
    above = sum(1 for c in comparisons if c["above_threshold"])
    avg = (
        sum(c["similarity_score"] for c in comparisons) / total
        if total > 0
        else 0.0
    )

    return {
        "comparisons": comparisons,
        "model": {
            "provider": provider,
            "model_name": model_name,
            "model_version": SEMANTIC_MODEL_VERSION,
            "threshold": threshold,
        },
        "summary": {
            "total_comparisons": total,
            "above_threshold_count": above,
            "average_similarity": round(avg, 4),
        },
    }


def handle_semantic_health(payload: dict) -> dict:
    """Handle academic_similarity.semantic.health@1."""
    uptime = time.time() - _start_time

    return {
        "ok": True,
        "service": "academic-similarity-semantic-service",
        "version": SERVICE_VERSION,
        "model": {
            "backend": EMBEDDING_BACKEND,
            "model_version": SEMANTIC_MODEL_VERSION,
        },
        "uptime_seconds": round(uptime, 1),
        "recent_errors": _error_count,
    }


CAPABILITY_HANDLERS: dict[str, callable] = {
    "academic_similarity.semantic.compare@1": handle_semantic_compare,
    "academic_similarity.semantic.health@1": handle_semantic_health,
}


# ── HTTP Server ───────────────────────────────────────────────────


class CapabilityHandler(BaseHTTPRequestHandler):
    def log_message(self, format: str, *args: Any):
        print(f"[semantic-service] {args[0]}", file=sys.stderr)

    def _json_response(self, status: int, body: dict):
        data = json.dumps(body).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(data)))
        self.end_headers()
        self.wfile.write(data)

    def do_GET(self):
        if self.path == "/health":
            try:
                data = handle_semantic_health({})
                self._json_response(200, {"ok": True, "data": data})
            except Exception as e:
                self._json_response(500, {"ok": False, "error": str(e)})
        else:
            self._json_response(404, {"ok": False, "error": "not found"})

    def do_POST(self):
        if self.path != "/capability/call":
            self._json_response(404, {"ok": False, "error": "only /capability/call is supported"})
            return

        # Auth validation
        if AUTH_TOKEN:
            auth_header = self.headers.get("Authorization", "")
            if auth_header != f"Bearer {AUTH_TOKEN}":
                self._json_response(401, {"ok": False, "error": "unauthorized"})
                return

        content_length = int(self.headers.get("Content-Length", 0))
        if content_length == 0:
            self._json_response(400, {"ok": False, "error": "empty body"})
            return

        try:
            body = json.loads(self.rfile.read(content_length))
        except json.JSONDecodeError as e:
            self._json_response(400, {"ok": False, "error": f"invalid JSON: {e}"})
            return

        capability_id = body.get("capability_id", "")
        payload = body.get("payload", {})
        caller = body.get("caller", {})

        print(
            f"[semantic-service] capability={capability_id} "
            f"caller_module={caller.get('module', '?')}",
            file=sys.stderr,
        )

        handler = CAPABILITY_HANDLERS.get(capability_id)
        if handler is None:
            self._json_response(404, {
                "ok": False,
                "error": f"unknown capability: {capability_id}",
                "available": list(CAPABILITY_HANDLERS.keys()),
            })
            return

        try:
            result = handler(payload)
            self._json_response(200, {"ok": True, "data": result})
        except ValueError as e:
            self._json_response(422, {"ok": False, "error": str(e)})
        except Exception as e:
            print(f"[semantic-service] handler error: {e}", file=sys.stderr)
            self._json_response(500, {"ok": False, "error": str(e)})


if __name__ == "__main__":
    server = HTTPServer((HOST, PORT), CapabilityHandler)
    print(f"[semantic-service] Listening on http://{HOST}:{PORT}")
    print(f"[semantic-service] Backend: {EMBEDDING_BACKEND}")
    print(f"[semantic-service] Capabilities: {list(CAPABILITY_HANDLERS.keys())}")
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        print("\n[semantic-service] Shutting down")
        server.shutdown()
