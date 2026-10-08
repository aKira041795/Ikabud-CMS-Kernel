#!/usr/bin/env bash
#
# POST-DEPLOY 5.7 CHECK — run this ON the Bluehost host immediately after deploying
# and running the tenant migration. It answers the two questions that cannot be
# answered locally.
#
# WHY THIS EXISTS, AND WHAT IS ALREADY KNOWN
#
#   The local development server is MySQL 8.0.46. Production is MySQL 5.7. A local
#   pass therefore proves NOTHING about 5.7 — that asymmetry is the whole reason this
#   script exists.
#
#   What WAS verified locally, statically (so it is not re-checked here):
#
#     * migration 087 adds one plain column:
#           ALTER TABLE dl_consignees ADD COLUMN sort_order INT NOT NULL DEFAULT 0
#       guarded by information_schema, mirroring 065_add_branch_sort_order.sql — the
#       same prepared-statement pattern already running in production. No generated
#       column, no foreign key, no ENGINE or charset change.
#
#     * dl_entityOrderBySql() emits:
#           (sort_order = 0) ASC, sort_order ASC, name ASC
#       A boolean expression in ORDER BY. This is the ONE construct that could have
#       interacted badly with 5.7: under ONLY_FULL_GROUP_BY an ORDER BY expression
#       must be legal for a grouped query. All 8 call sites were inspected and every
#       one is a simple SELECT with NO GROUP BY, so the case cannot arise.
#
#     * The repository's own pre-deployment audit: 0 window functions, 0 CTEs,
#       0 JSON_TABLE / EXCEPT / INTERSECT / CHECK constraints in the changed files.
#
#   So what is LEFT is exactly two things: does the guarded ALTER actually execute on
#   5.7, and does the ordering clause behave. Both are one query each, below.
#
# USAGE (on the server, from the app root):
#   bash tools/post-deploy-consignee-order-check.sh [tenant_id] [db_name]
#
# Exit 0 = both checks pass.  Non-zero = something needs attention, printed.

set -u

TENANT="${1:-}"
DB="${2:-}"

pass() { printf '  PASS  %s\n' "$*"; }
fail() { printf '  FAIL  %s\n' "$*"; FAILED=$((FAILED + 1)); }
note() { printf '        %s\n' "$*"; }

FAILED=0

echo "== post-deploy check: consignee Order on MySQL 5.7 =="
echo

# ---------------------------------------------------------------------------------------------
# 0. Report the server, so the result is self-describing in a ticket or a chat.
# ---------------------------------------------------------------------------------------------
if command -v mysql >/dev/null 2>&1; then
  # Credentials from .env, so the version probe connects the same way the app does.
  _v=$(grep -E '^DB_USERNAME=' .env 2>/dev/null | cut -d= -f2- | tr -d '"' )
  _p=$(grep -E '^DB_PASSWORD=' .env 2>/dev/null | cut -d= -f2- | tr -d '"' )
  if [ -n "$_v" ]; then
    ver=$(MYSQL_PWD="${_p:-}" mysql -u "$_v" -N -e "SELECT VERSION();" 2>/dev/null || echo "unavailable")
  else
    ver=$(MYSQL_PWD="${_p:-}" mysql -N -e "SELECT VERSION();" 2>/dev/null || echo "unavailable")
  fi
  echo "  server: $ver"
  case "$ver" in
    5.7*|5.6*|10.*) echo "  -> Compatibility profile (5.7-class). These checks are the real thing." ;;
    8.*) echo "  -> NOTE: this looks like MySQL 8, NOT the 5.7 Compatibility target." ;;
  esac
  echo
fi

if [ -z "$DB" ]; then
  echo "  Usage: bash tools/post-deploy-consignee-order-check.sh <tenant_id> <db_name>"
  echo "  (pass them explicitly: the tenant DB is not the base app DB)"
  echo
  echo "POST-DEPLOY CHECK: INCOMPLETE (no db name supplied)"
  exit 2
