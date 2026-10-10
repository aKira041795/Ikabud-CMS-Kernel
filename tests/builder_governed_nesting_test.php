<?php
/**
 * Governed nesting must admit top-level composition.
 *
 * THE DEFECT THIS GUARDS (reported 2026-10-10): setting a page from the page
 * builder failed with
 *
 *     "Cannot insert template element section in that governed location."
 *
 * A theme's governed nesting rule takes PRECEDENCE over the component
 * definition, so a theme rule expressed in a vocabulary the builder does not
 * have silently makes placement impossible. ARK's block definitions name the
 * page root `page`; the builder's root node type is `document`. With
 * `allowed_parents: ["page"]`, `canBeChildOf('section','document')` was false
 * while `canAcceptChild('document','section')` was true — so the root accepted a
 * section and the section rejected the root, and nothing could be placed at the
 * top level at all. 24 of ARK's 57 block definitions carried the alias.
 *
 * The assertions below are deliberately OUTCOME-level: they ask whether a
 * top-level insert is permitted, not whether a particular string was rewritten.
 *
 * Run: php tests/builder_governed_nesting_test.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);

ob_start();
require $root . '/bootstrap.php';
require_once BASE_PATH . '/src/helpers/module-manager.php';
foreach (['/helpers/50-builder.php', '/builder-renderers.php'] as $file) {
    if (file_exists(BASE_PATH . '/modules/cms' . $file)) {
        require_once BASE_PATH . '/modules/cms' . $file;
    }
}
ob_end_clean();

$passed = 0;
$failed = 0;
function gnAssert(string $label, bool $condition, string $detail = ''): void
{
    global $passed, $failed;
    if ($condition) {
        echo "PASS: {$label}\n";
        $passed++;
        return;
    }
    echo "FAIL: {$label}" . ($detail !== '' ? " :: {$detail}" : '') . "\n";
    $failed++;
}

$nesting = cmsBuilderLoadNestingConstraints();

/**
 * The browser's decision, mirrored: the governed rule wins when it declares the
 * relevant key, otherwise the component definition decides.
 */
$canAcceptChild = static function (string $parentType, string $childType) use ($nesting): bool {
    $rule = $nesting[$parentType]['allowed_children'] ?? null;
    if (is_array($rule)) {
        return in_array($childType, $rule, true);
    }
    return $parentType === 'document' && $childType === 'section'; // component definition
};
$canBeChildOf = static function (string $childType, string $parentType) use ($nesting): bool {
    $rule = $nesting[$childType]['allowed_parents'] ?? null;
    if (is_array($rule)) {
        return in_array($parentType, $rule, true);
    }
    return true; // no governed rule: fall through to the component definition
};
$isValidInsertion = static function (string $parentType, string $childType) use ($canAcceptChild, $canBeChildOf): bool {
    return $canAcceptChild($parentType, $childType) && $canBeChildOf($childType, $parentType);
};

gnAssert(
    'the theme publishes governed nesting constraints',
    $nesting !== [],
    count($nesting) . ' governed types'
);

// The exact defect: the root's structural wrappers must be placeable AT the root.
// `column` is deliberately NOT in this list — see the assertion below.
foreach (['section', 'container', 'layout_container', 'row'] as $type) {
    gnAssert(
        "a '{$type}' can be inserted at the document root",
        $isValidInsertion('document', $type),
        'canAcceptChild=' . var_export($canAcceptChild('document', $type), true)
            . ' canBeChildOf=' . var_export($canBeChildOf($type, 'document'), true)
    );
}

// A column outside a row is meaningless, and the theme says so: ARK's
// column.json declares `allowed_parents: ["row"]` even though its page-level
// schema loosely lists column as a top-level child. The intersection is the real
// contract, so this is asserted as intended behaviour rather than left as an
// unexplained gap. (row/column are also excluded from the insert palette —
// components.ts HIDDEN_COMPONENTS — because flex presets replace them.)
gnAssert(
    'a column is restricted to a row, not the page root',
    !$isValidInsertion('document', 'column') && $isValidInsertion('row', 'column')
);

// The root alias must be translated, not merely tolerated: a rule left saying
// `page` is a rule the builder can never satisfy.
$rawAlias = [];
foreach ($nesting as $type => $rule) {
    foreach (['allowed_parents', 'allowed_children'] as $key) {
        if (is_array($rule[$key] ?? null) && in_array('page', $rule[$key], true)) {
            $rawAlias[] = $type . '.' . $key;
        }
    }
}
gnAssert(
    'no governed rule still names the builder-less page root alias',
    $rawAlias === [],
    implode(', ', $rawAlias)
);

// And the root itself must not be narrowed to nothing.
gnAssert(
    'the document root still admits at least one child type',
    isset($nesting['document']['allowed_children'])
        && is_array($nesting['document']['allowed_children'])
        && $nesting['document']['allowed_children'] !== [],
    json_encode($nesting['document']['allowed_children'] ?? null)
);

// A section is where most widgets go; if sections cannot be placed, nothing can.
gnAssert(
    'a container can be inserted into a section',
    $isValidInsertion('section', 'container'),
    json_encode($nesting['container']['allowed_parents'] ?? null)
);

echo "\nGoverned nesting: {$passed} passed, {$failed} failed\n";
echo "Scope: top-level composition is possible for the active theme's governed\n";
echo "constraints. Translating theme vocabulary onto builder vocabulary is the\n";
echo "job of cmsBuilderLoadNestingConstraints(), which is where a fix belongs.\n";
exit($failed === 0 ? 0 : 1);
