<?php
declare(strict_types=1);

/**
 * AISS AI Integration Gaps — Acceptance Tests
 *
 * Verifies:
 * 1. semantic_match_enabled defaults to '1'
 * 2. Internet discovery supports provider = 'ai'
 * 3. AI report narrative setting exists with correct default
 * 4. Graceful degradation when AI is unavailable
 */

use Ikabud\Kernel\Testing\TestHarness;

require_once __DIR__ . '/../bootstrap.php';

$harness = new TestHarness('AISS AI Integration');
$harness->clearLogs();

// ── Test 1: semantic_match_enabled defaults to '1' ──────────────
$harness->test('semantic_match_enabled defaults to enabled', function () {
    $_SERVER['HTTP_HOST'] = 'aiss.test';

    // Load the module helpers to get the defaults function
    // The defaults are in academic_similarity_get_settings()
    $defaults = academic_similarity_get_settings('__test__');
    $val = $defaults['semantic_match_enabled'] ?? 'NOT_FOUND';

    assert($val === '1', "Expected '1', got '{$val}'");
    return true;
});

// ── Test 2: report_ai_narrative_enabled defaults to '1' ─────────
$harness->test('report_ai_narrative_enabled defaults to enabled', function () {
    $defaults = academic_similarity_get_settings('__test__');
    $val = $defaults['report_ai_narrative_enabled'] ?? 'NOT_FOUND';

    assert($val === '1', "Expected '1', got '{$val}'");
    return true;
});

// ── Test 3: internet_check_provider defaults to seed_urls ───────
$harness->test('internet_check_provider defaults to seed_urls', function () {
    $defaults = academic_similarity_get_settings('__test__');
    $val = $defaults['internet_check_provider'] ?? 'NOT_FOUND';

    assert($val === 'seed_urls', "Expected 'seed_urls', got '{$val}'");
    return true;
});

// ── Test 4: internet_check_seed_urls has default URLs ───────────
$harness->test('internet_check_seed_urls has default seed URLs', function () {
    $defaults = academic_similarity_get_settings('__test__');
    $val = $defaults['internet_check_seed_urls'] ?? '';

    assert($val !== '', 'Seed URLs should not be empty');
    assert(str_contains($val, 'wikipedia.org'), 'Should contain Wikipedia URLs');
    return true;
});

// ── Test 5: InternetDiscoveryService supports provider = 'ai' ───
$harness->test('InternetDiscoveryService accepts provider=ai without error', function () {
    $_SERVER['HTTP_HOST'] = 'aiss.test';

    $service = new AcademicSimilarityInternetDiscoveryService('__test__');
    $result = $service->discover(['test query'], [
        'internet_check_provider' => 'ai',
        'internet_check_max_sources' => '5',
        'internet_check_payload_policy' => 'snippets_only',
    ]);

    // Provider 'ai' should not crash — it calls the capability bus
    // which may return empty if no handler is registered at higher priority.
    // The key assertion: no exception thrown, ok response returned.
    assert(isset($result['ok']), 'Result should have ok key');
    assert(isset($result['candidates']), 'Result should have candidates key');
    return true;
});

// ── Test 6: provider = 'seed_urls' still works ──────────────────
$harness->test('InternetDiscoveryService with seed_urls works', function () {
    $service = new AcademicSimilarityInternetDiscoveryService('__test__');
    $result = $service->discover(['test'], [
        'internet_check_provider' => 'seed_urls',
        'internet_check_max_sources' => '5',
        'internet_check_seed_urls' => 'https://example.com',
        'internet_check_payload_policy' => 'snippets_only',
    ]);

    assert($result['ok'] === true, 'Seed URL discovery should succeed');
    assert(count($result['candidates']) > 0, 'Should have at least 1 candidate from seed URL');
    assert($result['candidates'][0]['url'] === 'https://example.com', 'Candidate URL should match seed URL');
    return true;
});

// ── Test 7: AI module exposes ai.search.discover@1 ──────────────
$harness->test('AI module exposes ai.search.discover@1', function () {
    $modules = discoverModules();
    $aiModule = $modules['ai'] ?? null;
    assert($aiModule !== null, 'AI module should be discoverable');

    $caps = $aiModule['capabilities']['exposes'] ?? [];
    $found = false;
    foreach ($caps as $cap) {
        if (($cap['id'] ?? '') === 'ai.search.discover@1') {
            $found = true;
            break;
        }
    }
    assert($found, 'ai.search.discover@1 should be in AI module exposes');
    return true;
});

// ── Test 8: Migration 007 column exists ─────────────────────────
$harness->test('ac_similarity_reports has report_ai_narrative column', function () {
    $_SERVER['HTTP_HOST'] = 'aiss.test';
    $tenantId = app()->tenant()->current();
    $db = app()->dbForTenant($tenantId);

    $cols = $db->query("SHOW COLUMNS FROM ac_similarity_reports LIKE 'report_ai_narrative'")->fetchAll();
    assert(count($cols) > 0, 'report_ai_narrative column should exist');
    return true;
});

// ── Test 9: AI narrative generation does not crash ──────────────
$harness->test('generateAiReportNarrative returns null gracefully when AI unavailable', function () {
    $_SERVER['HTTP_HOST'] = 'aiss.test';
    $tenantId = app()->tenant()->current();

    $pipeline = new AcademicSimilarityPipelineService($tenantId);

    // Use reflection to call private method
    $ref = new ReflectionClass($pipeline);
    $method = $ref->getMethod('generateAiReportNarrative');
    $method->setAccessible(true);

    $result = $method->invoke($pipeline, 999999, [
        'submission_title' => 'Test',
        'raw_score' => 0,
        'adjusted_score' => 0,
        'total_matches' => 0,
        'matched_word_count' => 0,
        'total_eligible_words' => 0,
    ]);

    // Should return null (AI unavailable) without throwing
    assert($result === null || is_string($result), 'Should return null or string');
    return true;
});

// ── Test 10: Public form still works ────────────────────────────
$harness->test('Public submission form is not broken', function () {
    $_SERVER['HTTP_HOST'] = 'aiss.test';
    $_SERVER['REQUEST_URI'] = '/cms/page/ai-similarity-checker';
    $_SERVER['REQUEST_METHOD'] = 'GET';

    ob_start();
    require __DIR__ . '/../public/index.php';
    $html = ob_get_clean();

    assert(str_contains($html, 'ac-sim-public-wrap'), 'Form should be present in rendered output');
    return true;
});

// ── Run all tests ───────────────────────────────────────────────
$harness->run();
