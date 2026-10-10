/**
 * Builder core verification — Copy Style / Paste Style (and the document
 * mutations it shares a code path with).
 *
 * WHY THIS EXISTS: the builder's correctness lives in `builderReducer`, which is
 * a pure function over the document tree. It previously had no test at all — the
 * only coverage of builder behaviour was PHP, on the server renderer, which
 * cannot see this. A pure reducer needs no React renderer to exercise, so this
 * runs it directly in node.
 *
 * Run:  npm run test:core      (compiles src/builder/core, then runs this file)
 *
 * Contract asserted here, from the chair consult of 2026-10-10:
 *   - Copy Style captures style and responsive overrides ONLY — never props,
 *     content or identity, so a paste can never move content between widgets;
 *   - Paste Style merges (copied keys win, target-only keys survive) rather than
 *     replacing, so it cannot silently drop a layout the author set;
 *   - one paste is ONE document mutation, so it is one undo step;
 *   - a paste with no clipboard, no targets, or unknown targets is a no-op and
 *     does not dirty the document.
 */
const Module = require('module');
const path = require('path');
const fs = require('fs');

// The package is "type": "module", so the compiled output tree would be treated
// as ESM and could not be require()d. Scope the build directory back to CommonJS.
const OUT = path.join(__dirname, '..', '.corecheck');
fs.mkdirSync(path.join(OUT, 'lib'), { recursive: true });
fs.writeFileSync(path.join(OUT, 'package.json'), JSON.stringify({ type: 'commonjs' }));
// The builder core imports the API client, which this check never exercises.
fs.writeFileSync(path.join(OUT, 'lib', 'api.js'), 'module.exports = {};\n');

// tsc leaves the `@/*` path alias in the emitted require() calls, which node
// cannot resolve. Map it at the output root.
const originalResolve = Module._resolveFilename;
Module._resolveFilename = function (request, ...rest) {
  if (typeof request === 'string' && request.startsWith('@/')) {
    return originalResolve.call(this, path.join(OUT, request.slice(2)), ...rest);
  }
  return originalResolve.call(this, request, ...rest);
};

const { builderReducer } = require(path.join(OUT, 'builder/core/useBuilderState.js'));

let pass = 0;
let fail = 0;
function check(label, ok, detail) {
  if (ok) {
    pass++;
    console.log('  PASS: ' + label);
  } else {
    fail++;
    console.log('  FAIL: ' + label + (detail ? ' :: ' + detail : ''));
  }
}

const node = (id, type, style, props, children) => ({
  id, type, props: props || {}, style: style || {}, children: children || [], meta: {},
});

const find = (root, id) => {
  if (root.id === id) return root;
  for (const child of root.children || []) {
    const hit = find(child, id);
    if (hit) return hit;
  }
  return null;
};

// document > section > [heading (source), button (target)]
const root = node('doc_root', 'document', {}, {}, [
  node('sec1', 'section', {}, {}, [
    node('h1', 'heading', { color: 'red', padding: '4px', tablet: { fontSize: '12px' } }, { content: 'Hello', level: 2 }),
    node('b1', 'button', { backgroundColor: 'blue' }, { href: '/go', variant: 'primary' }),
  ]),
]);

const baseState = {
  document: root,
  isDirty: false,
  selectedIds: ['h1'],
  hoveredId: null,
  activeTool: 'select',
  sidebarTab: 'components',
  zoom: 100,
  viewport: 'desktop',
  clipboard: null,
  styleClipboard: null,
};

console.log('Copy Style / Paste Style');

const copied = builderReducer(baseState, { type: 'COPY_STYLE', nodeId: 'h1' });
check('COPY_STYLE stores the source style',
  copied.styleClipboard !== null
  && copied.styleClipboard.color === 'red'
  && copied.styleClipboard.padding === '4px');
check('COPY_STYLE carries responsive overrides',
  !!copied.styleClipboard.tablet && copied.styleClipboard.tablet.fontSize === '12px');
check('COPY_STYLE does not mark the document dirty', copied.isDirty === false);
check('COPY_STYLE leaves the document identical', copied.document === baseState.document);
check('COPY_STYLE on a missing node is a no-op',
  builderReducer({ ...baseState, styleClipboard: null }, { type: 'COPY_STYLE', nodeId: 'nope' }).styleClipboard === null);

const pasted = builderReducer(copied, { type: 'PASTE_STYLE', nodeIds: ['b1'] });
const newButton = find(pasted.document, 'b1');
const oldButton = find(root, 'b1');

check('PASTE_STYLE transfers the copied keys',
  newButton.style.color === 'red' && newButton.style.padding === '4px');
check('PASTE_STYLE keeps target-only keys (merge, not replace)',
  newButton.style.backgroundColor === 'blue');
check('PASTE_STYLE transfers responsive overrides',
  !!newButton.style.tablet && newButton.style.tablet.fontSize === '12px');
check('PASTE_STYLE does NOT touch props — style only',
  JSON.stringify(newButton.props) === JSON.stringify(oldButton.props) && newButton.props.href === '/go');
check('PASTE_STYLE does NOT touch node identity or type',
  newButton.id === 'b1' && newButton.type === 'button');
check('PASTE_STYLE leaves the source node untouched',
  JSON.stringify(find(pasted.document, 'h1').style) === JSON.stringify(find(root, 'h1').style));
check('PASTE_STYLE marks the document dirty', pasted.isDirty === true);
check('PASTE_STYLE is one document mutation (one undo step)', pasted.document !== copied.document);

const multi = builderReducer(copied, { type: 'PASTE_STYLE', nodeIds: ['b1', 'sec1'] });
check('PASTE_STYLE applies to every target in one action',
  find(multi.document, 'b1').style.color === 'red' && find(multi.document, 'sec1').style.color === 'red');
check('PASTE_STYLE with no clipboard is a no-op',
  builderReducer({ ...baseState, styleClipboard: null }, { type: 'PASTE_STYLE', nodeIds: ['b1'] }).document === baseState.document);
check('PASTE_STYLE with an empty target list is a no-op',
  builderReducer(copied, { type: 'PASTE_STYLE', nodeIds: [] }).document === copied.document);
check('PASTE_STYLE over unknown ids is a no-op',
  builderReducer(copied, { type: 'PASTE_STYLE', nodeIds: ['ghost'] }).document === copied.document);

// A paste must not mutate the captured style via a shared reference.
const afterSecondPaste = builderReducer(pasted, { type: 'PASTE_STYLE', nodeIds: ['h1'] });
check('the style clipboard survives repeated pastes',
  afterSecondPaste.styleClipboard.color === 'red');

console.log('\nBuilder core: ' + pass + ' passed, ' + fail + ' failed');
process.exit(fail === 0 ? 0 : 1);
