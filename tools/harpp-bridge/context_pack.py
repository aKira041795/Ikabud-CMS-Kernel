#!/usr/bin/env python3
"""Deterministic, read-only workspace retrieval for HARPP prompts.

Repository facts use git when available.  Saved chair conclusions are read directly
because they are commonly untracked.  No index or third-party package is required.
"""
from __future__ import annotations

import json
import re
import subprocess
from pathlib import Path

# Keep retrieval isolated from callers that replace subprocess.Popen while testing
# their own process launchers.
_POPEN = subprocess.Popen

_STOP = frozenset(
    "a an and are as at be by do for from how in is it of on or should the this to we what with anything".split()
)
_SECRET_PATH = re.compile(r"(^|/)(\.env(?:\.|$)|.*(?:private[_-]?key|credentials?|secrets?)(?:\.|/|$))", re.I)
_SECRET_TEXT = re.compile(
    r"HARPP_BRIDGE_KEY|DB_PASSWORD|BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY|"
    r"(?:password|passwd|secret|api[_-]?key|access[_-]?token)\s*[:=]",
    re.I,
)
_HEADING = re.compile(r"^\s{0,3}#{1,6}\s+\S")


def _git(root: Path, args: list[str]) -> str:
    proc = None
    try:
        proc = _POPEN(
            ["git", "-C", str(root), *args], stdout=subprocess.PIPE,
            stderr=subprocess.PIPE, text=True, encoding="utf-8", errors="replace",
        )
        stdout, _stderr = proc.communicate(timeout=20)
        return stdout if proc.returncode in (0, 1) else ""
    except Exception:  # retrieval must never make its caller fail
        if proc is not None:
            try:
                proc.kill()
                proc.communicate()
            except Exception:
                pass
        return ""


def _terms(query: str) -> list[str]:
    words = re.findall(r"[a-z0-9][a-z0-9_.@/-]*", query.lower())
    result: list[str] = []
    for word in words:
        variants = [word]
        if "-" in word:
            variants.extend(part for part in word.split("-") if len(part) > 2)
        for value in variants:
            if len(value) > 1 and value not in _STOP and value not in result:
                result.append(value)
    return result[:12]


def _safe_path(path: str, tracked: set[str]) -> bool:
    return path in tracked and not _SECRET_PATH.search(path) and "\x00" not in path


def _safe_line(text: str) -> bool:
    return bool(text.strip()) and not _SECRET_TEXT.search(text)


def _score(text: str, path: str, terms: list[str], phrase: str) -> int:
    haystack = (path + "\n" + text).lower()
    matched = [term for term in terms if term in haystack]
    # Coverage matters more than repetition of one broad word (for example "runs").
    score = (2 * len(matched) * len(matched)) + sum(
        3 for term in matched if term in path.lower()
    )
    if phrase and phrase in haystack:
        score += 8
    return score


def _add(entries: list[tuple[int, int, str, str]], seen: set[tuple[str, int]],
         rank: int, score: int, path: str, line: int, text: str) -> None:
    key = (path, max(1, line))
    cleaned = " ".join(text.strip().split())
    content_key = ("content:" + cleaned.lower(), 0)
    if key in seen or content_key in seen or not cleaned or not _safe_line(cleaned):
        return
    seen.add(key)
    seen.add(content_key)
    entries.append((rank, -score, path, f"{path}:{key[1]} {cleaned}"))


