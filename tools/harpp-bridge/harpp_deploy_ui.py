#!/usr/bin/env python3
"""Local HARPP deploy UI — pick a deployment package + FTP profile and upload.

Binds to 127.0.0.1 only. FTP credentials stay in the local profile store
(~/.config/harpp/deploy.json, 0600) and never leave this machine. Reuses
deploy_harpp.py for profile loading, artifact inspection, and execution.

    python3 tools/harpp-bridge/harpp_deploy_ui.py [--port 8787] [--open]

Endpoints:
    GET  /                    phone-friendly UI
    GET  /api/state           packages + profiles + receipts (UI header required)
    POST /api/build           build a fresh package with create-harpp-deploy-package.php
    POST /api/deploy          dry-run or execute a profile-bound upload
"""

import argparse
import datetime as dt
import json
import os
import re
import subprocess
import sys
import threading
import webbrowser
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
import deploy_harpp  # noqa: E402

REPO_ROOT = Path(__file__).resolve().parent.parent.parent
BUILD_SCRIPT = REPO_ROOT / "create-harpp-deploy-package.php"
DEFAULT_CONFIG = Path.home() / ".config" / "harpp" / "deploy.json"
DEFAULT_PACKAGES_DIR = REPO_ROOT
RECEIPTS_DIR = Path.home() / ".config" / "harpp" / "deploy-receipts"
UI_HEADER = "X-HARPP-DEPLOY-UI"
MAX_BODY = 1_000_000


class DeployUIError(RuntimeError):
    pass


# --------------------------------------------------------------------------
# Pure helpers (unit-testable without a running server)
# --------------------------------------------------------------------------

def list_packages(packages_dir):
    """Return deploy-package summaries (name, size, modified) newest first."""
    packages = []
    root = Path(packages_dir)
    if root.is_dir():
        for entry in sorted(root.glob("harpp-deploy-*.zip"),
                            key=lambda p: p.stat().st_mtime, reverse=True):
            st = entry.stat()
            packages.append({
                "name": entry.name,
                "size": st.st_size,
                "modified": dt.datetime.fromtimestamp(st.st_mtime, dt.timezone.utc).isoformat(),
            })
    return packages


def build_package(packages_dir):
    """Build a fresh deploy package into packages_dir via create-harpp-deploy-package.php."""
    root = Path(packages_dir)
    root.mkdir(parents=True, exist_ok=True)
    out = root / f"harpp-deploy-{dt.datetime.now().strftime('%Y%m%d-%H%M%S')}.zip"
    if not BUILD_SCRIPT.is_file():
        raise DeployUIError(f"build script not found: {BUILD_SCRIPT}")
    try:
        result = subprocess.run(["php", str(BUILD_SCRIPT), str(out)],
                                capture_output=True, text=True, timeout=120)
    except FileNotFoundError:
        raise DeployUIError("php not found on PATH (needed to build a package)")
    except subprocess.TimeoutExpired:
        raise DeployUIError("package build timed out")
    if result.returncode != 0 or not out.is_file():
        detail = (result.stderr or result.stdout or "").strip()
        raise DeployUIError(f"package build failed: {detail[:500]}")
    return {"ok": True, "path": str(out), "name": out.name,
            "output": (result.stdout or "").strip()[:2000]}


def _safe_package_path(packages_dir, name):
    root = Path(packages_dir).resolve()
    candidate = (root / name).resolve()
    if candidate.parent != root:
        raise DeployUIError("invalid package path")
    return candidate


def deploy(packages_dir, config_path, package, profile, execute=False,
           confirm=False, allow_plain_ftp=False):
    """Dry-run or execute a profile-bound upload; returns the receipt dict."""
    if not package or not profile:
        raise DeployUIError("package and profile are required")
    artifact = _safe_package_path(packages_dir, package)
    if not artifact.is_file() or artifact.suffix.lower() != ".zip":
        raise DeployUIError("package must be an existing .zip in the packages directory")
    selected = deploy_harpp.load_profile(config_path, profile)
    if execute and not confirm:
        raise DeployUIError("execute requires explicit confirmation")
    return deploy_harpp.run_deploy(selected, artifact, execute=execute,
                                   allow_plain_ftp=allow_plain_ftp)


