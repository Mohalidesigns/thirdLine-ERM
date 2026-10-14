# Deployment

There is **one** deployment procedure: GitHub Actions over SSH to a systemd
host, running `scripts/deploy.sh` on the server.

Until migration Phase 7.3 there were two. `build-deploy.sh` packaged a cPanel
zip and nothing invoked it — no workflow, no documentation, only its own usage
comment — while `.github/workflows/deploy.yml` had been calling
`scripts/deploy.sh` all along. Two divergent procedures where only one runs is
worse than one: the unused one drifts, and it is the one somebody reads when
the real deploy breaks at 3am. It has been deleted; this file replaces it.

## Topology

| | |
|---|---|
| Application root | `/var/www/thirdLine-ERM` |
| Runs as | `deploy`, unprivileged, owning files `deploy:www-data` with setgid dirs |
| Web/PHP | `php8.4-fpm` |
| Queue | `risk-queue` (systemd unit) |
| Restarts | scoped passwordless sudo, limited to those two units |
| Scheduler | `php artisan schedule:run` every minute, and it **must run as `www-data`**. Which user's crontab runs it on the VPS has not been recorded; confirm it with the step 0 check under "When the permissions step warns". Anything a scheduled command logs goes to `storage/logs/laravel.log`, and a log file another user creates is `0644` under the usual `umask 022`, which php-fpm cannot append to. None of the scheduled commands writes to the `local` disk. Two RCSA commands are scheduled, `rcsa:check-action-plans` and `rcsa:check-cycle-deadlines`, and neither writes a file. The RCSA commands that do write there, `rcsa:migrate-legacy` and `rcsa:parallel-run`, are run by hand, as `www-data` (`sudo -u www-data php artisan …`). |

`deploy` cannot become root. It can restart exactly the two services the
deploy needs, and nothing else.

## What a deploy does

`.github/workflows/deploy.yml` connects over SSH and runs
`bash /var/www/thirdLine-ERM/scripts/deploy.sh`, which is `set -euo pipefail`
throughout — any failing step stops the deploy rather than leaving a
half-migrated application serving traffic.

1. `git fetch --all --prune` and `git reset --hard origin/main`
2. `composer install --no-dev --optimize-autoloader`
3. `npm ci && npm run build`
4. `php artisan migrate --force`
5. `php artisan config:cache route:cache view:cache`
6. Re-apply runtime-writable permissions on `storage` and `bootstrap/cache` —
   the one step that is never fatal; see below
7. Restart `php8.4-fpm` and `risk-queue`

### Why the cache step matters more than it looks

`php artisan view:cache` calls `view:clear` first, and that is load-bearing
rather than incidental. Compiled Blade outlives the package that compiled it:
Livewire hooked the Blade compiler, so every template compiled while it was
installed carries `ExtendBlade::isRenderingLivewireComponent()` into the cached
PHP. After Phase 6.8 removed Livewire, a stale cache made the PDF templates
fail with a class-not-found error naming a file that contains no Livewire and
never did. The deploy is safe because it clears; a developer pulling across
that commit is not, and needs `php artisan view:clear` by hand.

`config:cache` freezes the resolved configuration and `env()` stops being
consulted at runtime. Build the cache **on the target host**: a cache built
somewhere with `APP_ENV=local` carries `session.secure => null` and
`session.encrypt => false` into production, and the application would work
perfectly while sending an unencrypted session cookie over plain HTTP.
`AppServiceProvider::assertSessionCookieIsHardened()` refuses to boot on that,
which is the only reason it cannot ship silently.

### When the permissions step warns

The step re-applies `2775`/`0664` under `storage/` and `bootstrap/cache`, and
`2770`/`0660` under `storage/app/private` — the `local` disk root, which holds
every module's evidence and the RCSA/TPRM import workbooks and must never be
world-readable. It chmods only what `deploy` owns, skips any directory `deploy`
cannot list and enter, and never fails the deploy. What it skipped is printed:

```
!!  WARNING: 'deploy' cannot list or enter these directories under storage/ or
!!  bootstrap/cache; they and everything below them were skipped:
!!    storage/app/private/rcsa
```

It was fatal until 2026-10-05. The day after RCSA v2 was enabled php-fpm, as
`www-data`, created `storage/app/private/rcsa` at Flysystem's private default of
`0700`; `find` could not list it and the deploy of PR #12 aborted before
preflight and the restart. `config/filesystems.php` now gives the `local` and
`public` disks an explicit `permissions` map (private `0770`/`0660`, public
`0775`/`0664`), so a directory the app creates is owner+group and nothing for
anyone else — `0750` under systemd's default `UMask=0022`, which `deploy` can
list through the `www-data` group.

#### The first deploy after this fix still runs the old step

