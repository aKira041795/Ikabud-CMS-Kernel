#!/usr/bin/env python3
"""
Tests for the Academic Similarity Semantic Service.

Tests the wire protocol compliance, backend comparison accuracy,
error handling, auth validation, and model metadata reporting.
"""

import json
import os
import sys
import unittest
from http.server import HTTPServer
from threading import Thread
from urllib.request import Request, urlopen
from urllib.error import URLError
from unittest.mock import patch

# Add the service directory to path
sys.path.insert(0, os.path.join(os.path.dirname(__file__), "..", "service"))

from app import (
    CAPABILITY_HANDLERS,
    BACKENDS,
    handle_semantic_compare,
    handle_semantic_health,
    compare_token_overlap,
    compare_groq,
    _tokenize,
    _jaccard_similarity,
    SERVICE_VERSION,
    SEMANTIC_MODEL_VERSION,
)


PORT = 19003  # Test port to avoid conflicts


class TestTokenization(unittest.TestCase):
    """Test the word tokenizer."""

    def test_tokenize_simple(self):
        tokens = _tokenize("The quick brown fox")
        self.assertEqual(tokens, {"the", "quick", "brown", "fox"})

    def test_tokenize_punctuation_stripped(self):
        tokens = _tokenize("Hello, world! It's ok.")
        self.assertIn("hello", tokens)
        self.assertIn("world", tokens)
        self.assertNotIn(",", tokens)
        self.assertNotIn("!", tokens)

    def test_tokenize_empty(self):
        tokens = _tokenize("")
        self.assertEqual(tokens, set())

    def test_tokenize_case_insensitive(self):
        tokens_a = _tokenize("Hello World")
        tokens_b = _tokenize("hello world")
        self.assertEqual(tokens_a, tokens_b)


class TestJaccardSimilarity(unittest.TestCase):
    """Test Jaccard similarity computation."""

    def test_identical_sets(self):
        score = _jaccard_similarity({"a", "b", "c"}, {"a", "b", "c"})
        self.assertAlmostEqual(score, 1.0)

    def test_disjoint_sets(self):
        score = _jaccard_similarity({"a", "b"}, {"c", "d"})
        self.assertAlmostEqual(score, 0.0)

    def test_partial_overlap(self):
        score = _jaccard_similarity({"a", "b", "c"}, {"b", "c", "d"})
        self.assertAlmostEqual(score, 0.5)

    def test_empty_sets(self):
        score = _jaccard_similarity(set(), set())
        self.assertAlmostEqual(score, 0.0)

    def test_one_empty(self):
        score = _jaccard_similarity({"a"}, set())
        self.assertAlmostEqual(score, 0.0)


class TestTokenOverlapBackend(unittest.TestCase):
    """Test the default token overlap comparison backend."""

    def test_identical_segments(self):
        segs = ["The quick brown fox jumps"]
        result = compare_token_overlap(segs, segs)
        self.assertEqual(len(result), 1)
        self.assertGreater(result[0]["similarity_score"], 0.9)

    def test_different_segments(self):
        result = compare_token_overlap(
            ["The quick brown fox"], ["Quantum physics theory"]
        )
        self.assertEqual(len(result), 1)
        self.assertLess(result[0]["similarity_score"], 0.3)

    def test_multiple_segments(self):
        result = compare_token_overlap(
            ["Hello world", "Test sentence"],
            ["Hello world", "Another one"],
        )
        self.assertEqual(len(result), 4)  # 2x2 comparisons

    def test_above_threshold_flag(self):
        result = compare_token_overlap(["Hello world"], ["Hello world"])
        self.assertTrue(result[0]["above_threshold"])

    def test_below_threshold_flag(self):
        result = compare_token_overlap(
            ["Hello world"], ["Quantum chromodynamics"]
        )
        self.assertFalse(result[0]["above_threshold"])