def _doc_entries(root: Path, tracked: set[str], terms: list[str], phrase: str,
                 entries: list, seen: set) -> tuple[set[str], str]:
    named: set[str] = set()
    matched_text: list[str] = []
    candidates: list[tuple[int, str, list[str], list[tuple[int, int]]]] = []
    for path in sorted(p for p in tracked if p.startswith("docs/") and p.endswith(".md")):
        try:
            lines = (root / path).read_text(encoding="utf-8", errors="replace").splitlines()
        except Exception:
            continue
        # A term in a path selects the document, not every line in that document.
        # Snippets themselves must match the intent, otherwise headings crowd out facts.
        matches = [(i, _score(line, "", terms, phrase)) for i, line in enumerate(lines)
                   if _score(line, "", terms, phrase) > 0]
        if matches:
            path_bonus = sum(3 for term in terms if term in path.lower())
            candidates.append((max(score for _, score in matches) + path_bonus,
                               path, lines, matches))
    for _, path, lines, matches in sorted(candidates, key=lambda item: (-item[0], item[1]))[:4]:
        for index, score in sorted(matches, key=lambda item: (-item[1], item[0]))[:1]:
            heading = next((h for h in range(index, -1, -1) if _HEADING.match(lines[h])), None)
            if heading is not None:
                _add(entries, seen, 1, score + 2, path, heading + 1, lines[heading])
            for pos in range(max(0, index - 1), min(len(lines), index + 2)):
                _add(entries, seen, 1, score, path, pos + 1, lines[pos])
            snippet = " ".join(lines[max(0, index - 1):min(len(lines), index + 2)])
            matched_text.append(snippet.lower())
            named.update(re.findall(r"\b[a-z0-9]+(?:-[a-z0-9]+)+\b", snippet.lower()))
            parts = path.split("/")
            if len(parts) > 2:
                named.add(parts[1].lower())
    return named, "\n".join(matched_text)


def _module_entries(root: Path, tracked: set[str], terms: list[str], phrase: str,
                    doc_names: set[str], doc_text: str, entries: list, seen: set) -> None:
    for path in sorted(p for p in tracked if re.fullmatch(r"modules/[^/]+/module\.json", p)):
        module = path.split("/")[1].lower()
        direct = module in doc_names or any(t in module or module in t for t in terms)
        if not direct:
            continue
        try:
            raw = (root / path).read_text(encoding="utf-8", errors="replace")
            data = json.loads(raw)
        except Exception:
            continue
        score = _score(raw, path, terms, phrase) + (8 if direct else 0)
        for key in ("capabilities", "routes"):
            if key not in data:
                continue
            line = next((i for i, value in enumerate(raw.splitlines(), 1)
                         if re.search(rf'"{re.escape(key)}"\s*:', value)), 1)
            rendered = json.dumps(data[key], sort_keys=True, ensure_ascii=True, separators=(",", ":"))
            if len(rendered) > 900:
                rendered = rendered[:880] + "...[field truncated]"
            _add(entries, seen, 2, score, path, line, f"{key}: {rendered}")


def _grep_entries(root: Path, tracked: set[str], terms: list[str], phrase: str,
                  entries: list, seen: set) -> list[str]:
    if not terms:
        return []
    args = ["grep", "-n", "-I", "--full-name", "-i"]
    for term in terms:
        args.extend(["-e", term])
    output = _git(root, args + ["--"])
    by_file: dict[str, list[tuple[int, str, int]]] = {}
    for raw in output.splitlines():
        match = re.match(r"([^:]+):(\d+):(.*)", raw)
        if not match:
            continue
        path, number, text = match.group(1), int(match.group(2)), match.group(3)
        if not _safe_path(path, tracked) or not _safe_line(text):
            continue
        score = _score(text, path, terms, phrase)
        by_file.setdefault(path, []).append((number, text, score))
    ranked = sorted(by_file, key=lambda p: (-max(x[2] for x in by_file[p]), p))[:8]
    for path in ranked:
        hits = sorted(by_file[path], key=lambda x: (-x[2], x[0]))[:3]
        try:
            lines = (root / path).read_text(encoding="utf-8", errors="replace").splitlines()
        except Exception:
            lines = []
        for number, text, score in hits:
            # Include one neighbouring line where possible, but never emit a secret line.
            for pos in range(max(1, number - 1), min(len(lines), number + 1) + 1):
                _add(entries, seen, 3, score if pos == number else max(1, score - 1),
                     path, pos, lines[pos - 1])
    return ranked