fi

# Credentials come from .env, the same way the application gets them. A bare `mysql -e`
# would try the invoking OS user and fail with 1045 — and, worse, that ERROR TEXT then looks
# like output to a `[ -n "$var" ]` test, which is how a check reports PASS while connecting
# to nothing. Every query below therefore goes through Q(), which refuses error output.
MYSQL_USER_OPT=()
if [ -f .env ]; then
  DBPASS=$(grep -E '^DB_PASSWORD=' .env | cut -d= -f2- | tr -d '"' )
  DBUSER=$(grep -E '^DB_USERNAME=' .env | cut -d= -f2- | tr -d '"' )
  [ -n "${DBPASS:-}" ] && export MYSQL_PWD="$DBPASS"
  [ -n "${DBUSER:-}" ] && MYSQL_USER_OPT=(-u "$DBUSER")
fi

# Q() runs a statement and FAILS LOUDLY on error instead of returning the error as data.
Q() {
  local out
  out=$(mysql "${MYSQL_USER_OPT[@]}" -N -e "$1" "$DB" 2>&1)
  case "$out" in
    *"ERROR "*) printf 'SQLERR: %s' "$out"; return 1 ;;
  esac
  printf '%s' "$out"
}

MYSQL_OK=1
if ! Q "SELECT 1;" >/dev/null 2>&1; then
  MYSQL_OK=0
  fail "cannot query database '${DB}' — credentials or connectivity"
  note "The script reads DB_USERNAME / DB_PASSWORD from .env; run it from the app root."
  note "$(Q "SELECT 1;" 2>&1 | head -1)"
  echo
  echo "POST-DEPLOY CHECK: INCOMPLETE (no database connection)"
  exit 2
fi

# ---------------------------------------------------------------------------------------------
# 1. Did the guarded ALTER execute on 5.7?
#
#    This is the check that matters most. If 087 did not run, the app's consignee queries
#    reference a column that does not exist and fail with 1054 Unknown column — on the
#    consignee list and on the Daily Sheet columns.
# ---------------------------------------------------------------------------------------------
col=$(Q "SHOW COLUMNS FROM dl_consignees LIKE 'sort_order';")
# An SQL error must never be mistaken for a column definition: that is precisely how a check
# reports PASS while the column is absent.
if printf '%s' "$col" | grep -q "^SQLERR:"; then
  fail "could not read the column: $col"
  col=""
elif [ -n "$col" ]; then
  if printf '%s' "$col" | grep -qE '^sort_order[[:space:]]+int[[:space:]]+NO[[:space:]]+0'; then
    pass "dl_consignees.sort_order exists, NOT NULL, default 0 -> $col"
  else
    fail "dl_consignees.sort_order exists but is not 'int NOT NULL DEFAULT 0' -> $col"
  fi
else
  fail "dl_consignees.sort_order is MISSING"
  note "The migration did not run. Two deploy paths apply it, so use whichever this host uses:"
  note "  * upgrade kit: db/tenant-upgrade.sql (the guarded ALTER is already generated into it)"
  note "  * CLI:         php ikabud tenant:migrate ${TENANT:-<tenant_id>} daily-ledger"
  note "The ALTER is guarded, so applying it twice is safe."
  note "Until it runs, consignee listings and the Daily Sheet columns will fail with"
  note "1054 Unknown column 'sort_order'."
fi

# Is it recorded as applied? A guarded migration that ran but was not recorded will re-run
# harmlessly, but an UNRECORDED migration is the classic cause of a partial deploy.
if [ -n "$TENANT" ]; then
  rec=$(mysql -N -e "SELECT COUNT(*) FROM _migrations WHERE migration LIKE '%087%';" "$DB" 2>/dev/null || echo "")
  if [ "$rec" = "1" ]; then
    pass "migration 087 is recorded as applied in _migrations"
  elif [ -z "$rec" ]; then
    note "could not read _migrations (table name or permissions differ) - not a failure"
  else
    fail "087 appears $rec times in _migrations"
    note "A duplicate means the file ran twice; the guarded ALTER is idempotent so this is"
    note "harmless, but the duplicate number should be cleaned up."
  fi