class TestSemanticCompareHandler(unittest.TestCase):
    """Test the semantic comparison capability handler."""

    def test_compare_identical(self):
        result = handle_semantic_compare({
            "submission_segments": ["The quick brown fox jumps over the lazy dog"],
            "source_segments": ["The quick brown fox jumps over the lazy dog"],
        })
        self.assertIn("comparisons", result)
        self.assertIn("model", result)
        self.assertIn("summary", result)
        self.assertEqual(len(result["comparisons"]), 1)
        self.assertGreater(result["comparisons"][0]["similarity_score"], 0.9)

    def test_compare_different(self):
        result = handle_semantic_compare({
            "submission_segments": ["Hello world"],
            "source_segments": ["Quantum physics theory and applications"],
        })
        self.assertLess(result["comparisons"][0]["similarity_score"], 0.3)

    def test_multiple_segments_cross_product(self):
        result = handle_semantic_compare({
            "submission_segments": ["A", "B", "C"],
            "source_segments": ["X", "Y"],
        })
        self.assertEqual(len(result["comparisons"]), 6)  # 3x2

    def test_model_metadata(self):
        result = handle_semantic_compare({
            "submission_segments": ["Test"],
            "source_segments": ["Test"],
        })
        self.assertEqual(result["model"]["provider"], "token_overlap")
        self.assertEqual(result["model"]["model_version"], SEMANTIC_MODEL_VERSION)

    def test_summary_identical(self):
        result = handle_semantic_compare({
            "submission_segments": ["Hello world"],
            "source_segments": ["Hello world"],
        })
        self.assertEqual(result["summary"]["total_comparisons"], 1)
        self.assertEqual(result["summary"]["above_threshold_count"], 1)

    def test_summary_different(self):
        result = handle_semantic_compare({
            "submission_segments": ["Hello"],
            "source_segments": ["Goodbye farewell adieu"],
        })
        self.assertEqual(result["summary"]["total_comparisons"], 1)
        self.assertEqual(result["summary"]["above_threshold_count"], 0)

    def test_raises_on_empty_submission(self):
        with self.assertRaises(ValueError):
            handle_semantic_compare({
                "submission_segments": [],
                "source_segments": ["A"],
            })

    def test_raises_on_empty_source(self):
        with self.assertRaises(ValueError):
            handle_semantic_compare({
                "submission_segments": ["A"],
                "source_segments": [],
            })

    def test_raises_on_missing_segments(self):
        with self.assertRaises(ValueError):
            handle_semantic_compare({"model_profile": {}})

    def test_model_profile_override(self):
        result = handle_semantic_compare({
            "submission_segments": ["Test"],
            "source_segments": ["Test"],
            "model_profile": {"provider": "custom", "model_name": "custom-model"},
        })
        self.assertEqual(result["model"]["provider"], "custom")
        self.assertEqual(result["model"]["model_name"], "custom-model")

    def test_provider_profile_selects_backend(self):
        result = handle_semantic_compare({
            "submission_segments": ["The rainfall pattern changed slowly"],
            "source_segments": ["Rainfall patterns shifted over time"],
            "model_profile": {"provider": "tfidf", "model_name": "tfidf"},
        })
        self.assertEqual(result["model"]["provider"], "tfidf")
        self.assertEqual(result["model"]["model_name"], "tfidf")

    def test_threshold_profile_adjusts_match_flags(self):
        result = handle_semantic_compare({
            "submission_segments": ["alpha beta"],
            "source_segments": ["alpha gamma"],
            "model_profile": {"provider": "token_overlap", "threshold": 0.2},
        })
        self.assertEqual(result["model"]["threshold"], 0.2)
        self.assertTrue(result["comparisons"][0]["above_threshold"])
        self.assertEqual(result["summary"]["above_threshold_count"], 1)

    def test_groq_requires_api_key(self):
        with patch.dict(os.environ, {}, clear=True):
            with self.assertRaises(RuntimeError):
                compare_groq(["A"], ["A"], "llama-3.1-8b-instant")

    def test_groq_backend_parses_json_score(self):
        class MockResponse:
            def __enter__(self):
                return self

            def __exit__(self, exc_type, exc, tb):
                return False

            def read(self):
                return json.dumps({
                    "choices": [{"message": {"content": "{\"similarity_score\": 0.82}"}}]
                }).encode("utf-8")

        with patch.dict(os.environ, {"SEMANTIC_API_KEY": "test-key"}, clear=True):
            with patch("app.urllib.request.urlopen", return_value=MockResponse()):
                result = handle_semantic_compare({
                    "submission_segments": ["Academic integrity systems compare documents."],
                    "source_segments": ["Document comparison supports academic integrity."],
                    "model_profile": {
                        "provider": "groq",
                        "model_name": "llama-3.1-8b-instant",
                        "threshold": 0.8,
                    },
                })

        self.assertEqual(result["model"]["provider"], "groq")
        self.assertEqual(result["model"]["model_name"], "llama-3.1-8b-instant")
        self.assertEqual(result["comparisons"][0]["similarity_score"], 0.82)
        self.assertTrue(result["comparisons"][0]["above_threshold"])

    def test_groq_provider_is_registered(self):
        result = handle_semantic_compare({
            "submission_segments": ["Same text"],
            "source_segments": ["Same text"],
            "model_profile": {"provider": "token_overlap"},
        })
        self.assertIn("groq", BACKENDS)
        self.assertEqual(result["model"]["provider"], "token_overlap")

    def test_large_payload_safety(self):
        """Test that the max segment limit is enforced."""
        many_segs = ["Segment " + str(i) for i in range(600)]
        with self.assertRaises(ValueError):
            handle_semantic_compare({
                "submission_segments": ["Test"],
                "source_segments": many_segs,
            })


