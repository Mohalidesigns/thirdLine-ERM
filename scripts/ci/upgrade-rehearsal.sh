#!/usr/bin/env bash
# Upgrade-path rehearsal: install the BASE release on an empty database, seed
# it the way a production server was seeded, then upgrade that populated
# database to the HEAD release by running scripts/deploy.sh's database and
# application steps in deploy.sh's order (which steps, and which are NOT
# rehearsed, is listed below).
#
#   scripts/ci/upgrade-rehearsal.sh <base-checkout> <head-checkout>
#
# WHY THIS EXISTS. On 2026-10-04 the PR #11 production upgrade stopped at the
# 25th of 124 migrations on a VPS rehearsal: 2026_08_12_120003 saved a Period
# for every EXISTING organisation, WebhookEventObserver fired on the save and
# queried webhook_subscriptions, and that table is created 21 migrations later.
# The suite never saw it, because the suite only ever migrates an EMPTY
# database — no organisation, so no backfill saves a model, so no observer
# fires. Fixed in 02cf6df (MigrationWindow) and guarded statically in 831f65f
# (DEVELOPMENT_STANDARD §16). This script is the runtime catch for the class:
# anything that only breaks when a migration meets rows a real installation
# already holds.
#
# What it does, in order, and what fails it:
#   1. Lists the migrations the upgrade will apply (information only), then
#      decides whether there is anything to rehearse. It skips ONLY when every
#      path that differs between base and head is documentation — docs/**,
#      plans/** or *.md. Anything else is rehearsed in full, even with no new
#      migration: deploy.sh runs the permission seeder, the caches and
#      app:preflight on EVERY deploy, so a change to a seeder, an observer,
#      config, routes or Preflight itself can break a deploy that migrates
#      nothing. If the changed paths cannot be listed, it rehearses.
#   2. BASE: composer install, `migrate --force`, `db:seed --force` with the
#      base's own DatabaseSeeder (the seeder a production server was seeded
#      with), then insists at least one organisation exists.
#   3. HEAD: composer install, `migrate --force` against that same database.
#      Any migration error fails the run, and so does anything still pending
#      afterwards.
#   4. HEAD: the rest of deploy.sh's sequence — the permission-catalogue sync,
#      config/route/view caches — and then `app:preflight`, which fails the run
#      on a non-zero exit OR on any FAIL row.
#
# deploy.sh steps this does NOT rehearse: `git fetch`/`git reset --hard
# origin/main` (CI checks the two releases out instead), the front-end build
# (`npm ci`, `npm run build`), the runtime-permissions step
# (ensure_runtime_permissions on storage/ and bootstrap/cache), and the
# service restarts (php8.4-fpm, risk-queue). It also starts from an EMPTY
# bootstrap/cache, where a real server migrates with the previous release's
# config/route caches still on disk.
#
# The database must exist and be EMPTY. This script never drops anything: it
# refuses a database with tables in it rather than guess whose they are.
#
# Required environment: DB_HOST DB_PORT DB_DATABASE DB_USERNAME DB_PASSWORD.
# Optional:
#   REHEARSAL_SEEDER        base seeder class (default: DatabaseSeeder)
#   REHEARSAL_SKIP_COMPOSER 1 = do not run composer install (vendor/ is ready)
#
# Configuration is passed as process environment, which Laravel's dotenv
# loader does not override, so a stray .env in either checkout cannot change
# what is rehearsed. Both checkouts share ONE APP_KEY: rows the base encrypts
# have to decrypt under the head, exactly as on a real server.
set -euo pipefail