fi

# ---------------------------------------------------------------------------------------------
# 2. Does the ordering clause actually behave on this server?
#
#    The rule: numbered first ASCENDING, zeros LAST, so a new row at 0 never jumps ahead.
#    Exercised on real rows inside a transaction, then rolled back.
# ---------------------------------------------------------------------------------------------
echo
before=$(Q "SELECT COUNT(*) FROM dl_consignees;")
if printf '%s' "$before" | grep -qE '^[0-9]+$'; then
  # assigned_commissary_id is NOT NULL with a foreign key, so the probe cannot invent one.
  # Derive it from live data: an existing consignee's commissary, else any branch. Clean up any
  # ZZPD row first, inside the transaction, so a leaked row from an earlier aborted run cannot
  # collide on the unique code.
  out=$(mysql "${MYSQL_USER_OPT[@]}" -N -e "
    START TRANSACTION;
    DELETE FROM dl_consignees WHERE code LIKE 'ZZPD%';
    SET @cid := COALESCE(
        (SELECT assigned_commissary_id FROM dl_consignees WHERE assigned_commissary_id IS NOT NULL LIMIT 1),
        (SELECT MIN(id) FROM dl_branches)
    );
    INSERT INTO dl_consignees (code,name,area,address,assigned_commissary_id,price_group_id,is_active,sort_order)
    SELECT * FROM (
      SELECT 'ZZPD5' AS code,'ZZ PD5' AS name,'X' AS area,'X' AS address,@cid AS cid,NULL AS pg,1 AS act,5 AS so UNION ALL
      SELECT 'ZZPD2','ZZ PD2','X','X',@cid,NULL,1,2 UNION ALL
      SELECT 'ZZPD0','ZZ PD0','X','X',@cid,NULL,1,0
    ) AS probe_rows
    WHERE @cid IS NOT NULL;
    SELECT GROUP_CONCAT(code ORDER BY (sort_order = 0) ASC, sort_order ASC, name ASC) FROM dl_consignees WHERE code LIKE 'ZZPD%';
    ROLLBACK;
  " "$DB" 2>&1)
  after=$(Q "SELECT COUNT(*) FROM dl_consignees;")

  seq=$(printf '%s' "$out" | tail -1)
  case "$seq" in
    "ZZPD2,ZZPD5,ZZPD0") pass "ordering rule holds on this server: $seq (number 2, then 5, then 0 last)" ;;
    "") fail "the ordering probe returned nothing" ;;
    *) fail "unexpected order: $seq"
       note "Expected ZZPD2,ZZPD5,ZZPD0 - numbered ascending, zero last." ;;
  esac

  if [ "$before" = "$after" ]; then
    pass "rollback clean (consignee count unchanged at $before)"
  else
    fail "probe rows leaked: $before -> $after"
    note "Remove them: DELETE FROM dl_consignees WHERE code LIKE 'ZZPD%';"
  fi
else
  note "could not count dl_consignees; skipping the ordering probe"
fi

# ---------------------------------------------------------------------------------------------
# 3. The clause must be legal under this server's sql_mode (ONLY_FULL_GROUP_BY).
# ---------------------------------------------------------------------------------------------
echo
mode=$(Q "SELECT @@sql_mode;")
printf '%s' "$mode" | grep -q "ONLY_FULL_GROUP_BY" \
  && echo "  sql_mode includes ONLY_FULL_GROUP_BY (the clause is used on non-aggregate SELECTs only)" \
  || echo "  sql_mode does not include ONLY_FULL_GROUP_BY"

echo
if [ "$FAILED" -eq 0 ]; then
  echo "POST-DEPLOY CHECK: PASS"
  exit 0
fi
echo "POST-DEPLOY CHECK: FAIL (${FAILED} check(s))"
exit 1
