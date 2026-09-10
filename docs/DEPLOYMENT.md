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
6. Re-apply runtime-writable permissions on `storage` and `bootstrap/cache`
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

`git reset --hard <previous sha>` and re-run `scripts/deploy.sh`. Migrations
are **not** rolled back automatically and mostly should not be: a `down()` that
drops a column discards the rows written since the deploy. Roll the code back
first, then decide about the schema deliberately.