def list_receipts(limit=40):
    receipts = []
    if RECEIPTS_DIR.is_dir():
        for path in sorted(RECEIPTS_DIR.glob("*.json"), reverse=True)[:limit]:
            try:
                data = json.loads(path.read_text(encoding="utf-8"))
            except Exception:
                continue
            receipts.append({
                "file": path.name,
                "created_at": data.get("created_at"),
                "mode": data.get("mode"),
                "profile": (data.get("profile") or {}).get("profile_name"),
                "artifact": (data.get("artifact") or {}).get("name"),
                "sha256": data.get("receipt_sha256"),
                "manual_action": data.get("manual_action"),
            })
    return receipts


# --------------------------------------------------------------------------
# HTTP server
# --------------------------------------------------------------------------

class DeployUIHandler(BaseHTTPRequestHandler):
    server_version = "HARPPDeployUI/1.0"

    def _host_ok(self):
        host = (self.headers.get("Host") or "").lower()
        return host.startswith("127.0.0.1") or host.startswith("localhost")

    def _send(self, status, body, content_type="application/json; charset=utf-8"):
        if isinstance(body, bytes):
            data = body
        elif content_type.startswith("text/html"):
            data = body.encode("utf-8")
        else:
            data = json.dumps(body).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", content_type)
        self.send_header("Content-Length", str(len(data)))
        self.send_header("Cache-Control", "no-store")
        self.end_headers()
        self.wfile.write(data)

    def _read_body(self):
        length = int(self.headers.get("Content-Length") or 0)
        if length > MAX_BODY:
            raise DeployUIError("request body too large")
        if length == 0:
            return {}
        try:
            return json.loads(self.rfile.read(length).decode("utf-8"))
        except Exception:
            raise DeployUIError("invalid JSON body")

    def _state(self):
        try:
            profiles = deploy_harpp.list_profiles(self.server.config)
        except (deploy_harpp.DeployError, OSError, ValueError) as error:
            profiles = {"profiles": [], "invalid": [], "error": str(error)}
        return {
            "ok": True,
            "packages": list_packages(self.server.packages_dir),
            "profiles": profiles,
            "receipts": list_receipts(),
            "dirs": {"packages_dir": str(Path(self.server.packages_dir).resolve()),
                     "config": str(Path(self.server.config).expanduser())},
            "plain_ftp_optin_required": True,
        }

    def do_GET(self):
        if not self._host_ok():
            return self._send(403, {"ok": False, "error": "unexpected Host"})
        try:
            if self.path in ("/", "/index.html"):
                self._send(200, PAGE_HTML, "text/html; charset=utf-8")
            elif self.path.startswith("/api/"):
                if self.headers.get(UI_HEADER) != "1":
                    return self._send(403, {"ok": False, "error": "missing UI header"})
                if self.path == "/api/state":
                    self._send(200, self._state())
                elif self.path == "/api/receipts":
                    self._send(200, {"ok": True, "receipts": list_receipts()})
                else:
                    self._send(404, {"ok": False, "error": "not found"})
            else:
                self._send(404, {"ok": False, "error": "not found"})
        except (DeployUIError, deploy_harpp.DeployError, OSError, ValueError) as error:
            self._send(400, {"ok": False, "error": str(error)})

    def do_POST(self):
        if not self._host_ok():
            return self._send(403, {"ok": False, "error": "unexpected Host"})
        if self.headers.get(UI_HEADER) != "1":
            return self._send(403, {"ok": False, "error": "missing UI header"})
        try:
            body = self._read_body()
            if self.path == "/api/build":
                self._send(200, build_package(self.server.packages_dir))
            elif self.path == "/api/deploy":
                receipt = deploy(
                    self.server.packages_dir, self.server.config,
                    package=body.get("package", ""), profile=body.get("profile", ""),
                    execute=bool(body.get("execute")), confirm=bool(body.get("confirm")),
                    allow_plain_ftp=bool(body.get("allow_plain_ftp")))
                self._send(200, {"ok": True, "receipt": receipt})
            else:
                self._send(404, {"ok": False, "error": "not found"})
        except (DeployUIError, deploy_harpp.DeployError, OSError, ValueError) as error:
            self._send(400, {"ok": False, "error": str(error)})

    def log_message(self, fmt, *args):
        sys.stderr.write("harpp-deploy-ui: %s\n" % (fmt % args))


