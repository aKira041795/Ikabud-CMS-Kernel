#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────
# Performance Baseline Benchmark Script
#
# Usage:
#   bash scripts/benchmark-routes.sh [BASE_URL]
#
# Defaults to http://localhost if no BASE_URL provided.
# Requires: ab (Apache Bench) or curl
# ─────────────────────────────────────────────────────────────────────────

set -euo pipefail

BASE_URL="${1:-http://localhost}"
REQUESTS="${BENCH_REQUESTS:-100}"
CONCURRENCY="${BENCH_CONCURRENCY:-10}"
OUTPUT_DIR="storage/benchmarks"
TIMESTAMP=$(date +%Y%m%d_%H%M%S)
REPORT_FILE="$OUTPUT_DIR/benchmark_${TIMESTAMP}.txt"

mkdir -p "$OUTPUT_DIR"

echo "Performance Baseline Benchmark"
echo "=============================="
echo "Base URL:    $BASE_URL"
echo "Requests:    $REQUESTS"
echo "Concurrency: $CONCURRENCY"
echo "Report:      $REPORT_FILE"
echo ""

# Routes to benchmark
declare -A ROUTES=(
    ["health"]="/health"
    ["homepage"]="/"
    ["shop"]="/ecommerce/shop"
    ["api_products"]="/api/v1/ecommerce/products"
    ["login_page"]="/cms/login"
)

HAS_AB=0
if command -v ab &>/dev/null; then
    HAS_AB=1
fi

{
    echo "Ikabud Performance Baseline — $TIMESTAMP"
    echo "Base URL: $BASE_URL | Requests: $REQUESTS | Concurrency: $CONCURRENCY"
    echo "================================================================"
    echo ""

    for name in "${!ROUTES[@]}"; do
        path="${ROUTES[$name]}"
        url="${BASE_URL}${path}"
        echo "── $name ($path) ──"

        if [ "$HAS_AB" -eq 1 ]; then
            ab -n "$REQUESTS" -c "$CONCURRENCY" -q "$url" 2>&1 | grep -E '(Requests per second|Time per request|Failed requests|Complete requests)' || echo "  [SKIPPED — endpoint may not be available]"
        else
            # Fallback to curl timing
            TOTAL=0
            SUCCESS=0
            for i in $(seq 1 10); do
                TIME=$(curl -o /dev/null -s -w '%{time_total}' "$url" 2>/dev/null || echo "0")
                if [ "$TIME" != "0" ]; then
                    TOTAL=$(echo "$TOTAL + $TIME" | bc)
                    ((SUCCESS++))
                fi
            done
            if [ "$SUCCESS" -gt 0 ]; then
                AVG=$(echo "scale=3; $TOTAL / $SUCCESS" | bc)
                echo "  Avg response time: ${AVG}s (${SUCCESS}/10 successful)"
            else
                echo "  [SKIPPED — endpoint unreachable]"
            fi
        fi
        echo ""
    done

    echo "================================================================"
    echo "Benchmark complete at $(date)"
} | tee "$REPORT_FILE"

echo ""
echo "Full report saved to: $REPORT_FILE"