The deploy that **ships** this fix does not run it. `git reset --hard` writes a
new `scripts/deploy.sh`, but bash keeps reading the copy it already opened, so
that one deploy runs the old, fatal step. Until the fix is live the app also
keeps creating owner-only (`0700`) directories, so any `www-data`-owned `0700`
directory created under `storage/` since the last root fix will abort it at
"Ensure runtime-writable permissions".

- **(a) Before the merge deploys,** run steps 0–4 of the root runbook below.
- **(b) If you do not,** expect that deploy to fail at the permissions step —
  migrations and the config/route/view caches done, preflight not run, services
  not restarted. Recover by re-running the deploy: re-run the failed workflow
  run, or start one with `workflow_dispatch`. The second run executes the new
  script, and every step before the permissions step is safe to repeat.
- **(c) Either way,** the old step runs one last time and reopens the
  `deploy`-owned files under `storage/app/private` to `2775`/`0664`. The next
  deploy closes them again; to close them at once, re-run step 3 of the
  runbook after that deploy.

The warning means a directory predates that config or was created some other
way. Fix it as root — group to `www-data`, group `rwX`, setgid on directories,
**nothing made world-readable**, and "other" removed under the private root:

```bash
APP=/var/www/thirdLine-ERM
cd /    # so `sudo -u deploy find` never starts in a directory deploy cannot enter

# 0. Preconditions. deploy must be in the runtime group, and php-fpm's pool,
#    the queue worker and the scheduler must run as www-data. If any is false,
#    stop and fix that first.
id -nG deploy | tr ' ' '\n' | grep -qx www-data && echo "deploy is in www-data" || echo "!! deploy is NOT in www-data"
#    Expect one `root root` line here — the php-fpm master process — and the
#    pool workers as www-data.
ps -o user=,group= -C php-fpm8.4 | sort | uniq -c
systemctl show -p User -p Group risk-queue
#    The scheduler: expect exactly one `schedule:run` entry, in www-data's
#    crontab or as a `www-data` line in /etc/crontab or /etc/cron.d, and
#    none for any other user. `sudo` matters: `crontab -l` for another user
#    fails without it, and the old check hid that failure behind 2>/dev/null
#    and printed nothing.
sudo crontab -u www-data -l | grep -F 'schedule:run' || echo "(none in www-data's crontab; it must then be a www-data line in /etc/crontab or /etc/cron.d below)"
for u in root deploy; do sudo crontab -u "$u" -l 2>/dev/null | grep -F 'schedule:run' && echo "!! the line above is in $u's crontab"; done
#    System crontabs name the user in the sixth field; it must be www-data.
sudo grep -H -F 'schedule:run' /etc/crontab /etc/cron.d/* 2>/dev/null || echo "(none in /etc/crontab or /etc/cron.d)"
systemctl list-timers --all | grep -i -E 'schedule|laravel' || true

# 1. What deploy cannot reach (the directories that abort or warn the deploy) ...
sudo -u deploy find "$APP/storage" "$APP/bootstrap/cache" -type d \( ! -readable -o ! -executable \) -prune -print
#    ... and everything not group-owned by www-data or lacking group access.
sudo find "$APP/storage" "$APP/bootstrap/cache" \( -type d -o -type f \) \( ! -group www-data -o \( -type d ! -perm -g=rwx \) -o \( -type f ! -perm -g=rw \) \) -printf '%M %u:%g %p\n'

# 2. Fix: group, then group rwX (never touches "other"), then setgid on directories.
sudo find "$APP/storage" "$APP/bootstrap/cache" \( -type d -o -type f \) ! -group www-data -exec chgrp www-data {} +
sudo find "$APP/storage" "$APP/bootstrap/cache" \( -type d -o -type f \) -exec chmod g+rwX {} +
sudo find "$APP/storage" "$APP/bootstrap/cache" -type d -exec chmod g+s {} +

# 3. Close the private root to "other" (evidence; nothing serves it directly).
sudo find "$APP/storage/app/private" \( -type d -o -type f \) -exec chmod o-rwx {} +

# 4. Verify: both print nothing. Symlinks are excluded from the second — they
#    are always lrwxrwxrwx and their mode means nothing.
sudo -u deploy find "$APP/storage" "$APP/bootstrap/cache" -type d \( ! -readable -o ! -executable \) -prune -print
sudo find "$APP/storage/app/private" ! -type l -perm /o=rwx -printf '%M %u:%g %p\n'
```

The OWNER is left alone — step 2 changes the group, never the owner: a
`www-data`-owned entry stays `www-data`-owned, which is why the deploy chmods
only its own. Symlinks are excluded throughout.

## Before serving traffic