# The whole script is one compound command, so bash parses all of it before
# running any of it: editing this file while a rehearsal is running (it takes
# minutes) cannot splice half of the old text onto half of the new.
{
if [[ $# -ne 2 ]]; then
    echo "usage: $0 <base-checkout> <head-checkout>" >&2
    exit 2
fi

BASE_DIR="$(cd "$1" && pwd)"
HEAD_DIR="$(cd "$2" && pwd)"
SEEDER="${REHEARSAL_SEEDER:-DatabaseSeeder}"

: "${DB_HOST:?DB_HOST is required}"
: "${DB_PORT:?DB_PORT is required}"
: "${DB_DATABASE:?DB_DATABASE is required}"
: "${DB_USERNAME:?DB_USERNAME is required}"
export DB_PASSWORD="${DB_PASSWORD-}"

for dir in "$BASE_DIR" "$HEAD_DIR"; do
    [[ -f "$dir/artisan" ]] || { echo "!! $dir is not an application checkout (no artisan)" >&2; exit 2; }
done
[[ "$BASE_DIR" != "$HEAD_DIR" ]] || { echo "!! base and head are the same directory" >&2; exit 2; }

# ---------------------------------------------------------------------------
# Output helpers. `::group::`/`::error::` are GitHub's workflow commands; a
# terminal just shows them as plain lines.
# ---------------------------------------------------------------------------
in_ci() { [[ "${GITHUB_ACTIONS:-}" == "true" ]]; }
group() { if in_ci; then echo "::group::$*"; else echo; echo "==> $*"; fi; }
endgroup() { if in_ci; then echo "::endgroup::"; fi; }
fail() {
    if in_ci; then echo "::error title=Upgrade rehearsal::$*"; else echo "!!  $*"; fi
    summary "**FAILED:** $*"
    exit 1
}
summary() { if [[ -n "${GITHUB_STEP_SUMMARY:-}" ]]; then echo "$*" >>"$GITHUB_STEP_SUMMARY"; fi; }

# The newest application log in a checkout, so a failure prints the exception
# rather than just artisan's one-line message.
show_log() {
    local log="$1/storage/logs/laravel.log"
    if [[ -s "$log" ]]; then
        echo "---- last 80 lines of ${log#"$1"/} ($(basename "$1")) ----"
        tail -n 80 "$log"
        echo "----"
    fi
}

# One scalar from the rehearsal database, through PHP so no mysql client is
# needed. Prints the value, or nothing if the query fails (e.g. no table).
sql_scalar() {
    # shellcheck disable=SC2016 # PHP variables, deliberately unexpanded
    php -r '
        try {
            $p = new PDO(sprintf("mysql:host=%s;port=%s;dbname=%s", getenv("DB_HOST"), getenv("DB_PORT"), getenv("DB_DATABASE")),
                getenv("DB_USERNAME"), (string) getenv("DB_PASSWORD"), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            echo $p->query($argv[1])->fetchColumn();
        } catch (Throwable $e) { exit(0); }
    ' "$1"
}

PHASE_START=$SECONDS
# Prints "<what> in <n>s" for the time since the previous call. Called
# directly, never inside $(...): a subshell could not move PHASE_START on.
phase_done() { echo "$1 in $((SECONDS - PHASE_START))s"; PHASE_START=$SECONDS; }

base_sha="$(git -C "$BASE_DIR" rev-parse --short HEAD 2>/dev/null || echo unknown)"
head_sha="$(git -C "$HEAD_DIR" rev-parse --short HEAD 2>/dev/null || echo unknown)"
echo "Upgrade rehearsal: base ${base_sha} (${BASE_DIR}) -> head ${head_sha} (${HEAD_DIR})"
summary "### Upgrade rehearsal: \`${base_sha}\` → \`${head_sha}\`"

# ---------------------------------------------------------------------------
# 1. What would an upgraded server actually run?
#
# Migration NAMES decide it: the migrator compares file names with the
# `migrations` table and never looks at content. So an edited migration that
# already ran is NOT re-run on an upgraded server — it is reported here,
# because the edit reaches fresh installs only, and that is worth a look.
# ---------------------------------------------------------------------------
group "Migrations the upgrade will apply"
# Every directory the migrator reads: the application's own and each local
# package's (packages/platform registers its licence tables through
# loadMigrationsFrom). A vendor package's migrations are not listed here; a
# changed composer.lock is a non-docs change, so it is always rehearsed in
# full. Keyed by NAME, as the migrator keys them; paths kept to diff content.
list_migration_paths() {
    local roots=() r
    for r in database/migrations packages; do [[ -d "$1/$r" ]] && roots+=("./$r"); done
    [[ ${#roots[@]} -gt 0 ]] || return 0
    (cd "$1" && find "${roots[@]}" -type f -name '*.php' -path '*/database/migrations/*' \
        -not -path '*/vendor/*' -not -path '*/node_modules/*' | sed 's|^\./||' | LC_ALL=C sort)
}
names_of() { sed 's|.*/||; s|\.php$||' "$1" | LC_ALL=C sort -u; }
path_of() { grep -m1 "/$2\.php\$" "$1"; }
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT
list_migration_paths "$BASE_DIR" >"$tmp/base-paths"
list_migration_paths "$HEAD_DIR" >"$tmp/head-paths"
names_of "$tmp/base-paths" >"$tmp/base"
names_of "$tmp/head-paths" >"$tmp/head"
LC_ALL=C comm -13 "$tmp/base" "$tmp/head" >"$tmp/pending"
LC_ALL=C comm -23 "$tmp/base" "$tmp/head" >"$tmp/removed"
: >"$tmp/edited"
while IFS= read -r m; do
    bp="$(path_of "$tmp/base-paths" "$m")"
    hp="$(path_of "$tmp/head-paths" "$m")"
    cmp -s "$BASE_DIR/$bp" "$HEAD_DIR/$hp" || echo "$hp" >>"$tmp/edited"
done < <(LC_ALL=C comm -12 "$tmp/base" "$tmp/head")

pending_count=$(wc -l <"$tmp/pending" | tr -d ' ')
echo "base: $(wc -l <"$tmp/base" | tr -d ' ') migrations; head: $(wc -l <"$tmp/head" | tr -d ' '); new at head: ${pending_count}"
sed 's/^/  + /' "$tmp/pending"
if [[ -s "$tmp/removed" ]]; then
    echo "WARNING: migrations present at base and DELETED at head (an upgraded server keeps what they did):"
    sed 's/^/  - /' "$tmp/removed"
fi
if [[ -s "$tmp/edited" ]]; then
    echo "NOTE: migrations EDITED since base. An upgraded server has already run them and will NOT run them again;"
    echo "      only a fresh install sees the edit:"
    sed 's/^/  ~ /' "$tmp/edited"
fi
endgroup

locks_differ=0
cmp -s "$BASE_DIR/composer.lock" "$HEAD_DIR/composer.lock" || locks_differ=1

# Every path that differs between the base commit and head's WORKING TREE
# (tracked changes, then untracked files), renames split into both sides so a
# file moved out of docs/ cannot hide behind its new name. Prints nothing and
# fails if git cannot answer, in which case the caller rehearses.
changed_paths() {
    local base_full
    base_full="$(git -C "$BASE_DIR" rev-parse HEAD 2>/dev/null)" || return 1
    git -C "$HEAD_DIR" diff --no-renames --name-only "$base_full" -- || return 1
    git -C "$HEAD_DIR" ls-files --others --exclude-standard || return 1
}
is_documentation() { [[ "$1" == docs/* || "$1" == plans/* || "$1" == *.md ]]; }

group "Paths changed since base"
docs_only=0
if changed_paths >"$tmp/changed" 2>"$tmp/changed-err"; then
    docs_only=1
    while IFS= read -r p; do
        [[ -z "$p" ]] && continue
        if ! is_documentation "$p"; then
            docs_only=0
            echo "$p" >>"$tmp/code-changed"
        fi
    done <"$tmp/changed"
    echo "$(wc -l <"$tmp/changed" | tr -d ' ') path(s) changed; $( [[ -s "$tmp/code-changed" ]] && wc -l <"$tmp/code-changed" | tr -d ' ' || echo 0) outside docs/**, plans/** and *.md"
    if [[ -s "$tmp/code-changed" ]]; then
        LC_ALL=C sort "$tmp/code-changed" | sed -n '1,40p' | sed 's/^/  * /'
    fi
else
    echo "could not list the changed paths ($(tr '\n' ' ' <"$tmp/changed-err")); rehearsing in full"
fi
endgroup

if [[ "$docs_only" -eq 1 ]]; then
    echo "Nothing to rehearse: every path changed since base is documentation (docs/**, plans/**, *.md)."
    summary "Nothing to rehearse: documentation-only change."
    exit 0
fi

# ---------------------------------------------------------------------------
# 2. Environment: production's shape, minus anything that needs a service CI
#    does not have.
# ---------------------------------------------------------------------------
export DB_CONNECTION=mysql
export APP_ENV=production
export APP_DEBUG=false
export APP_URL="https://rehearsal.invalid"
APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')"
export APP_KEY
export SESSION_SECURE_COOKIE=true
# Production's module flags: RCSA v2 and TPRM on, BCMS off (preflight refuses
# BCMS on until Phase 7 is certified).
export FEATURE_RCSA_V2=true
export FEATURE_TPRM=true
export FEATURE_BCMS=false
# No Redis in CI. `database` queues a job the way production does — written,
# not run — so a dispatch during a migration is recorded rather than executed
# inline (sync) or lost.
export QUEUE_CONNECTION=database
export CACHE_STORE=database
export SESSION_DRIVER=database
export MAIL_MAILER=log
export BROADCAST_CONNECTION=log
export LOG_CHANNEL=single
export LLM_ENABLED=false

group "Database"
tables="$(sql_scalar "select count(*) from information_schema.tables where table_schema = database()")"
[[ -n "$tables" ]] || fail "cannot connect to ${DB_DATABASE} on ${DB_HOST}:${DB_PORT}"
[[ "$tables" -eq 0 ]] || fail "${DB_DATABASE} already holds ${tables} table(s); the rehearsal needs an empty database and never drops one"
echo "server: $(sql_scalar "select version()"); database ${DB_DATABASE} is empty"
endgroup

prepare_checkout() {
    local dir="$1" label="$2"
    group "${label}: prepare checkout"
    [[ -f "$dir/.env" ]] || cp "$dir/.env.example" "$dir/.env"
    # A cached config would win over the environment above.
    rm -f "$dir"/bootstrap/cache/config.php "$dir"/bootstrap/cache/routes-*.php
    if [[ "${REHEARSAL_SKIP_COMPOSER:-0}" != "1" ]]; then
        # Same lock as head: start from head's vendor/ instead of downloading
        # it again. composer install then only verifies it and dumps the
        # autoloader for this checkout's own classes.
        if [[ "$dir" == "$BASE_DIR" && ! -d "$dir/vendor" && "$locks_differ" -eq 0 && -d "$HEAD_DIR/vendor" ]]; then
            cp -R "$HEAD_DIR/vendor" "$dir/vendor"
        fi
        # Head installs the way deploy.sh does (--no-dev), so a production
        # class that leans on a require-dev package fails here, not on the
        # server. Base keeps dev packages: its DatabaseSeeder needs them.
        local -a flags=(--prefer-dist --no-interaction --no-progress)
        [[ "$dir" == "$HEAD_DIR" ]] && flags+=(--no-dev --optimize-autoloader)
        (cd "$dir" && composer install "${flags[@]}") || fail "composer install failed in the ${label} checkout"
    fi
    endgroup
    phase_done "${label}: prepared"
}

# ---------------------------------------------------------------------------
# 3. BASE: install and seed.
# ---------------------------------------------------------------------------
prepare_checkout "$BASE_DIR" "base"

group "base ${base_sha}: migrate"
(cd "$BASE_DIR" && php artisan migrate --force --no-interaction) || { show_log "$BASE_DIR"; fail "the BASE release (${base_sha}) does not migrate an empty database; the rehearsal cannot start"; }
endgroup
phase_done "base: migrated"

group "base ${base_sha}: seed (${SEEDER})"
(cd "$BASE_DIR" && php artisan db:seed --class="$SEEDER" --force --no-interaction) || { show_log "$BASE_DIR"; fail "the BASE release (${base_sha}) does not seed (${SEEDER}); the rehearsal cannot start"; }
endgroup
phase_done "base: seeded"

orgs="$(sql_scalar "select count(*) from organizations")"
users="$(sql_scalar "select count(*) from users")"
base_migrations="$(sql_scalar "select count(*) from migrations")"
jobs_before="$(sql_scalar "select count(*) from jobs")"
echo "base data: ${orgs:-?} organisation(s), ${users:-?} user(s), ${base_migrations:-?} migrations recorded"
[[ "${orgs:-0}" -ge 1 ]] || fail "the base seed produced no organisation, so no backfill would meet existing rows — rehearsing that proves nothing"
summary "- base: ${base_migrations} migrations, seeded with \`${SEEDER}\`: ${orgs} organisation(s), ${users} user(s)"

# ---------------------------------------------------------------------------
# 4. HEAD: upgrade the populated database the way deploy.sh does.
# ---------------------------------------------------------------------------
prepare_checkout "$HEAD_DIR" "head"
trap 'rm -rf "$tmp"; (cd "$HEAD_DIR" && rm -f bootstrap/cache/config.php bootstrap/cache/routes-*.php && php artisan view:clear >/dev/null 2>&1 || true)' EXIT

group "head ${head_sha}: pending before upgrade"
# Information only: the upgrade below is what passes or fails. But a count
# read from a command that failed would be a made-up number, so say so.
pre_status_rc=0
(cd "$HEAD_DIR" && php artisan migrate:status --pending --no-interaction) | tee "$tmp/status" || pre_status_rc=$?
endgroup
if [[ "$pre_status_rc" -eq 0 ]]; then
    echo "head: $(grep -c 'Pending' "$tmp/status" || true) migration(s) pending against the seeded base"
else
    echo "head: migrate:status exited ${pre_status_rc} before the upgrade, so the pending count is unknown; continuing to migrate"
fi

group "head ${head_sha}: migrate --force (deploy.sh step)"
if ! (cd "$HEAD_DIR" && php artisan migrate --force --no-interaction); then
    endgroup
    show_log "$HEAD_DIR"
    fail "upgrading a populated ${base_sha} database to ${head_sha} failed in a migration (see the output above). A fresh-database suite cannot see this; DEVELOPMENT_STANDARD §16."
fi
endgroup
phase_done "head: migrated"

status_rc=0
(cd "$HEAD_DIR" && php artisan migrate:status --pending --no-interaction) >"$tmp/status-after" 2>&1 || status_rc=$?
if [[ "$status_rc" -ne 0 ]]; then
    cat "$tmp/status-after"
    fail "migrate:status failed after the upgrade (exit ${status_rc})"
fi
if grep -q 'Pending' "$tmp/status-after"; then
    cat "$tmp/status-after"
    fail "migrations are still pending after migrate --force"
fi
jobs_after="$(sql_scalar "select count(*) from jobs")"
echo "head: jobs queued by the upgrade: $(( ${jobs_after:-0} - ${jobs_before:-0} ))"

group "head ${head_sha}: permission catalogue sync, caches (deploy.sh steps)"
# Chained with && on purpose: on the left of `||`, bash switches errexit off
# inside the subshell, so separate lines would let a failed seed through.
(
    cd "$HEAD_DIR" &&
        php artisan db:seed --class=RolesAndPermissionsSeeder --force --no-interaction &&
        php artisan config:cache &&
        php artisan route:cache &&
        php artisan view:cache
) || { endgroup; show_log "$HEAD_DIR"; fail "a post-migrate deploy step failed (permission sync or config/route/view cache)"; }
endgroup

group "head ${head_sha}: app:preflight"
preflight_status=0
(cd "$HEAD_DIR" && php artisan app:preflight --no-ansi --no-interaction) | tee "$tmp/preflight" || preflight_status=$?
endgroup
# Belt and braces: the exit status AND the table. A FAIL row is a FAIL even if
# a future edit to handle() forgets to turn it into a non-zero exit.
if [[ "$preflight_status" -ne 0 ]] || grep -Eq '\|[[:space:]]*FAIL[[:space:]]*\|' "$tmp/preflight"; then
    grep -E '\|[[:space:]]*FAIL[[:space:]]*\|' "$tmp/preflight" || true
    fail "app:preflight failed on the upgraded database (exit ${preflight_status})"
fi
phase_done "head: deploy steps and preflight"

applied=$(( $(sql_scalar "select count(*) from migrations") - base_migrations ))
echo
echo "Upgrade rehearsal PASSED: ${applied} migration(s) applied to a seeded ${base_sha} database, preflight clean. Total ${SECONDS}s."
summary "- head: ${applied} migration(s) applied to the populated database; permission sync, caches and \`app:preflight\` clean (${SECONDS}s)"
exit 0
}
