#!/usr/bin/env bash
#
# Acceptance probe: EVERY tenant host's /login must END at a sign-in page, without looping.
#
# This asserts OUTCOMES over real HTTP, not a rewrite mechanism: an operator typing /login must get a
# login form. It caught what a rewrite-level assertion missed - see below.
#
# MEASURED 2026-10-07, after the first fix attempt (which its own lane reported PASS on):
#   PASS  baronledger.test -> "Daily Ledger Sign In"          (rewrites straight to /daily-ledger/login)
#   PASS  zapattendance.test -> "KablaM! - Login"
#   PASS  palsystem.test -> "Project Ledger - Sign In"
#   PASS  dccafe.test -> "DC Cafe POS - Sign In"
#   PASS  ehr.test / moto.test / akiracms.test -> their own sign-in pages
#   PASS  wms.test, harpp.test -> 302 to /<module>/login, which is itself a sign-in page
#   FAIL  juliesbakeshop.test -> INFINITE LOOP: /login -> /bakeshop (module ROOT) -> 302 back to
#         /login -> /bakeshop -> ... while /bakeshop/login serves "Bakeshop Sign In" perfectly.
#   FAIL  cmsnew.test, aiss.test -> the tenant's PUBLIC frontpage/blog instead of /cms/login
#
# ROOT CAUSE of the three failures: for an explicit /login the resolve chain prefers the module ROOT
# over the module LOGIN route, because the "/" landing call and the "/login" call pass the same
# $authPath and are therefore indistinguishable inside the elseif chain.
#
# The requirement: an explicit /login (and /forgot-password) request on an auth-owned tenant must
# resolve to that module's LOGIN route. The "/" landing keeps its existing root preference.
#
# guidancemonitoring.test is excluded: it 301s to https, which cannot be followed over http here.

cd /var/www/html/applicationostest || exit 1

HOSTS="baronledger.test juliesbakeshop.test zapattendance.test wms.test harpp.test cmsnew.test aiss.test ehr.test moto.test akiracms.test palsystem.test dccafe.test"
FAILED=0

for h in $HOSTS; do
    body=$(mktemp)
    res=$(curl -s -m 15 -L --max-redirs 5 -o "$body" -w "%{http_code}|%{num_redirects}" -H "Host: $h" http://127.0.0.1/login 2>/dev/null)
    code=${res%%|*}
    redirs=${res##*|}
    title=$(grep -oE "<title>[^<]*</title>" "$body" 2>/dev/null | head -1 | sed 's/<[^>]*>//g')
    rm -f "$body"

    if [ "$code" = "200" ] && printf '%s' "$title" | grep -qiE "sign ?in|login|log ?in"; then
        printf '  PASS  %-26s -> %s\n' "$h" "$title"
    else
        printf '  FAIL  %-26s -> code=%s redirects=%s title=%s\n' "$h" "$code" "$redirs" "$title"
        FAILED=1
    fi

    # A loop shows up as curl exhausting max-redirs and never reaching a 200.
    if [ "$redirs" -ge 5 ]; then
        printf '        ^ redirect loop detected (>=5 hops)\n'
        FAILED=1
    fi
done

if [ "$FAILED" -ne 0 ]; then
    echo "FAIL: at least one tenant's /login does not reach a sign-in page" >&2
    exit 1
fi

echo "PASS: every tenant host's /login reaches a sign-in page with no loop"
exit 0