def _history_entries(root: Path, paths: list[str], entries: list, seen: set) -> None:
    if not paths:
        return
    output = _git(root, ["log", "-n", "8", "--format=%h %s", "--", *paths[:20]])
    subjects = [line for line in output.splitlines() if _safe_line(line)]
    if subjects:
        # Commits have no source line; cite the first matched file that scoped git log.
        _add(entries, seen, 4, len(subjects), paths[0], 1,
             "recent commits for matched paths: " + " | ".join(subjects))


def _ledger_entries(root: Path, tracked: set[str], terms: list[str], phrase: str,
                    entries: list, seen: set) -> None:
    paths = [p for p in tracked if p == ".ai/chair/ledger.md"]
    for path in sorted(paths):
        try:
            lines = (root / path).read_text(encoding="utf-8", errors="replace").splitlines()
        except Exception:
            continue
        hits = [(i, _score(line, path, terms, phrase)) for i, line in enumerate(lines)
                if _score(line, path, terms, phrase) > 0 and _safe_line(line)]
        for index, score in sorted(hits, key=lambda x: (-x[1], x[0]))[:3]:
            _add(entries, seen, 5, score, path, index + 1, lines[index])


def _without_embedded_packs(lines: list[str]) -> list[tuple[int, str]]:
    """(original line number, text) worth indexing from a saved .ai artefact.

    Two things are dropped, both measured 2026-10-06:

    1. Previously embedded retrieved-facts blocks. A saved consultation stores the pack it was
       given; indexing those lines re-serves a copy of the repository as if it were the chair's
       conclusion, and one long transcript then consumes the whole budget (a 900-char pack was
       entirely one consultation quoting an older pack).
    2. When a consultation HAS a reply, its scaffolding. The heading, the idea/instruction
       labels and the composer's own boilerplate match a query's words but conclude nothing, so
       they crowded out real facts. The reply IS the conclusion, so only replies are indexed.

    A file with no reply marker is indexed as-is, so a plan or a note keeps working.
    """
    replies_only = any(line.startswith("# CHATGPT REPLY") for line in lines)
    kept: list[tuple[int, str]] = []
    in_pack = in_reply = False
    for number, line in enumerate(lines, start=1):
        if line.strip() == "# RETRIEVED REPOSITORY FACTS":
            in_pack = True
            continue
        if line.startswith("# CHATGPT REPLY"):
            in_pack, in_reply = False, True
            continue
        if in_pack and line.startswith("#"):
            in_pack = False
        if in_reply and not line.strip():
            continue
        if in_reply and line.startswith("#"):
            in_reply = False
        if not in_pack and (not replies_only or in_reply):
            kept.append((number, line))
    return kept


def _conclusion_entries(root: Path, terms: list[str], phrase: str,
                        entries: list, seen: set) -> None:
    """Read saved chair conclusions directly, including untracked files."""
    candidates: list[tuple[int, str, list[str]]] = []
    patterns = (".ai/consult/*.md", ".ai/debate/plan-*.md")
    for pattern in patterns:
        for file_path in root.glob(pattern):
            try:
                if not file_path.is_file() or file_path.is_symlink():
                    continue
                relative = file_path.relative_to(root).as_posix()
                if _SECRET_PATH.search(relative):
                    continue
                lines = _without_embedded_packs(
                    file_path.read_text(encoding="utf-8", errors="replace").splitlines())
                candidates.append((file_path.stat().st_mtime_ns, relative, lines))
            except Exception:
                continue
    ranked: list[tuple[int, int, str, int, str]] = []
    for modified, path, lines in candidates:
        for index, line in lines:
            score = _score(line, path, terms, phrase)
            if (score > 0 and _safe_line(line) and
                    line.strip() != "# RETRIEVED REPOSITORY FACTS"):
                ranked.append((-score, -modified, path, index, line))
    for neg_score, neg_modified, path, index, line in sorted(ranked)[:12]:
        number = index
        cleaned = " ".join(line.strip().split())
        key = (path, number)
        content_key = ("content:" + cleaned.lower(), 0)
        if key in seen or content_key in seen or not cleaned or not _safe_line(cleaned):
            continue
        seen.update((key, content_key))
        # The third tuple field is only a deterministic sort key.  For equal scores,
        # negative mtime puts the most recently modified conclusion first.
        order_key = f"{10**30 + neg_modified:031d}:{path}"
        entries.append((0, neg_score, order_key, f"{path}:{number} {cleaned}"))