def serve(bind, port, config, packages_dir, open_browser):
    if bind not in ("127.0.0.1", "localhost"):
        raise SystemExit("refusing to bind outside localhost (FTP profiles are local secrets)")
    server = ThreadingHTTPServer((bind, port), DeployUIHandler)
    server.config = os.environ.get("HARPP_DEPLOY_CONFIG") or str(config or DEFAULT_CONFIG)
    server.packages_dir = str(packages_dir or DEFAULT_PACKAGES_DIR)
    url = f"http://{bind}:{port}/"
    print(f"HARPP deploy UI: {url}")
    print(f"  packages: {server.packages_dir}")
    print(f"  profiles: {server.config}")
    if open_browser:
        threading.Timer(0.5, lambda: webbrowser.open(url)).start()
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        print("\nstopped")


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--bind", default="127.0.0.1", help="loopback address (localhost only)")
    parser.add_argument("--port", type=int, default=int(os.environ.get("HARPP_DEPLOY_UI_PORT", "8787")))
    parser.add_argument("--config", default=None, help="deploy profile JSON (default ~/.config/harpp/deploy.json)")
    parser.add_argument("--packages-dir", default=None, help="folder to list/build deploy packages (default repo root)")
    parser.add_argument("--open", action="store_true", help="open the UI in the default browser")
    parser.add_argument("--list", action="store_true", help="print available packages/profiles and exit")
    args = parser.parse_args(argv)
    if args.list:
        profiles = deploy_harpp.list_profiles(args.config)
        print(json.dumps({"packages": list_packages(args.packages_dir or DEFAULT_PACKAGES_DIR),
                          "profiles": profiles}, sort_keys=True, indent=2))
        return 0
    serve(args.bind, args.port, args.config, args.packages_dir, args.open)


