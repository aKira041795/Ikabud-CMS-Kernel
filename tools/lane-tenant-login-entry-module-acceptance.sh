#!/usr/bin/env bash
#
# Acceptance probe for lane tenant-login-entry-module-routing (contract A1).
#
# PASSES only once /login on a tenant host whose entry module owns auth resolves to that module's
# login surface instead of the kernel sign-in form.
#
# Measured on the UNCHANGED tree 2026-10-07: /login -> /login, exits 1. The gate discriminates.
# Base behaviour for reference (same tree): / -> /daily-ledger/login, /dashboard -> /daily-ledger/dashboard,
# /api/v1/health -> unchanged.

cd /var/www/html/applicationostest || exit 1

php -r '
require "bootstrap.php";
$_SERVER["HTTP_HOST"] = "baronledger.test";
$router = new \Ikabud\Kernel\Http\TenantEntryRouter();
$out = $router->rewriteUri("/login");
if ($out === "/login") {
    fwrite(STDERR, "FAIL: /login still resolves to the kernel login - a form that cannot authenticate on this tenant\n");
    exit(1);
}
if (strpos($out, "daily-ledger") === false) {
    fwrite(STDERR, "FAIL: /login -> {$out}; expected a daily-ledger login surface\n");
    exit(1);
}
echo "PASS: /login -> {$out}\n";

// Mirror case that must NOT regress: an unresolved host keeps the kernel login.
$_SERVER["HTTP_HOST"] = "unknown-host.invalid";
$out2 = $router->rewriteUri("/login");
if ($out2 !== "/login") {
    fwrite(STDERR, "FAIL: unresolved host rewrote /login to {$out2}; the kernel login must stay reachable there\n");
    exit(1);
}
echo "PASS: unresolved host keeps /login -> {$out2}\n";
exit(0);
'