class TestSemanticHealthHandler(unittest.TestCase):
    """Test the health check capability handler."""

    def test_health_ok(self):
        result = handle_semantic_health({})
        self.assertTrue(result["ok"])
        self.assertEqual(result["service"], "academic-similarity-semantic-service")
        self.assertEqual(result["version"], SERVICE_VERSION)

    def test_health_has_model_info(self):
        result = handle_semantic_health({})
        self.assertIn("model", result)
        self.assertIn("backend", result["model"])

    def test_health_has_uptime(self):
        result = handle_semantic_health({})
        self.assertGreaterEqual(result["uptime_seconds"], 0)


class TestWireProtocolCompliance(unittest.TestCase):
    """Test the capability wire protocol JSON structure."""

    def test_handler_list_completeness(self):
        """All module.json capabilities have a handler."""
        expected = [
            "academic_similarity.semantic.compare@1",
            "academic_similarity.semantic.health@1",
        ]
        for cap_id in expected:
            self.assertIn(cap_id, CAPABILITY_HANDLERS,
                          f"Missing handler for {cap_id}")

    def test_success_response_structure(self):
        result = handle_semantic_compare({
            "submission_segments": ["Test"],
            "source_segments": ["Test"],
        })
        # The wire protocol wraps in {"ok": true, "data": result}
        wire = {"ok": True, "data": result}
        self.assertTrue(wire["ok"])
        self.assertIn("comparisons", wire["data"])
        self.assertIn("model", wire["data"])
        self.assertIn("summary", wire["data"])

    def test_error_response_structure(self):
        try:
            handle_semantic_compare({"submission_segments": []})
            self.fail("Expected ValueError")
        except ValueError as e:
            wire = {"ok": False, "error": str(e)}
            self.assertFalse(wire["ok"])
            self.assertIn("error", wire)

    def test_handler_returns_dict_for_json_serialization(self):
        result = handle_semantic_compare({
            "submission_segments": ["Test"],
            "source_segments": ["Test"],
        })
        # Verify JSON-serializable
        try:
            json.dumps(result)
        except (TypeError, OverflowError) as e:
            self.fail(f"Result is not JSON-serializable: {e}")


class TestEndpointIntegration(unittest.TestCase):
    """Integration test against a live server (optional, requires running server)."""

    @classmethod
    def setUpClass(cls):
        cls.server = None
        cls.server_thread = None
        cls.skip_integration = os.environ.get("SKIP_INTEGRATION", "1") == "1"

    def test_health_endpoint(self):
        if self.skip_integration:
            self.skipTest("Integration tests disabled (SKIP_INTEGRATION=1)")
        # This test is a placeholder — run with SKIP_INTEGRATION=0 to test against
        # a real running service.
        pass

    def test_capability_endpoint(self):
        if self.skip_integration:
            self.skipTest("Integration tests disabled (SKIP_INTEGRATION=1)")
        pass


if __name__ == "__main__":
    unittest.main()
