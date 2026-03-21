#!/bin/bash
# ===========================================
# CSS Build Script for Native Default Theme
# Version: 2.1.0
# ===========================================
#
# This script concatenates all CSS partials into a single
# production-ready CSS file (theme.min.css)
#
# Usage: ./build-css.sh
#
# Requirements: cat (standard Unix tool)
# Optional: cssnano, clean-css-cli, or similar for minification

# Get the directory where this script is located
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

# Output file
OUTPUT_FILE="theme.min.css"
TEMP_FILE="theme.combined.css"

echo "Building CSS for Native Default Theme..."
echo "========================================="

# Concatenate all partials in the correct order
cat \
    base/_variables.css \
    base/_reset.css \
    base/_typography.css \
    layout/_container.css \
    layout/_header.css \
    layout/_footer.css \
    layout/_sidebar.css \
    components/_buttons.css \
    components/_forms.css \
    components/_cards.css \
    components/_widgets.css \
    components/_pagination.css \
    components/_comments.css \
    components/_page-builder.css \
    pages/_single.css \
    pages/_archive.css \
    pages/_search.css \
    utilities/_utilities.css \
    > "$TEMP_FILE"

# Check if concatenation was successful
if [ $? -eq 0 ]; then
    echo "✓ CSS files concatenated successfully"
else
    echo "✗ Error concatenating CSS files"
    exit 1
fi

# Check if minification tools are available
if command -v cleancss &> /dev/null; then
    echo "Using clean-css for minification..."
    cleancss -o "$OUTPUT_FILE" "$TEMP_FILE"
elif command -v cssnano &> /dev/null; then
    echo "Using cssnano for minification..."
    cssnano "$TEMP_FILE" "$OUTPUT_FILE"
elif command -v npx &> /dev/null; then
    echo "Using npx clean-css-cli for minification..."
    npx clean-css-cli -o "$OUTPUT_FILE" "$TEMP_FILE" 2>/dev/null
    if [ $? -ne 0 ]; then
        echo "Note: clean-css-cli not installed. Using unminified output."
        cp "$TEMP_FILE" "$OUTPUT_FILE"
    fi
else
    echo "Note: No CSS minifier found. Using unminified output."
    echo "Install clean-css-cli for minification: npm install -g clean-css-cli"
    cp "$TEMP_FILE" "$OUTPUT_FILE"
fi

# Clean up temp file
rm -f "$TEMP_FILE"

# Report file sizes
if [ -f "$OUTPUT_FILE" ]; then
    SIZE=$(wc -c < "$OUTPUT_FILE")
    SIZE_KB=$((SIZE / 1024))
    echo "✓ Output: $OUTPUT_FILE (${SIZE_KB}KB)"
    echo "========================================="
    echo "Build complete!"
else
    echo "✗ Error: Output file not created"
    exit 1
fi
