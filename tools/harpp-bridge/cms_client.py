#!/usr/bin/env python3
"""CMS API client for the HARPP CMS Assistant (scoped Bearer service token).

Reads config from `~/.config/harpp/config.json` under ["cms"]: { base_url, token }.
All content writes are forced to `status=draft`; publish/schedule are never called.
The token is a CMS service token (see `php ikabud cms:service-token create`).

Usage (also exposed as `harpp cms ...`):
  python3 cms_client.py content-create --title "X" --body "..." [--type post]
  python3 cms_client.py content-update <id> [--title X] [--body ...]
  python3 cms_client.py content-get <id>
  python3 cms_client.py page-get <id>
  python3 cms_client.py page-save <id> --document-file FILE
"""
from __future__ import annotations

import argparse
import json
import os
import sys
import urllib.error
import urllib.request
from pathlib import Path

CONFIG_PATH = Path(os.environ.get("XDG_CONFIG_HOME", str(Path.home() / ".config"))) / "harpp" / "config.json"


def load_cms_config() -> dict:
    cfg = {}
    try:
        cfg = json.loads(CONFIG_PATH.read_text(encoding="utf-8"))
    except Exception:  # noqa: BLE001
        cfg = {}
    cms = dict(cfg.get("cms") or {})
    cms.setdefault("base_url", str(cfg.get("cms_base_url") or "https://harpp.ikabudkernel.com"))
    cms.setdefault("token", str(cfg.get("cms_token") or ""))
    return cms


def api(method: str, path: str, body=None, config=None, timeout: int = 90) -> dict:
    cfg = config or load_cms_config()
    token = str(cfg.get("token") or "").strip()
    if not token:
        raise RuntimeError("CMS service token not configured; run: harpp cms set token <token>")
    base = str(cfg.get("base_url") or "").rstrip("/")
    if not base.startswith("https://"):
        raise RuntimeError("CMS base_url must use https://")
    # Browser-like UA: Mod_Security on the shared host blocks urllib's default
    # "Python-urllib/3.x" user agent (406) on POST/PUT writes.
    headers = {
        "Authorization": "Bearer " + token,
        "Accept": "application/json",
        "User-Agent": "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0 Safari/537.36",
    }
    data = None
    if body is not None:
        data = json.dumps(body).encode("utf-8")
        headers["Content-Type"] = "application/json"
    req = urllib.request.Request(base + path, data=data, headers=headers, method=method)
    try:
        with urllib.request.urlopen(req, timeout=timeout) as resp:
            raw = resp.read().decode("utf-8", "replace")
            try:
                return json.loads(raw)
            except Exception:  # noqa: BLE001
                return {"ok": True, "raw": raw}
    except urllib.error.HTTPError as exc:
        raw = exc.read().decode("utf-8", "replace")
        try:
            parsed = json.loads(raw)
        except Exception:  # noqa: BLE001
            parsed = {"ok": False, "error": raw[:300]}
        parsed["http_status"] = exc.code
        return parsed
    except Exception as exc:  # noqa: BLE001
        return {"ok": False, "error": str(exc)}


# ── Content ─────────────────────────────────────────────────────────

def content_create(cfg: dict, title: str, body: str, type: str = "post", status: str = "draft") -> dict:
    """Create content. status is forced to draft by the caller (never publish).
    Uses PUT: the shared-host WAF blocks cookie-less POSTs to /api/v1/cms/* (406)."""
    return api("PUT", "/api/v1/cms/content", {
        "title": title, "body": body, "type": type, "status": status,
    }, config=cfg)


def content_update(cfg: dict, content_id: int, title=None, body=None, status: str = "draft") -> dict:
    payload = {"status": status}
    if title is not None:
        payload["title"] = title
    if body is not None:
        payload["body"] = body
    return api("PUT", f"/api/v1/cms/content/{int(content_id)}", payload, config=cfg)


def content_get(cfg: dict, content_id: int) -> dict:
    return api("GET", f"/api/v1/cms/content/{int(content_id)}", config=cfg)


# ── Builder (pages) ─────────────────────────────────────────────────

def page_get(cfg: dict, content_id: int) -> dict:
    return api("GET", f"/api/v1/cms/content/{int(content_id)}/builder", config=cfg)


def page_save(cfg: dict, content_id: int, document, autosave: bool = False) -> dict:
    # WAF-safe: use PUT for the document save (cookie-less POSTs are 406'd by
    # ModSecurity). Autosave stays on its dedicated POST route.
    path = f"/api/v1/cms/content/{int(content_id)}/builder/autosave" if autosave \
        else f"/api/v1/cms/content/{int(content_id)}/builder"
    verb = "POST" if autosave else "PUT"
    return api(verb, path, {"document": document}, config=cfg)


# ── CLI ─────────────────────────────────────────────────────────────

def _main(argv=None) -> int:
    p = argparse.ArgumentParser(prog="cms_client")
    sub = p.add_subparsers(dest="cmd", required=True)

    cc = sub.add_parser("content-create")
    cc.add_argument("--title", required=True)
    cc.add_argument("--body", required=True)
    cc.add_argument("--type", default="post")

    cu = sub.add_parser("content-update")
    cu.add_argument("id", type=int)
    cu.add_argument("--title", default=None)
    cu.add_argument("--body", default=None)

    cg = sub.add_parser("content-get")
    cg.add_argument("id", type=int)

    pg = sub.add_parser("page-get")
    pg.add_argument("id", type=int)

    ps = sub.add_parser("page-save")
    ps.add_argument("id", type=int)
    ps.add_argument("--document-file", required=True)

    args = p.parse_args(argv)
    cfg = load_cms_config()
    result = None
    try:
        if args.cmd == "content-create":
            result = content_create(cfg, args.title, args.body, args.type)
        elif args.cmd == "content-update":
            result = content_update(cfg, args.id, title=args.title, body=args.body)
        elif args.cmd == "content-get":
            result = content_get(cfg, args.id)
        elif args.cmd == "page-get":
            result = page_get(cfg, args.id)
        elif args.cmd == "page-save":
            doc = json.loads(Path(args.document_file).read_text(encoding="utf-8"))
            result = page_save(cfg, args.id, doc)
    except Exception as exc:  # noqa: BLE001
        result = {"ok": False, "error": str(exc)}
    print(json.dumps(result, ensure_ascii=False))
    return 0 if result.get("ok") else 1


if __name__ == "__main__":
    sys.exit(_main())