PAGE_HTML = """<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#172554">
<title>HARPP Deploy</title>
<style>
:root{font-family:Inter,system-ui,sans-serif;color:#e2e8f0;background:#020617;--panel:#0f172a;--line:#26334a;--accent:#38bdf8}
*{box-sizing:border-box}body{margin:0;min-height:100vh;background:linear-gradient(145deg,#020617,#172554)}
.top{position:sticky;top:0;z-index:5;display:flex;align-items:center;gap:1rem;padding:.85rem max(1rem,env(safe-area-inset-left));background:#0f172aee;border-bottom:1px solid var(--line)}
.brand{font-weight:900;letter-spacing:.12em;color:#7dd3fc}.shell{max-width:760px;margin:auto;padding:1rem}
.panel{background:#0f172add;border:1px solid var(--line);border-radius:1rem;padding:1rem;margin-bottom:1rem;box-shadow:0 18px 50px #0005}
h1,h2{margin-top:0}h2{font-size:1rem;color:#7dd3fc}
label{display:grid;gap:.35rem;color:#cbd5e1;margin-bottom:.75rem}
input,select,button{width:100%;border:1px solid #334155;border-radius:.65rem;background:#020617;color:#f8fafc;padding:.7rem;font-size:1rem}
button{cursor:pointer;background:#172554;color:#e2e8f0;margin-top:.4rem}
button.primary{background:#0ea5e9;color:#02131f;font-weight:700}
button.danger{background:#7f1d1d;color:#fff;font-weight:700}
button:disabled{opacity:.5;cursor:not-allowed}
.row{display:flex;gap:.6rem;flex-wrap:wrap}.row button{flex:1}
.muted{color:#94a3b8;font-size:.8rem}
pre{background:#020617;border:1px solid #334155;border-radius:.65rem;padding:.75rem;overflow:auto;font-size:.75rem;white-space:pre-wrap;word-break:break-word}
.status{min-height:1.3rem;color:#7dd3fc;margin:.5rem 0}
ul.recs{list-style:none;padding:0;margin:0;max-height:16rem;overflow:auto}
ul.recs li{padding:.5rem .25rem;border-bottom:1px solid var(--line);font-size:.8rem}
.kv{display:grid;gap:.15rem;font-size:.8rem;color:#cbd5e1}
.warn{color:#fbbf24}.err{color:#fca5a5}
.hint{border:1px dashed #334155;border-radius:.65rem;padding:.75rem;color:#94a3b8;font-size:.8rem}
</style>
</head>
<body>
<header class="top"><a class="brand" href="/">HARPP Deploy</a><span class="muted" id="cfg-label"></span></header>
<main class="shell">
  <p class="status" id="status">Loading…</p>

  <section class="panel"><h2>1 · Deployment package</h2>
    <label>Package zip
      <select id="package-select"></select>
    </label>
    <div class="row">
      <button id="build-btn" type="button" class="primary">Build fresh package</button>
      <button id="refresh-btn" type="button">Refresh</button>
    </div>
  </section>

  <section class="panel"><h2>2 · FTP profile</h2>
    <label>Profile
      <select id="profile-select"></select>
    </label>
    <div class="kv" id="profile-detail"></div>
  </section>

  <section class="panel"><h2>3 · Upload</h2>
    <label><input type="checkbox" id="plain-ftp" style="width:auto">
      <span class="muted">Allow plain FTP (cleartext) — risk opt-in, only for profiles that cannot use SFTP/FTPS</span></label>
    <div class="row">
      <button id="dry-btn" type="button">Dry run</button>
      <button id="deploy-btn" type="button" class="danger">Deploy</button>
    </div>
    <p class="muted">Dry run never connects. Deploy uploads via the selected profile and writes a local receipt.</p>
  </section>

  <section class="panel"><h2>Receipt</h2><pre id="receipt">(none yet)</pre></section>

  <section class="panel"><h2>Recent receipts</h2><ul class="recs" id="receipts"><li class="muted">(none)</li></ul></section>
</main>
<script>
const HDR = {"X-HARPP-DEPLOY-UI": "1"};
let state = null;

function setStatus(msg, cls) { const el = document.getElementById("status"); el.textContent = msg; el.className = "status " + (cls || ""); }
function esc(s) { const d = document.createElement("div"); d.textContent = s == null ? "" : String(s); return d.innerHTML; }
async function api(path, opts) {
  const res = await fetch(path, Object.assign({headers: HDR}, opts || {}));
  let data = {};
  try { data = await res.json(); } catch (e) { data = {ok:false, error:"non-JSON response"}; }
  if (!res.ok || data.ok === false) throw new Error(data.error || ("HTTP " + res.status));
  return data;
}

function fillSelect(el, options, placeholder) {
  el.innerHTML = "";
  const ph = document.createElement("option"); ph.value = ""; ph.textContent = placeholder || "— none —";
  el.appendChild(ph);
  for (const o of options) { const op = document.createElement("option"); op.value = o.value; op.textContent = o.label; el.appendChild(op); }
}
function fmtBytes(n) { if (n == null) return "?"; if (n < 1024) return n + " B"; if (n < 1048576) return (n/1024).toFixed(1) + " KB"; return (n/1048576).toFixed(2) + " MB"; }

function packagesToOptions() {
  return (state.packages || []).map(p => ({value: p.name, label: p.name + "  ·  " + fmtBytes(p.size) + "  ·  " + (p.modified || "").slice(0,16).replace("T"," ")}));
}
function profilesToOptions() {
  return (state.profiles.profiles || []).map(p => ({value: p.profile_name, label: p.profile_name + "  ·  " + p.host + "  ·  " + p.transport}));
}
function renderProfileDetail() {
  const name = document.getElementById("profile-select").value;
  const p = (state.profiles.profiles || []).find(x => x.profile_name === name);
  const el = document.getElementById("profile-detail");
  if (!p) { el.innerHTML = ""; return; }
  el.innerHTML =
    "<div><b>host</b> " + esc(p.host) + " : " + esc(p.port) + "</div>" +
    "<div><b>user</b> " + esc(p.user) + " · <b>transport</b> " + esc(p.transport) + "</div>" +
    "<div><b>root</b> " + esc(p.root_path) + "</div>" +
    "<div><b>extraction</b> " + esc(p.extraction_adapter) + "</div>" +
    "<div><b>ops</b> " + esc((p.allowed_operations || []).join(", ")) + "</div>";
}
function renderReceipts() {
  const el = document.getElementById("receipts");
  const recs = state.receipts || [];
  if (!recs.length) { el.innerHTML = "<li class='muted'>(none)</li>"; return; }
  el.innerHTML = "";
  for (const r of recs) {
    const li = document.createElement("li");
    li.innerHTML = "<b>" + esc(r.mode || "?") + "</b> · " + esc(r.profile || "?") + " · " + esc(r.artifact || "?") +
      "<br><span class='muted'>" + esc(r.created_at || "") + (r.manual_action ? " · ⚠ " + esc(r.manual_action) : "") + "</span>";
    el.appendChild(li);
  }
}

async function loadState(selectNewPackage) {
  setStatus("Loading…");
  try {
    state = await api("/api/state");
    fillSelect(document.getElementById("package-select"), packagesToOptions(), "No packages — build one");
    fillSelect(document.getElementById("profile-select"), profilesToOptions(), "No profiles — configure ~/.config/harpp/deploy.json");
    if (selectNewPackage && state.packages.length) document.getElementById("package-select").value = state.packages[0].name;
    renderProfileDetail(); renderReceipts();
    document.getElementById("cfg-label").textContent = state.dirs.packages_dir;
    if (state.profiles.invalid && state.profiles.invalid.length) {
      setStatus("Some profiles are invalid: " + state.profiles.invalid.map(i => i.name + " (" + i.error + ")").join("; "), "warn");
    } else {
      setStatus("Ready — " + state.packages.length + " package(s), " + (state.profiles.profiles||[]).length + " profile(s)");
    }
  } catch (e) { setStatus("Error: " + e.message, "err"); }
}

async function buildPackage() {
  setStatus("Building package…"); document.getElementById("build-btn").disabled = true;
  try { await api("/api/build", {method:"POST", body:"{}"}); await loadState(true); setStatus("Package built."); }
  catch (e) { setStatus("Build failed: " + e.message, "err"); }
  finally { document.getElementById("build-btn").disabled = false; }
}

async function runDeploy(execute) {
  const pkg = document.getElementById("package-select").value;
  const prof = document.getElementById("profile-select").value;
  if (!pkg || !prof) { setStatus("Choose a package and a profile first.", "warn"); return; }
  if (execute && !confirm("Deploy " + pkg + " via profile " + prof + "?\\n\\nThis uploads the package to the live host now.")) return;
  setStatus(execute ? "Deploying…" : "Dry run…");
  document.getElementById("deploy-btn").disabled = execute;
  document.getElementById("dry-btn").disabled = !execute;
  try {
    const data = await api("/api/deploy", {method:"POST", body: JSON.stringify({
      package: pkg, profile: prof, execute: execute, confirm: execute,
      allow_plain_ftp: document.getElementById("plain-ftp").checked
    })});
    document.getElementById("receipt").textContent = JSON.stringify(data.receipt, null, 2);
    await loadState(false);
    const mode = (data.receipt || {}).mode;
    setStatus(mode === "execute" ? "Deploy executed — receipt written locally." : "Dry-run receipt — review, then Deploy.", mode === "execute" ? "" : "warn");
  } catch (e) { setStatus("Deploy failed: " + e.message, "err"); }
  finally { document.getElementById("deploy-btn").disabled = false; document.getElementById("dry-btn").disabled = false; }
}

document.addEventListener("DOMContentLoaded", function () {
  loadState(false);
  document.getElementById("build-btn").addEventListener("click", buildPackage);
  document.getElementById("refresh-btn").addEventListener("click", function () { loadState(false); });
  document.getElementById("dry-btn").addEventListener("click", function () { runDeploy(false); });
  document.getElementById("deploy-btn").addEventListener("click", function () { runDeploy(true); });
  document.getElementById("profile-select").addEventListener("change", renderProfileDetail);
});
</script>
</body>
</html>
"""


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except (DeployUIError, deploy_harpp.DeployError) as error:
        print(f"harpp-deploy-ui: {error}", file=sys.stderr)
        raise SystemExit(2)