**`scripts/deploy.sh` runs this automatically**, after migrations and the
config/route/view caches and *before* restarting php-fpm and the queue worker.
A non-zero exit aborts the deploy with the services left on the previous
release. Until 2026-09-10 this section asked a human to remember; every control
below was therefore worth exactly what remembering was worth.

To run it by hand — on a host you are investigating, or before a manual
release:

```bash
php artisan app:preflight
```

Non-zero exit means do not serve. It checks `APP_ENV`, `APP_DEBUG`, `APP_KEY`,
HTTPS, session cookie hardening, that no view loads an external CDN, that no
web installer or dev auto-login remains, that **every** web and api route
carries an authorization guard, and that the audit hash chain is present.

It also reports three things that are not about hardening:

- **Database engine** — the server's own `version()`, because nothing in a
  Laravel configuration distinguishes MariaDB from MySQL: `DB_CONNECTION=mysql`
  names the PDO driver and is correct for both, and hosting panels label both
  "MySQL". **Run this on the customer's host to settle which engine you are
  actually on.** A mismatch against what CI pins is a WARNING, not a failure —
  which engine an estate runs is not a preflight check's decision.
- **Modules enabled** — which feature-flagged modules this installation serves.
  Every flag in `config/features.php` defaults to FALSE, so an installation
  that never sets `FEATURE_TPRM` serves 404 on every TPRM route and the module
  reads as absent rather than switched off.
- **Uncertified modules** — FAILS the deploy when `FEATURE_BCMS` is on while
  `docs/bcms/phase-7-handoff.md` still records that Phase 7 has not passed its
  gates. **That document must exist on the target host** — the check fails if
  it is missing, because a gate that cannot confirm certification must refuse.

`schema:audit-deprecated` reports columns and tables scheduled for removal and
what still writes to them. It is informational and exits zero.

## Environment

`.env.example` is the reference and is kept complete for the product's own
keys — `FEATURE_*`, `MEASURE_*`, `CBN_RATE_*`, `WEBHOOKS_*`, `WORKFLOW_*`,
`CONNECTORS_*`, `SSO_*`, `LICENSE_*`, `LLM_*`. Framework keys that Laravel
already defaults sensibly are deliberately not repeated there.

Three that decide whether a deployment is safe rather than merely working:

| Key | Ship as | Why |
|---|---|---|
| `APP_DEBUG` | `false` | The debug page renders configuration, credentials and record contents. The application refuses to boot with it on outside local. |
| `SESSION_SECURE_COOKIE` | `true` | Forced by `config/session.php` outside local; the boot check exists for a config cache built elsewhere. |
| `WEBHOOKS_ALLOW_PRIVATE_HOSTS` | `false` | True lets a subscription point at `169.254.169.254` or an internal address, making the delivery worker a request-forgery tool with the platform's network position. |

`LICENSE_ENFORCE_VALID=false` is the shipping default. Activate the licence
under Administration → License, confirm it validates, then set it true and
re-run `php artisan config:cache`.

## Rollback

`scripts/deploy.sh` always deploys `origin/main`: its first step is
`git reset --hard origin/main`. A `git reset --hard <previous sha>` on the
server followed by `scripts/deploy.sh` therefore re-deploys `main` and undoes
the rollback. Put the previous code on `main` (a revert commit) and deploy
that. If the revert changes `scripts/deploy.sh` itself, the deploy that
ships it runs the copy bash already opened, not the new one; see "The first
deploy after this fix still runs the old step" above.

Migrations are **not** rolled back automatically and mostly should not be:
a `down()` that drops a column discards the rows written since the deploy. Roll
the code back first, then decide about the schema deliberately.

### Releases that cannot be rolled back past cleanly

- **Before `58662ca` (ADR 0026), for example to `056ec5a`, once a format-2
  configuration bundle exists.** From `58662ca` every `config_bundles` row is
  written sealed: its `payload` is one JSON string. That includes the snapshot
  each apply takes. Older code reads the column through an `array` cast, gets
  the string back, and passes it where an `array` is required. The result is a
  `TypeError`, which is HTTP 500 on the Configuration bundles screen and a crash
  in `config:import <id>`. It happens when you apply such a bundle, diff
  against it, or roll back to its snapshot. It also happens when you diff or
  apply any bundle at all, format 1 or a file, in an organisation whose last
  applied bundle is format 2, because the importer reads that bundle as the
  merge ancestor. The bundle list still loads, but shows such a bundle with 0
  rows. Count them first. This query runs on MySQL 8.0.46 and MariaDB 10.4:
  `SELECT COUNT(*) FROM config_bundles WHERE JSON_TYPE(payload) = 'STRING';`
  Nothing converts sealed rows back, and ADR 0026 §4 forbids re-checksumming
  bundle rows. Roll back past this release only when that count is 0, or accept that
  those bundles stay unusable until you roll forward again.