def build_context_pack(query: str, workspace: str | None, budget_chars: int = 6000,
                       extra_facts: str | None = "") -> str:
    """Return a cited context pack, or ``""`` for invalid/unavailable input.

    Failures are intentionally swallowed: retrieval is grounding assistance and must not
    take down the advisor lane.
    """
    try:
        query = str(query or "").strip()
        root = Path(workspace).resolve() if workspace else None
        budget = int(budget_chars)
        if not query or root is None or not root.is_dir() or budget <= 0:
            return ""
        tracked = {p for p in _git(root, ["ls-files"]).splitlines() if p}
        terms = _terms(query)
        phrase = query.lower()
        entries: list[tuple[int, int, str, str]] = []
        seen: set[tuple[str, int]] = set()
        _conclusion_entries(root, terms, phrase, entries, seen)
        conclusion_count = len(entries)
        names, doc_text = _doc_entries(root, tracked, terms, phrase, entries, seen)
        _module_entries(root, tracked, terms, phrase, names, doc_text, entries, seen)
        matched_paths = _grep_entries(root, tracked, terms, phrase, entries, seen)
        _history_entries(root, matched_paths, entries, seen)
        _ledger_entries(root, tracked, terms, phrase, entries, seen)

        # A query matched only by prior conclusions still gets one repository
        # orientation fact where available.
        if (conclusion_count > 0 and not doc_text) or len(entries) == conclusion_count:
            fallback = next((p for p in ("README.md", "docs/README.md") if p in tracked), None)
            if fallback is None:
                fallback = next(iter(sorted(p for p in tracked
                                            if p.startswith("docs/") and p.endswith(".md"))), None)
            if fallback:
                lines = (root / fallback).read_text(encoding="utf-8", errors="replace").splitlines()
                if lines:
                    index = next((i for i, line in enumerate(lines) if _HEADING.match(line)), 0)
                    _add(entries, seen, 3, 1, fallback, index + 1, lines[index])
        entries.sort(key=lambda item: (item[0], item[1], item[2], item[3]))
        header = f"Repository context for intent: {query}\n"
        if len(header) > budget:
            return "[truncated]"[:budget]
        result = header
        omitted = False
        marker = "\n[context pack truncated to budget]"
        facts = str(extra_facts or "")
        # Durable memory is trusted text, but credential-shaped lines remain excluded
        # under the same no-secrets guarantee as workspace snippets.
        if facts and all(_safe_line(line) for line in facts.splitlines() if line.strip()):
            addition = facts + ("" if facts.endswith("\n") else "\n")
            if len(result) + len(addition) <= budget:
                result += addition
            else:
                omitted = True
        for _, _, _, line in entries:
            addition = line + "\n"
            if len(result) + len(addition) <= budget:
                result += addition
            else:
                omitted = True
        if omitted:
            if len(result) + len(marker) <= budget:
                result = result.rstrip("\n") + marker
            else:
                # Remove complete low-ranked entries to make room; never cut a citation.
                kept = result.rstrip("\n").splitlines()
                while len("\n".join(kept)) + len(marker) > budget and len(kept) > 1:
                    kept.pop()
                candidate = "\n".join(kept) + marker
                result = candidate if len(candidate) <= budget else "[truncated]"[:budget]
        return result.rstrip("\n")
    except Exception:
        return ""
