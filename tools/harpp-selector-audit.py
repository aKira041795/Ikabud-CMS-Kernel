#!/usr/bin/env python3
"""
Selector audit: every id/class a module's JS queries must still exist after a UI change.

WHY THIS EXISTS
A renamed class is silent breakage - the page renders, the button does nothing, no error is
logged. A UI refactor contract must therefore prove the JS's selector dependencies survived.

FALSE-POSITIVE RULE (learned the hard way)
Some selectors are CREATED by the JS itself - it builds the element as an HTML string and then
queries for it. `.save-user` in users.js is exactly this: the class appears nowhere in a template
and never should. Flagging it would be a false red, and a false red is as damaging as a false
green because it destroys trust in the instrument. So a selector is only reported as MISSING when:
  (a) it is absent from the templates, AND
  (b) it is not constructed inside the JS source itself.

Usage:  python3 tools/harpp-selector-audit.py <module> [--js-dir PATH] [--tpl-glob GLOB]
Exit:   0 = every dependency resolves, 1 = at least one is genuinely missing
"""
import argparse
import glob
import os
import re
import sys

JS_DIR_DEFAULT = "modules/{module}/assets"
TPL_GLOBS_DEFAULT = ("templates/modules/{module}/*.disyl",)

PAT_ID = re.compile(r"getElementById\(\s*['\"]([^'\"]+)['\"]")
PAT_SEL = re.compile(r"querySelector(?:All)?\(\s*['\"]([#.][A-Za-z0-9_\-]+)")


def load_js(js_dir):
    out = {}
    for f in sorted(glob.glob(os.path.join(js_dir, "*.js"))):
        try:
            out[f] = open(f, encoding="utf-8", errors="replace").read()
        except OSError as e:
            print(f"  ! cannot read {f}: {e}", file=sys.stderr)
    return out


def load_templates(globs):
    out = {}
    for g in globs:
        for f in sorted(glob.glob(g)):
            try:
                out[f] = open(f, encoding="utf-8", errors="replace").read()
            except OSError as e:
                print(f"  ! cannot read {f}: {e}", file=sys.stderr)
    return out


def created_in_js(js_src, token):
    """True if this JS file builds an element carrying the token (so it need not exist in a template)."""
    if re.search(r'class\s*=\s*"[^"]*\b' + re.escape(token) + r'\b', js_src):
        return True
    if re.search(r"class\s*=\s*'[^']*\b" + re.escape(token) + r"\b", js_src):
        return True
    if re.search(r'id\s*=\s*["\']' + re.escape(token) + r'["\']', js_src):
        return True
    # classList.add('token') / .className = '... token ...'
    if re.search(r"classList\.add\(\s*['\"]" + re.escape(token) + r"['\"]", js_src):
        return True
    if re.search(r"className\s*=\s*['\"][^'\"]*\b" + re.escape(token) + r"\b", js_src):
        return True
    return False


def in_templates(templates, token, is_id):
    for src in templates.values():
        if is_id:
            if f'id="{token}"' in src or f"id='{token}'" in src:
                return True
        else:
            if re.search(r'class="[^"]*\b' + re.escape(token) + r'\b', src):
                return True
            if re.search(r"class='[^']*\b" + re.escape(token) + r"\b", src):
                return True
    return False


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("module")
    ap.add_argument("--js-dir")
    ap.add_argument("--tpl-glob", action="append")
    args = ap.parse_args()

    js_dir = args.js_dir or JS_DIR_DEFAULT.format(module=args.module)
    globs = args.tpl_glob or [g.format(module=args.module) for g in TPL_GLOBS_DEFAULT]

    js = load_js(js_dir)
    tpl = load_templates(globs)
    if not js:
        print(f"no JS found in {js_dir}")
        return 1
    print(f"module={args.module}  js_files={len(js)}  templates={len(tpl)}")

    missing = 0
    selfmade = 0
    checked = 0
    for f, src in js.items():
        ids = set(PAT_ID.findall(src))
        sels = set(PAT_SEL.findall(src))
        local_missing = []
        for tok in sorted(ids):
            checked += 1
            if in_templates(tpl, tok, True):
                continue
            if created_in_js(src, tok):
                selfmade += 1
                continue
            local_missing.append("id#" + tok)
        for s in sorted(sels):
            checked += 1
            tok = s[1:]
            if in_templates(tpl, tok, s.startswith("#")):
                continue
            if created_in_js(src, tok):
                selfmade += 1
                continue
            local_missing.append(s)
        if local_missing:
            print(f"\n{os.path.basename(f)}  -- GENUINELY MISSING:")
            for m in local_missing:
                print("   " + m)
            missing += len(local_missing)

    print(f"\nchecked={checked}  js_created={selfmade}  missing={missing}")
    return 1 if missing else 0


if __name__ == "__main__":
    sys.exit(main())
