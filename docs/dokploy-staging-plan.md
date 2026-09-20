# Dokploy staging plan: Can Eye Budget

## Purpose and decision

Create a new Dokploy project for `can-eye-budget-v2` with a `staging` environment. Follow the existing `comparebuild/staging` pattern of one Dokploy service per
runtime container, but deploy the Laravel application as the web container because this repository has no standalone frontend runtime.

Recommended topology:

| Service             | Dokploy type          | Role                                         | Public domain         |
|---------------------|-----------------------|----------------------------------------------|-----------------------|
| `can-eye-web`       | Application           | Laravel HTTP server and compiled Vite assets | `can-eye.mrwilde.dev` |
| `can-eye-horizon`   | Application           | Horizon queue worker                         | None                  |
| `can-eye-scheduler` | Application           | Laravel scheduler                            | None                  |
| `can-eye-mariadb`   | Managed MariaDB/MySQL | Persistent application database              | None                  |
| `can-eye-redis`     | Redis                 | Queue, cache, and Horizon backend            | None                  |

Redis is an intentional addition to the reference topology. This application explicitly selects Redis for queues and cache and Horizon stores its supervisor and
metrics data in Redis. MariaDB is also an intentional divergence from the reference PostgreSQL service: the shipped report aggregation code uses `DATE_FORMAT`
for every non-SQLite driver, so PostgreSQL would break monthly report aggregation until the code is changed and fully tested.

This document is a provisioning plan, not a request to create or deploy Dokploy resources during this investigation.

**Step 0 has been implemented and merged to `develop`.** The five blocking code changes are now present: a root production image
(`Dockerfile`, `.dockerignore`, `docker/`), a trusted-proxy call in `bootstrap/app.php`, an allow-listed Horizon gate
(`app/Providers/HorizonServiceProvider.php` + `config/horizon.php` `authorized_emails`), `app/Listeners/VerifyHealthDependencies.php` so `/up` asserts
MariaDB and Redis, and the `FORTIFY_REGISTRATION_ENABLED` gate in `config/fortify.php` that lets staging close public sign-up.
Bank-import storage is settled as a shared volume — a Dokploy provisioning step, not a code change. The "Deployment sequence" is ready to proceed,
with the code prerequisites confirmed on `develop`.

## Step 0: raise the GitHub work before touching Dokploy

The GitHub work is raised in `robwilde/can-eye-budget-v2`. Parent tracking issue: **#375 "Staging deployment readiness (Dokploy)"** — links this document and
tracks the children below via a task list. Every blocking child is now merged to `develop`; the epic stays open until the Dokploy environment itself is
provisioned and verified.

Children: #369 (production image), #370 (Horizon gate), #371 (trusted proxies), #372 (`/up` dependencies) and #383 (registration env gate) were the blocking
set and are all merged to `develop`; #373 (dev-only dependencies) is also merged, and #374 (CI gating for staging deploys) is non-blocking and remains open. #383 blocked
because `FORTIFY_REGISTRATION_ENABLED` does nothing without its config gate: setting the variable on the service beforehand would leave sign-up open with
no error to say so. That gate is now on `develop` (`config/fortify.php:149`), so the staging variable is read.

| # | Issue | Work item                                                               | Status | Scope                                                                                                                                                                                                                                                        |
|---|---|-------------------------------------------------------------------------|-----------|----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| 1 | #369 | Add production `Dockerfile`, `.dockerignore`, and container entrypoint   | Merged to `develop` (c0ade4a) | Multi-stage Node asset build plus PHP 8.4 runtime; installs the extension set listed under "PHP extension requirements"; entrypoint runs migrations and caches config **before** the HTTP server binds; runs `storage:link` in the image, not by hand. Delivered as root `Dockerfile`, `.dockerignore`, `docker/entrypoint.sh`, `docker/nginx/default.conf`, `docker/supervisord.conf`, `docker/php.ini` |
| 2 | — | Provision the shared import volume                                      | Provisioning only | Mount one volume at `/var/www/html/storage/app/private` on both `can-eye-web` and `can-eye-horizon`. Settled — see "Storage decision (settled): shared volume". No repository change; `FILESYSTEM_DISK` stays `local`                                          |
| 3 | #370 | Restrict Horizon dashboard access outside `local`                       | Merged to `develop` (f39fcdd) | Gate is now an explicit allow-list: `config('horizon.authorized_emails')` from `HORIZON_AUTHORIZED_EMAILS`, with `local` short-circuited. Fails closed when unset. Registration is env-gated as of #383 — see "Security exposure decision"                              |
| 4 | #371 | Configure trusted proxies                                               | Merged to `develop` (94303c5) | `bootstrap/app.php` now calls `$middleware->trustProxies(at: '*')` with the framework's default header set; correct because the container is only reachable through Traefik on the Dokploy network                                                            |
| 5 | #372 | Add a `DiagnosingHealth` listener that asserts database and Redis       | Merged to `develop` (74ed230) | `app/Listeners/VerifyHealthDependencies.php` runs `select 1` and a Redis `ping`; auto-discovered from `app/Listeners`. `/up` now returns 500 when either dependency is unreachable — a dependency probe, not a readiness signal; see "`/up` is a dependency probe" |
| 6 | #383 | Env-gate registration and guard the landing page                        | Merged to `develop` (PR #395, c10c5b4) | `config/fortify.php` gates `Features::registration()` on `FORTIFY_REGISTRATION_ENABLED`, default `true` so local and production are unchanged; set `false` on staging. The three `route('register')` call sites in `resources/views/welcome.blade.php` are guarded with `Route::has('register')` so the landing page still renders once the routes are gone. Registration/password-reset throttling was split out to #394 — see row 9 |
| 7 | #373 | Move development-only dependencies out of the production dependency set | Merged to `develop` | `laravel/boost` and `spatie/laravel-ray` moved to `require-dev`, so `composer install --no-dev` no longer resolves them — verified by dry run, which now removes `laravel/boost`, `spatie/laravel-ray` and `spatie/ray`. `playwright` moved to `devDependencies` (previously empty) and `Dockerfile` line 22 gained `--omit=dev`, which drops `playwright` and `playwright-core` from the asset stage; every asset-pipeline package is a runtime `dependencies` entry, so `npm run build` is unaffected. The stale `bootstrap/cache/packages.php` that `COPY . .` carries in is not a hazard: `composer dump-autoload` runs `ComposerScripts::postAutoloadDump`, which deletes it before `package:discover` rebuilds it from the `--no-dev` tree |
| 8 | #374 | Decide whether staging deploys are gated on CI                          | Open, non-blocking | `.github/workflows/lint.yml` and `tests.yml` exist but nothing ties a staging deploy to them                                                                                                                                                                  |
| 9 | #394 | Throttle registration and password-reset requests                       | PR #429 open against `develop` | `routes/fortify.php` re-declares `register.store` and `password.email` against Fortify's own controllers with `throttle:register` and `throttle:password-reset`; limiters live in `app/Providers/FortifyServiceProvider::configureRateLimiting()`. See "Security exposure decision" for the mechanism and the two password-reset budgets |

Rows 1, 3, 4, 5 and 6 (#369, #370, #371, #372, #383) were the release blockers and are all merged to `develop`, the branch every Application service below is
configured to build, so the code gate on the "Deployment sequence" is satisfied. Row 2 is a Dokploy provisioning step, not a merge gate — but the volume must
exist before the first bank import.

Further hardening landed on `develop` after that blocking set, and the runbook below assumes it: a role-aware container health check (#379, PR #390), worker
containers running as `www-data` rather than root (#384, PR #393), application logs on the container `stderr` stream (#380, PR #388), Ray and Boost off
outside a developer machine (#381, PR #389) with the Ray enable default itself failing closed (#391, PRs #397 and #398), a throttle on `/up` (#382, PR #392),
and `CONTAINER_ROLE` validated before any side effect so an unknown or blank value aborts instead of silently defaulting to `web` (#399, PR #400; #401,
PR #402). None of this changes the deployment order below; it only means the image and configuration already behave as the later steps expect.

#430 drops `CMD` from the `Dockerfile` and has `docker/entrypoint.sh` resolve its process from `CONTAINER_ROLE` when it is passed no arguments. The
worker-service configuration below — `CONTAINER_ROLE` set, `command` and `args` both empty — relies on it, so before deploying `can-eye-horizon` or
`can-eye-scheduler` confirm the image Dokploy built carries that entrypoint (`docker image inspect <image> --format '{{.Config.Cmd}}'` prints `[]`).

## Dokploy inventory

Captured from the Dokploy project inventory and service details:

- Dokploy contains 9 projects in the current organization.
- Reference project: `comparebuild` (`Bc6MlLuvuhhiZ9hVzCstL`), description `this is the doc portal for the project`.
- Reference staging environment: `staging` (`GtCAOGo5_32kZ0SkazCqp`).
- Reference staging has four services, all reported `done` — a status that means only that Swarm accepted the spec, not that anything converged
  (see "Dokploy `done` is not readiness"):
    - Application `backend` (`q6L3rVbJlA35H-GXkuQjA`)
    - Application `frontend` (`Hvb0NgWmza7tE2PiU37Fb`)
    - Application `scheduler` (`de50uCfU75TcCqfihx2Mk`)
    - Managed PostgreSQL (`eVKzjC2hMINUmlAHRpa1G`)
- Reference staging has no Redis service and no external PostgreSQL port.
- **Provisioning status: nothing exists yet.** No `can-eye` project, environment, application, database, Redis service, or domain is present in the inventory,
  so every step of the deployment sequence from step 3 onward is outstanding.

### Reference application records

The following values are taken directly from the three Dokploy Application records, not inferred from the unavailable GitHub source:

| Application ID          | Service     | Repository / owner           | Branch | Build path / Docker context | `buildType`  | `dockerfile` | Command                          | Replicas |
|-------------------------|-------------|------------------------------|--------|-----------------------------|--------------|--------------|----------------------------------|----------|
| `q6L3rVbJlA35H-GXkuQjA` | `backend`   | `compare-build` / `robwilde` | `dev`  | `cb-backend`                | `dockerfile` | `Dockerfile` | Empty/default                    | 1        |
| `Hvb0NgWmza7tE2PiU37Fb` | `frontend`  | `compare-build` / `robwilde` | `dev`  | `cb-frontend-next`          | `dockerfile` | `Dockerfile` | Empty/default                    | 1        |
| `de50uCfU75TcCqfihx2Mk` | `scheduler` | `compare-build` / `robwilde` | `dev`  | `cb-backend`                | `dockerfile` | `Dockerfile` | `php /app/artisan schedule:work` | 1        |

The backend record uses the custom Git URL `https://github.com/robwilde/compare-build.git`; the frontend record uses the connected GitHub provider with the same
repository, owner, and branch. The frontend record's Docker build arguments include the API URL, site URL, staging flags, and the backend internal application
hostname.

Reference domains are `api.mychippy.mrwilde.dev` on backend port 80 and `mychippy.mrwilde.dev` on frontend port 3000, both HTTPS with Let's Encrypt.

### Reference PostgreSQL settings

- Image: `postgres:17`
- Database: `compare-build-db`
- Persistent volume mounted at `/var/lib/postgresql/data`
- No external port

### Reference source limitation

The authorized GitHub repository and file APIs returned 404 for `robwilde/compare-build`, and repository search returned no matching repository. Dokploy still
exposes the configured custom Git URL, branch, build contexts, and service settings. The reference Dockerfiles and compose/source contents therefore could not
be independently inspected; verify the reference image entrypoints and environment contract in Dokploy before copying any implementation detail.

## Can Eye build strategy

The root `Dockerfile`, `.dockerignore`, and `docker/` configs are on `develop` (there is still no `nixpacks.toml`, `Procfile`, or production
`docker-compose` file; `.ddev/` remains development-only). Point Dokploy at the root `Dockerfile`.

| Planned service     | Dokploy build mode    | Dockerfile/Nixpacks decision                                             | Prerequisite                                    |
|---------------------|-----------------------|--------------------------------------------------------------------------|-------------------------------------------------|
| `can-eye-web`       | Application           | Root `Dockerfile`; do not use Nixpacks                                   | Satisfied — #369 on `develop`                   |
| `can-eye-horizon`   | Application           | Reuse the same root `Dockerfile`; do not use Nixpacks                    | Same Dockerfile as `can-eye-web`                |
| `can-eye-scheduler` | Application           | Reuse the same root `Dockerfile`; do not use Nixpacks                    | Same Dockerfile as `can-eye-web`                |
| `can-eye-mariadb`   | Managed MariaDB/MySQL | Managed MariaDB 11.8 image; neither Nixpacks nor a repository Dockerfile | Create the managed database service and volume  |
| `can-eye-redis`     | Managed Redis         | Managed Redis 7.x image; neither Nixpacks nor a repository Dockerfile    | Create the managed Redis service                |

This is the cleanest match to the reference records, where all three Application records have `buildType=dockerfile` and `dockerfile=Dockerfile`. The one
authored Dockerfile builds the Vite assets and provides the PHP 8.4 HTTP runtime; the Horizon and scheduler services reuse that image unchanged and are
differentiated by `CONTAINER_ROLE` alone, with the Dokploy `command` and `args` fields left empty — see "Dokploy `command` replaces the entrypoint".

### PHP extension requirements

The image must install more than BCMath. Requirements taken from `composer.lock`, plus the drivers the chosen backing services need:

| Extension                                                   | Required by                                                            |
|-------------------------------------------------------------|------------------------------------------------------------------------|
| `pcntl`, `posix`                                            | `laravel/horizon` — `php artisan horizon` will not start without them   |
| `bcmath`                                                    | Platform requirement in `composer.json`                                |
| `zip`, `iconv`, `fileinfo`, `libxml`, `mbstring`, `openssl`  | `webklex/php-imap`                                                     |
| `filter`                                                    | `league/csv`                                                           |
| `pdo_mysql`                                                 | MariaDB connection                                                     |
| `redis`                                                     | `REDIS_CLIENT=phpredis`                                                |

Also standard for Laravel and assumed present in any PHP base image: `json`, `dom`, `tokenizer`, `ctype`, `pcre`, `curl`, `xml`, `xmlwriter`, `simplexml`,
`session`, `phar`, `hash`, `date`, `sockets`, `reflection`.

### Config cache policy

The plan's entire environment matrix is delivered as Dokploy runtime variables. If the Dockerfile ran `php artisan config:cache` or `optimize` during the build,
it would freeze whatever environment exists in the build layer and the runtime variables would be silently ignored. Settled in #369: `config:cache`,
`route:cache`, `view:cache`, and `event:cache` all run in `docker/entrypoint.sh`, **after** the environment is injected, and never in the `Dockerfile`.

No build-time application variables are required: `vite.config.js` declares only static inputs (`resources/css/app.css`, `resources/js/app.js`) and nothing
under `resources/` or `vite.config.js` reads `import.meta.env`, `VITE_*`, or `loadEnv`. The `VITE_APP_NAME` entry in `.env.example` is currently unused.

## compare-build container topology

The four reference staging containers map as follows:

| Reference container | Dokploy service    | Build/image                        | Runtime command                  | Domain                                |
|---------------------|--------------------|------------------------------------|----------------------------------|---------------------------------------|
| Backend             | `backend`          | Dockerfile from `cb-backend`       | Default image command            | `api.mychippy.mrwilde.dev` on port 80 |
| Frontend            | `frontend`         | Dockerfile from `cb-frontend-next` | Default image command            | `mychippy.mrwilde.dev` on port 3000   |
| Scheduler           | `scheduler`        | Dockerfile from `cb-backend`       | `php /app/artisan schedule:work` | None                                  |
| Database            | Managed PostgreSQL | `postgres:17`                      | PostgreSQL default               | None                                  |

No queue worker or cache container is present in the reference inventory. That omission is not copied blindly: Can Eye Budget has a checked-in Redis queue/cache
configuration and a first-class Horizon provider, so the planned Redis service is required for the default topology.

## can-eye-budget-v2 container requirements

Evidence from the repository:

- `composer.json` requires PHP `^8.4`, Laravel `^12.56.0`, and `laravel/horizon` `^5.45.5`.
- `package.json` exposes only `vite build` and `vite` scripts. It does not define a production frontend server.
- `.ddev/config.yaml` uses PHP 8.4, nginx-fpm, and MariaDB 11.8 locally.
- `.ddev/docker-compose.redis.yaml` uses Redis 7 and exposes Redis only to the local network.
- `.env.example` selects `SESSION_DRIVER=database`, `QUEUE_CONNECTION=redis`, `CACHE_STORE=redis`, `REDIS_HOST=redis`, and `FILESYSTEM_DISK=local`.
- `bootstrap/app.php` schedules `horizon:snapshot` every five minutes and `app:sync-redbark-feeds` every six hours with overlap protection.
- `routes/console.php` schedules Basiq stuck-refresh cleanup every five minutes, Redbark stuck-sync cleanup every five minutes, and `app:expire-redbark-holds`
  daily at 03:15, all with overlap protection.
- Queue jobs implement `ShouldQueue`, and `config/horizon.php` uses the default Redis connection for Horizon state.
- `config/filesystems.php` provides local/private, local/public, and S3 disks. The public disk URL is derived from `APP_URL`, and `public/storage` links to
  `storage/app/public`. Every disk sets `'throw' => false`, so storage failures are silent.
- `app/Services/Reports/ReportAggregator.php:276-281` branches only for SQLite; every other driver uses `DATE_FORMAT(post_date, '%Y-%m')`. MariaDB/MySQL is
  therefore required by the current report implementation.
- `bootstrap/app.php` now calls `$middleware->trustProxies(at: '*')` alongside `validateCsrfTokens(except: ['webhooks/basiq'])` (#371), so behind Dokploy's
  Traefik the application resolves the real client IP and recognises the request as secure. The framework's default trusted-header set is kept.
- `/up` is registered explicitly in `bootstrap/app.php` as `App\Http\Controllers\HealthCheckController` behind `throttle:60,1` (#382); the
  `withRouting(health: '/up')` shorthand was dropped because it offers no middleware hook. The controller reproduces the framework handler
  (`Illuminate\Foundation\Configuration\ApplicationBuilder`): it dispatches `DiagnosingHealth` and returns 200 unless a listener throws. One behaviour the
  shorthand provided implicitly is preserved deliberately: `preventRequestsDuringMaintenance(except: ['up'])`, which keeps `/up` reachable during
  `artisan down` so a deploy does not mark the container unhealthy. The throttle itself is new — the shorthand applied no middleware at all, so the endpoint
  was previously unlimited. Its limiter keys per client IP, and the container's own `HEALTHCHECK` (`curl http://127.0.0.1/up`) bypasses Traefik and so
  carries no forwarded header, giving it a bucket no external burst can starve. One deliberate divergence from the framework handler: it stores the throwable
  rather than its message, so a listener failing with an empty message still reports unhealthy instead of rendering the healthy state.
  `app/Listeners/VerifyHealthDependencies.php` (#372) is that listener: it runs `select 1` on the
  default connection and pings Redis, so `/up` returns 500 when MariaDB or Redis is unreachable instead of reporting healthy on a broken application. Note the
  scope: `select 1` proves connectivity, **not** schema state, so `/up` can still return 200 against a reachable but unmigrated database. Migration ordering is
  guaranteed by `docker/entrypoint.sh` running `migrate` before `exec`, not by this listener. See "`/up` is a dependency probe".
- `app/Models/User.php` does not implement `MustVerifyEmail`, and no class in `app/` or `config/` references it. Although `config/fortify.php` enables
  `Features::emailVerification()`, the `['auth','verified']` group in `routes/web.php:16` is a pass-through, so registration and login do **not** depend on
  outbound mail. Only password reset and any deliberate verification testing need a working mailer.

The runtime therefore needs one HTTP container, one Horizon worker container, one scheduler container, MariaDB/MySQL for application data and sessions, and
Redis for queue/cache/Horizon. Vite runs during image build; it is not a separate Dokploy runtime service.

### Database compatibility decision

Use managed MariaDB 11.8 for staging and set Laravel's `DB_CONNECTION=mariadb`. This matches `.ddev/config.yaml` and the application's dedicated `mariadb`
connection in `config/database.php`, while preserving the non-SQLite report expression. Note that the checked-in default is SQLite — `.env.example` sets
`DB_CONNECTION=sqlite` and `config/database.php` defaults to `sqlite` — so `DB_CONNECTION` must be set explicitly on all three Application services.

The choice is low-risk beyond the report expression: all 51 files in `database/migrations` were scanned for `DB::statement`, `jsonb`, `ENGINE=`, `FULLTEXT`,
`fullText`, `generatedAs`, and explicit collation/charset. The only hit is a portable `DB::raw('import_source')` in
`2026_08_23_100001_add_balance_source_to_accounts_table.php:20`. No migration is engine-specific.

The reference's PostgreSQL 17 service is documented for parity comparison only; it must not be copied to Can Eye without first adding a PostgreSQL branch to
`ReportAggregator::monthExpression()` and running the complete report test coverage.

### Storage decision (settled): shared volume

**`FILESYSTEM_DISK=s3` was rejected: it does not work and does not solve the problem it was chosen for.** Two independent reasons:

1. The bank-import flow does not consult `FILESYSTEM_DISK`. `app/Livewire/ImportBank.php:84` stores with the disk hard-coded (`->store('bank-imports', 'local')`),
   and `ImportBank.php:85,238` plus `app/Jobs/ImportCsvTransactionsJob.php:74` call `Storage::disk('local')->path(...)`. The web container writes the file and the
   Horizon container reads it by absolute local path. Changing `FILESYSTEM_DISK` has no effect on either side, and the S3 driver cannot satisfy `->path()` at all.
2. `league/flysystem-aws-s3-v3` is not installed. `composer.lock` contains only `league/flysystem` 3.33.0 and `league/flysystem-local` 3.31.0, so any disk using
   the `s3` driver fails at runtime.

A full grep of `app/` for `Storage::`, `->store(`, and `storeAs(` confirms the bank import is the **only** cross-container file flow. The feedback screenshot path
(`app/Livewire/FeedbackWidget.php:180` writes the `public` disk, `app/Services/GitHubService.php:74` reads it) runs synchronously inside a single web request via
the injected `GitHubServiceContract`, so it is container-local and needs no shared storage — only `storage:link`, and only for the duration of the request.

**Decision: shared persistent volume.** Mount one volume at `/var/www/html/storage/app/private` on both `can-eye-web` and `can-eye-horizon`. No code change;
`FILESYSTEM_DISK` stays `local`. This is forced by the code: the disk name is hard-coded at `app/Livewire/ImportBank.php:84` and read back through
`Storage::disk('local')->path()` at `ImportBank.php:85,238` and `app/Jobs/ImportCsvTransactionsJob.php:74`, so no env change can redirect it and the S3 driver
cannot serve `->path()` at all. The volume must be created and an import tested before the first real import is attempted.

The rejected alternative — refactoring both call sites onto a driver-agnostic Flysystem stream and adding `league/flysystem-aws-s3-v3` — remains the only route to
S3 if the shared volume ever becomes impossible. It is a larger change and needs import test coverage.

This is a provisioning task, not a code change. Never point staging at a production bucket.

### Security exposure decision (resolved for the gate)

The Horizon dashboard is served by the web container and is therefore publicly reachable on the staging domain. Confirming that the Horizon *container* has no
port or domain does not address that.

Resolved in #370: `app/Providers/HorizonServiceProvider::gate()` now allows `local` unconditionally, denies guests, and otherwise requires the user's email to
appear in `config('horizon.authorized_emails')` — populated from the comma-separated `HORIZON_AUTHORIZED_EMAILS` variable. With that variable unset the
allow-list is empty and nobody outside `local` can open the dashboard, so the control fails closed. Covered by `tests/Feature/HorizonAccessTest.php`.

Also resolved, in #383: `config/fortify.php` now gates `Features::registration()` on `FORTIFY_REGISTRATION_ENABLED`, defaulting to `true` so local and
production are unchanged. Set it to `false` on staging and seed accounts instead. The three `route('register')` call sites in
`resources/views/welcome.blade.php` are guarded with `Route::has('register')`, so the landing page still renders once the routes are gone. Covered by
`tests/Feature/Auth/RegistrationDisabledTest.php`.

Addressed in #394, on PR #429 against `develop`: `routes/fortify.php` re-declares the two POST routes Fortify leaves unthrottleable — `register.store` and
`password.email` — against Fortify's own controllers with `throttle:register` and `throttle:password-reset`, and
`app/Providers/FortifyServiceProvider::configureRateLimiting()` defines both limiters: 5 registrations per minute per client, and for password reset 5 requests
per minute per client plus 3 per hour per target address, so a distributed sender cannot mail-bomb one inbox by staying under every per-client ceiling. The
address budget is only applied to a non-empty normalised address, because the limiter runs before validation and keying a shared bucket on the empty string
would let a few malformed requests block every real address. The override lands because `Illuminate\Routing\RouteCollection::addToCollections()` keys routes by
method and URI, so the later declaration replaces Fortify's for dispatch, and because it is an ordinary route declaration it serialises into the `route:cache`
the entrypoint builds — the provider-side mutation tried during #383 did neither. Only those two routes are owned here; the rest of Fortify's route table stays
with Fortify. Covered by `tests/Feature/Auth/AuthWriteThrottleTest.php`, including a cached-collection dispatch test.

Remaining hardening, deliberately out of scope for #370, #383 and #394:

- `HORIZON_PATH` is still the default `horizon`. An unguessable path is optional defence in depth now that the gate is an allow-list.

## Domain status

Dokploy domain validation for `can-eye.mrwilde.dev` against server IP `144.6.123.191` returns `isValid=true` with `resolvedIp=144.6.123.191`. DNS is therefore
ready for the planned web service. No Dokploy domain attachment exists yet.

Attach `can-eye.mrwilde.dev` only to `can-eye-web`, port 80, with HTTPS enabled and a Let's Encrypt certificate. Do not attach the domain to Horizon, scheduler,
MariaDB, or Redis. Attach it **after** the first successful migration — see the deployment sequence.

## Provisioning plan

### Service: can-eye-mariadb

- Create a managed MariaDB/MySQL service in project `can-eye-budget-v2`, environment `staging`.
- Use MariaDB 11.8 to match local development and the current report SQL compatibility requirement.
- Use a staging-only database name such as `can_eye_budget_staging`.
- Generate a unique staging username, password, and root password; do not reuse any reference or local credentials.
- Mount the managed persistent volume at the service's MariaDB data path shown by Dokploy.
- Do not expose an external database port.
- Record the Dokploy internal hostname and database credentials for the application environment. Use the actual hostname shown by Dokploy rather than guessing a
  service-name convention.

### Service: can-eye-redis

- Create a managed Redis service in the same project and environment.
- Use Redis 7.x to match local configuration.
- Do not expose an external Redis port.
- Generate a password if the Dokploy Redis service supports authentication and use the internal hostname shown by Dokploy.
- Give this staging instance a dedicated key namespace through `REDIS_PREFIX`, `CACHE_PREFIX`, and `HORIZON_PREFIX` so it cannot collide with another
  application.

### Service: can-eye-web

- Create an Application service with repository `https://github.com/robwilde/can-eye-budget-v2.git`, owner `robwilde`, branch `develop`, repository root as
  build path/context, `buildType=dockerfile`, and `dockerfile=Dockerfile`. The root Dockerfile from #369 is on `develop`; do not select Nixpacks.
- The image prerequisite is a multi-stage Node build that runs `npm ci` and `npm run build`; the runtime contains PHP 8.4, the extensions listed under "PHP
  extension requirements", Composer production dependencies, and an HTTP server listening on port 80.
- Serve the Laravel `public` directory. Do not run `npm run dev` in staging.
- Use one replica initially, matching the reference.
- Configure the Laravel environment listed in the environment matrix below.
- `storage:link` runs in `docker/entrypoint.sh` (`--force`, idempotent), never as a manual step: the container filesystem is ephemeral and a hand-run symlink is
  lost on every redeploy.
- Leave the `command` and `args` fields **empty** and set `CONTAINER_ROLE=web`; the entrypoint resolves `web` → `supervisord -c /etc/supervisord.conf` on its
  own since #430. See "Dokploy `command` replaces the entrypoint".
- Migrations run from the entrypoint before the HTTP server binds, gated on `CONTAINER_ROLE=web` and taking a cache lock via `--isolated`, so the container
  cannot report ready on an unmigrated schema. `CONTAINER_ROLE` defaults to `web`, so this is the only service that migrates. The `--isolated` lock is taken
  through the default cache store, which is why `CACHE_STORE` is a first-boot precondition — see step 8 of the deployment sequence.
- Leave Dokploy's Swarm health-check fields empty so the container inherits the image `HEALTHCHECK`, which has a 90s start period and is applied to the `web`
  role by `docker/healthcheck.sh`. It probes Laravel's `/up` route, which #372 gives a `DiagnosingHealth` listener asserting MariaDB and Redis, so it returns
  500 when either dependency is unreachable. Treat it as a dependency probe — see "`/up` is a dependency probe".
- Attach `can-eye.mrwilde.dev` to port 80 with HTTPS and Let's Encrypt after the first migration succeeds.

### Service: can-eye-horizon

- Create a second Application service named `can-eye-horizon` from the same repository, owner, branch, root build path/context, `buildType=dockerfile`, and the
  same root `Dockerfile`; do not select Nixpacks.
- Use one replica.
- Leave both the container `command` and `args` fields **empty** and set `CONTAINER_ROLE=horizon`. Since #430 the image carries no `CMD` and
  `docker/entrypoint.sh` resolves its own process from `CONTAINER_ROLE` when it is passed no arguments: `horizon` → `php artisan horizon`. Filling in Dokploy's
  `command` field is a regression rather than a configuration choice — it replaces the image entrypoint outright, so the worker would run as root against an
  uncached config. See "Dokploy `command` replaces the entrypoint" below.
- This makes `docker/healthcheck.sh` verify Horizon's liveness via `php artisan horizon:liveness` (#396), a container-local check that
  looks for a master named for this container's hostname within Horizon's 14s expiry window, so the container reports unhealthy if its own Horizon supervisor crashes
  or loses Redis connectivity. It deliberately does not use `horizon:status`: that returns non-zero while Horizon is merely `paused` (which a deploy does), and it
  reads Horizon's fleet-wide `masters` set, so a healthy peer sharing this Redis and `HORIZON_PREFIX` would mask a dead local master. Note that `HORIZON_NAME` does
  not scope this: `horizon.name` is a display label (notifications and the Horizon UI, per `config/horizon.php`) and never participates in `MasterSupervisor`'s name
  resolution, which is what actually names a master.
- Do not attach a domain or external port.
- Reuse the same application, database, Redis, and storage environment values as the web service. Set a distinct `HORIZON_NAME`, for example
  `can-eye-staging-horizon`.
- Mount the shared import volume at `/var/www/html/storage/app/private` here, the same volume as on `can-eye-web`. The bank import fails without it.
- Verify that the container remains running under Horizon supervision and that a test queued job is visible in Horizon metrics.
- Deploy this service only after `can-eye-web` is deployed and migrations have run, so the worker starts against a migrated schema.

### Service: can-eye-scheduler

- Create a third Application service named `can-eye-scheduler` from the same repository, owner, branch, root build path/context, `buildType=dockerfile`, and the
  same root `Dockerfile`; do not select Nixpacks.
- Use one replica.
- Leave both the container `command` and `args` fields **empty** and set `CONTAINER_ROLE=scheduler`. Since #430 the entrypoint resolves `scheduler` →
  `php artisan schedule:work` on its own. The reference `scheduler` service's `php /app/artisan schedule:work` command must **not** be copied here: see
  "Dokploy `command` replaces the entrypoint" below.
- `CONTAINER_ROLE=scheduler` also makes `docker/healthcheck.sh` verify the scheduler's liveness via a timestamped heartbeat file (#396), so the container
  reports unhealthy if the scheduler stops dispatching or crashes.
- Reuse the same application, database, Redis, and storage environment values as the web service.
- Note that two of the three schedules in `routes/console.php` are `Schedule::call()` closures that execute in this container and write to the database directly,
  so this service needs working database credentials, not just Redis.
- Confirm scheduler logs show the five-minute Horizon snapshot and the application schedules, including Redbark synchronization and hold expiry.
- Deploy this service only after `can-eye-web` is deployed and migrations have run, so scheduled commands never hit an unmigrated schema.

### Dokploy `command` replaces the entrypoint

Dokploy maps an Application's `command` field to Swarm `ContainerSpec.Command`, and Docker treats that as a replacement rather than an addition: in moby's
`daemon/cluster/executor/container/container.go`, "If Command is provided, we replace the whole invocation with Command by replacing Entrypoint and specifying
Cmd". A value in that field therefore does not run *through* `docker/entrypoint.sh` — it runs *instead of* it, and the container loses every guarantee the
entrypoint provides:

- no `CONTAINER_ROLE` validation, so an unknown or blank role no longer aborts (#399, #401);
- no `APP_ENV` export, so Horizon sizing and the Ray/Boost gates resolve against `production` (see `APP_ENV` in the environment matrix);
- no `config:cache`, `route:cache`, `view:cache` or `event:cache`, so the runtime variables are read from the environment on every request instead of a cache;
- no `chown` of the storage tree, so a fresh volume stays root-owned;
- no `su-exec www-data`, so the worker runs as **root** — reversing #384 outright.

Dokploy's separate `args` field maps to `ContainerSpec.Args`, which *does* preserve the entrypoint. Neither field is needed here: since #430 the image ships no
`CMD` and `docker/entrypoint.sh` resolves the process from `CONTAINER_ROLE` when it receives no arguments — `web` → `supervisord -c /etc/supervisord.conf`,
`horizon` → `php artisan horizon`, `scheduler` → `php artisan schedule:work` — while still honouring an explicit argument list when one is given. All three
Applications are therefore configured with `CONTAINER_ROLE` set and **both** fields empty.

The reference `comparebuild/scheduler` record is the shape to avoid copying: `command: "php /app/artisan schedule:work"`, `args: null`. Its other two fields
are worth noting because this plan inherits them deliberately — `healthCheckSwarm: null` and
`restartPolicySwarm: {Condition: on-failure, Delay: 10s, MaxAttempts: 5, Window: 60s}`.

Leave `healthCheckSwarm` null. With no Swarm health check sent, the container inherits the image `HEALTHCHECK`, which is what the role-aware
`docker/healthcheck.sh` is for. Docker merges health-check configuration field by field, so filling in only part of Dokploy's Swarm health-check UI silently
inherits the remaining fields from the image and produces a probe nobody wrote.

### `/up` is a dependency probe

`bootstrap/app.php` registers `/up` in the `then:` callback of `withRouting()` with only `throttle:60,1`, so it sits outside the `web` middleware group and
exercises no encryption and no session. #372's `DiagnosingHealth` listener makes it return 500 when MariaDB or Redis is unreachable, which is
what the image `HEALTHCHECK` needs — but a container with no `APP_KEY` was verified `healthy` with `/up` returning 200 while `/` and `/login` returned
500. Readiness is therefore a real page plus a session, checked in step 10 of the deployment sequence; `/up` only proves the dependencies answer.

### Dokploy `done` is not readiness

`deployApplication` flips both the deployment and the application status straight after `mechanizeDockerContainer`, with no convergence or health wait — the
reference deployments finish in about five seconds. A Dokploy deployment that reads `done` therefore means only that Swarm accepted the service spec. **A
crash-looping container displays green.**

Swarm's own behaviour completes the illusion: it shuts a container down on the first `unhealthy` event and only adds a task to the service load balancer on the
`healthy` event. So the signature of a broken deploy is not a red status — it is "green in Dokploy, 502 from Traefik".

Dokploy status is not evidence. The container log and `docker service ps --no-trunc <service>` are the only diagnosis surface; check them at every readiness
step below rather than the deployment badge.

## Environment matrix

Set the common application environment on all three Application services. Values below are names and policies, not credentials:

| Variable                                                                 | Staging value/policy                                                                                                                     |
|--------------------------------------------------------------------------|------------------------------------------------------------------------------------------------------------------------------------------|
| `APP_NAME`                                                               | `Can Eye Budget (Staging)`                                                                                                               |
| `APP_ENV`                                                                | `staging`. Load-bearing beyond the Ray/Boost gates: `config/horizon.php` `environments` sizes `production` at `maxProcesses: 10` and `staging` at `3`. If it is unset the entrypoint exports `production` and staging runs ten workers |
| `APP_KEY`                                                                | Generate a new staging key; never reuse another environment's key. **First-boot precondition:** a missing key still yields a *green* container because `/up` never touches encryption — see "`/up` is a dependency probe" |
| `APP_DEBUG`                                                              | `false`                                                                                                                                  |
| `APP_URL`                                                                | `https://can-eye.mrwilde.dev`                                                                                                            |
| `APP_TIMEZONE`                                                           | `Australia/Brisbane` — already the repository default in `config/app.php`, so this row is redundant but harmless                         |
| `LOG_CHANNEL` / `LOG_STACK`                                              | `stack` / `stderr` on all three services. `single` writes to `storage/logs/laravel.log`, which is ephemeral and invisible to Dokploy      |
| `LOG_LEVEL`                                                              | `info` on all three services. Set it explicitly: `.env.example` is not deployed and `config/logging.php` falls back to `debug`           |
| `DB_CONNECTION`                                                          | `mariadb` — must be set explicitly; the repository default is `sqlite`                                                                    |
| `DB_HOST` / `DB_PORT`                                                    | Dokploy MariaDB internal hostname / `3306`                                                                                               |
| `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD`                            | Staging-only MariaDB values                                                                                                              |
| `SESSION_DRIVER`                                                         | `database`. No separate migration is required: the `sessions` table is created inside `database/migrations/0001_01_01_000000_create_users_table.php` |
| `SESSION_SECURE_COOKIE`                                                  | `true` — the domain is HTTPS-only. Fail-open: `config/session.php` carries no default, so leaving it unset serves a non-secure session cookie. Same "must be set to be safe" class as `FORTIFY_REGISTRATION_ENABLED` |
| `TRUSTED_PROXIES`                                                        | Not implemented — nothing in the codebase reads this key. `bootstrap/app.php` hardcodes `trustProxies(at: '*')` (#371), so setting the variable has no effect; a topology that stops being Traefik-only needs a code change, not a variable |
| `QUEUE_CONNECTION`                                                       | `redis`                                                                                                                                  |
| `REDIS_QUEUE_RETRY_AFTER`                                                | `150`. `config/queue.php` defaults the `redis` connection's `retry_after` to 90 while `config/horizon.php` `defaults.supervisor-1.timeout` is 120, so a job still running at 90s is released and processed a second time — duplicated transactions on a bank-import and reconciliation application |
| `CACHE_STORE`                                                            | `redis`. **First-boot precondition, not a caching preference.** Unset, it defaults to `database` (`config/cache.php`), and `migrate --force --isolated` takes its mutex through the default cache store: on a virgin schema that fails on a missing `cache_locks` table — the table created by the very migration run that has not happened yet — and the entrypoint exits 1 under `set -e` before port 80 binds. An unreachable Redis therefore fails the *migration*, not merely caching. `CACHE_STORE=file` is the documented escape hatch for migrating before Redis exists |
| `REDIS_CLIENT`                                                           | `phpredis`; the image must install `ext-redis`                                                                                            |
| `REDIS_HOST` / `REDIS_PORT` / `REDIS_PASSWORD`                           | Dokploy Redis internal hostname / `6379` / staging-only credential                                                                       |
| `REDIS_USERNAME`                                                         | Required when the Dokploy Redis enforces an ACL user: password-only auth fails against an ACL-user Redis, and that surfaces as a *migration* failure rather than a cache warning |
| `REDIS_PREFIX`                                                           | Connection-wide key prefix; defaults to `slug(APP_NAME)-database-`. Set explicitly so staging cannot collide with another app             |
| `CACHE_PREFIX`                                                           | Unique staging prefix, for example `can_eye_staging`                                                                                     |
| `HORIZON_PREFIX`                                                         | Unique staging prefix, for example `can_eye_staging_horizon:`                                                                             |
| `HORIZON_NAME`                                                           | `can-eye-staging-horizon` on the Horizon service                                                                                         |
| `HORIZON_PATH`                                                           | Optional defence in depth now the gate is an allow-list; the default is `horizon`                                                          |
| `HORIZON_AUTHORIZED_EMAILS`                                              | Comma-separated allow-list of emails permitted to open the dashboard outside `local`. Empty means nobody can — fails closed                |
| `FORTIFY_REGISTRATION_ENABLED`                                           | `false` on staging — closes public sign-up on a bank-feed application. Default is `true`, so local and production are unchanged            |
| `CONTAINER_ROLE`                                                         | `web` on `can-eye-web`, `horizon` on `can-eye-horizon`, `scheduler` on `can-eye-scheduler`. Defaults to `web`. This is the **only** setting that differentiates the three services: since #430 the entrypoint resolves its own process from it, so the Dokploy `command` and `args` fields stay empty on all three. Only the `web` role migrates; the `web` role is health-checked over HTTP (`/up`), while `horizon` and `scheduler` roles are health-checked via process-specific liveness probes (#396) |
| `FILESYSTEM_DISK`                                                        | `local`, paired with the shared volume at `storage/app/private` — see "Storage decision (settled): shared volume". Never set `s3`        |
| `LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK`                                    | `local` on `can-eye-web`. Livewire temporary uploads default to the `local` disk — the shared `storage/app/private` volume — so the CSV import needs that mount on the web service too, not only on `can-eye-horizon` |
| `AWS_*`                                                                  | Not used; leave empty. Only relevant if the rejected S3 refactor is ever revisited                                                        |
| `MAIL_*`                                                                 | Staging SMTP/sandbox settings; never production mailbox credentials. `log` is fine unless password reset or verification is being tested   |
| `BASIQ_*`                                                                | Empty or Basiq sandbox credentials and callback URL only. Note `webhooks/basiq` is CSRF-exempt and publicly reachable regardless          |
| `REDBARK_BASE_URL` / `REDBARK_INCLUDE_PENDING` / `REDBARK_HOLD_TTL_DAYS` | Staging API policy; do not use production secrets by default                                                                              |
| `GMAIL_USERNAME` / `GMAIL_APP_PASSWORD`                                  | Empty unless a dedicated staging mailbox is approved                                                                                     |
| `GITHUB_TOKEN`                                                           | A least-privilege staging token only if feedback issue/screenshot upload is required                                                      |
| `GITHUB_FEEDBACK_REPO`                                                   | Keep the target repository only if staging feedback is intended to create issues                                                          |
| `GITHUB_FEEDBACK_RELEASE_ID`                                             | A staging release asset target, or empty                                                                                                 |
| `FEEDBACK_SCREENSHOT_URL`                                                | Empty/disabled unless a separately hosted screenshot service is provided; the local `host.docker.internal` value is not valid on Dokploy |
| `BUDGET_RECURRING_DETECTION` / `BUDGET_BNPL_EMAIL_IMPORT`                | Set deliberately for staging; retain the repository defaults until those flows are approved                                               |
| `RAY_ENABLED`                                                            | `false` on all three services. `ray.php:40` resolves the environment env-first with `??`: any non-null `env('APP_ENV')` wins, and `config('app.env')` is consulted only when it is null — an unset variable, or the literal `null`/`(null)` that Laravel coerces to null — falling back to `production` when no `config` repository is bound. `??` rather than `?:` is deliberate, because `env('APP_ENV')` returns `''` for an explicitly empty value and boolean `false` for `APP_ENV=false`; `?:` would discard both as absent and hand resolution to the cache. A staging container therefore still fails closed on two independent grounds — the real `APP_ENV` is `staging`, and a config cache baked from that same environment also reads `staging`. `docker/entrypoint.sh` runs `php artisan config:cache` on every boot, after which `LoadEnvironmentVariables` returns early and never reads `.env`. `docker/entrypoint.sh:33-71` therefore resolves and exports `APP_ENV` before that cache is built, so `env('APP_ENV')` resolves in any container booted through the entrypoint; an already-exported value is authoritative and is never overwritten, and where neither source supplies one the entrypoint exports `production`, matching Laravel's own default at `config/app.php:31`, so no container boots without an exported `APP_ENV` (#423). The `null`/`(null)` sentinels are dropped before that fallback, case-insensitively and untrimmed to mirror the `strtolower($value)` at `Env.php:256`, because `Env.php:266-268` decodes both as absent — so `env('APP_ENV')` cannot resolve null and no path reaches the cache. `APP_ENV=`, `APP_ENV=false` and the `(empty)`/`(false)` forms are deliberately kept: `env()` reports each as a value and each compares unequal to `local`. Env-first precedence is scoped to exported OS variables and that export is what guarantees one, so a stale cache baked at `local` no longer decides the environment — measured in an Alpine container, a cache reading `local` yields `enable` `false` as soon as `APP_ENV=production` is exported (#419). A `RAY_ENABLED=false` that exists only in `.env` is still invisible to `env()` once the cache exists and cannot countermand a cache baked at `local`; `RAY_ENABLED` has no cached config key, and adding `config/ray.php` would shadow the root `ray.php` via Spatie's upward `SettingsFactory` search. Export `RAY_ENABLED=false` as a real OS variable where that matters. Keep this set as explicit defence in depth rather than the only guard. When Ray is enabled it adds 18 watchers per request and per queued job plus an availability probe to `RAY_HOST:RAY_PORT`, which default to `host.docker.internal` and `23517` (`ray.php:141`, `ray.php:146`). Ray caches an unavailable result for 30s per process (`Client.php:67`), so that probe is per window, not per log line. The 2s `CURLOPT_TIMEOUT` is a ceiling that needs a routable address which silently drops packets; an unresolvable `host.docker.internal` fails DNS in milliseconds |
| `BOOST_ENABLED`                                                          | `false` on all three services. Boost activates on `local` **or** `APP_DEBUG=true`, and publishes an unauthenticated, CSRF-exempt `POST /_boost/browser-logs` plus a JS-injecting `web` middleware. Setting it `false` removes that route only — it does not make `APP_DEBUG=true` safe on a public domain, where the debug error page still exposes stack traces, configuration and query bindings. `APP_DEBUG` stays `false` |
| `SENTRY_DSN`                                                             | Same project DSN on all three services. The `environment` tag is derived from `APP_ENV`, so staging events separate themselves from local ones with no extra variable. Empty disables the SDK — errors then exist only in the container log |

Repository defaults are adequate for `APP_LOCALE` and `SESSION_LIFETIME`. `BROADCAST_CONNECTION` and `BCRYPT_ROUNDS` are also safe to omit, but not because this
repository configures them — it ships neither `config/broadcasting.php` nor `config/hashing.php`. Both defaults come from `vendor/laravel/framework/config/`:
`broadcasting.php` defaults the connection to `null` and `hashing.php` defaults the bcrypt cost to 12. `LOG_LEVEL` is not in that group: set it explicitly, as
the matrix above requires.

### Secret handling

Generate all staging secrets in Dokploy's environment-variable storage. Do not paste values from the reference service output into this plan or into another
environment. Any live-looking credentials previously exposed in Dokploy output should be rotated in their owning system.

## Deployment sequence

Steps 1 and 2 were the code gate; both are satisfied. Everything from step 3 onward is outstanding Dokploy work and has not been performed.

1. Done: parent issue #375 and children #369–#374 plus #383 exist in `robwilde/can-eye-budget-v2`.
2. Done: #369, #370, #371, #372 and #383 are merged to `develop`. If in doubt, confirm the branch contains the root `Dockerfile`, `docker/entrypoint.sh`
   migrating before serving, `trustProxies` in `bootstrap/app.php`, the allow-listed Horizon gate, `app/Listeners/VerifyHealthDependencies.php`, and the
   `FORTIFY_REGISTRATION_ENABLED` gate in `config/fortify.php`. Without that last one the staging variable set in step 8 would be inert. #373 is merged too,
   though it never gated the deploy; #374 still does not.
3. Nothing needs arranging for source access: `robwilde/can-eye-budget-v2` is public (`"private": false` from an unauthenticated GitHub API call), so a
   git-source clone of `develop` needs no credential and no connected GitHub provider.
4. Create project `can-eye-budget-v2` and environment `staging`.
5. Create and deploy MariaDB and Redis; wait for both services to report ready and record their internal hostnames.
6. Create the shared import volume (settled decision) before any Application is deployed.
7. Create `can-eye-web`, `can-eye-horizon`, and `can-eye-scheduler` from the same source and Dockerfile. Keep one replica for each initially and do not deploy
   any Application yet. Mount the shared volume on `can-eye-web` and `can-eye-horizon`.
8. Set the common environment variables. Leave the `command` **and** `args` fields empty on all three services and differentiate them with `CONTAINER_ROLE`
   alone (`web`, `horizon`, `scheduler`) — see "Dokploy `command` replaces the entrypoint". Set `APP_DEBUG=false`, `DB_CONNECTION=mariadb`,
   `HORIZON_AUTHORIZED_EMAILS`, `FORTIFY_REGISTRATION_ENABLED=false` and a distinct `HORIZON_NAME` before the first deployment. Two variables are **first-boot
   preconditions** rather than preferences, and getting either wrong costs a deploy: `CACHE_STORE`, because `migrate --force --isolated` takes its mutex
   through the default cache store and the unset default is `database` — on a virgin schema the `cache_locks` table does not exist yet, so the entrypoint exits
   1 under `set -e` before port 80 binds (set `redis`, or `file` to migrate before Redis exists); and `APP_KEY`, because without it the container still reports
   `healthy` and only real pages fail.
9. Deploy `can-eye-web` with **no domain attached**. The entrypoint runs `php artisan migrate --force` before binding the HTTP port, so the service only reports
   ready once the schema exists. Take a MariaDB snapshot before this first migration.
10. Confirm readiness from the container, not from Dokploy. The badge reads `done` as soon as Swarm accepts the spec, so a crash-looping container displays
    green and the usual signature of a broken deploy is "green in Dokploy, 502 from Traefik" — see "Dokploy `done` is not readiness". The container log must
    show the migrations completing and the role process starting; `docker service ps --no-trunc <service>` is the other diagnosis surface. `/up` returning 200
    is not sufficient either — see "`/up` is a dependency probe". Readiness means a real page renders and a session is issued: from inside the container,
    `http://127.0.0.1/` and `http://127.0.0.1/login` must both return 200 and `/login` must answer with a `Set-Cookie` for the session. Do not expect a second
    plain-HTTP request to carry that cookie back: `SESSION_SECURE_COOKIE=true` marks it `Secure`, so a client only resends it over HTTPS. The round trip is
    step 13's job, over the real domain.
11. Attach `can-eye.mrwilde.dev` to `can-eye-web` with HTTPS/Let's Encrypt. Traffic reaches the application only from this point, and only against a migrated
    schema. Run any approved staging seed/setup command once, now.
12. Deploy `can-eye-horizon` and `can-eye-scheduler` so both start against the migrated schema. Never run migrations from these services.
13. Exercise `/up`, then over `https://can-eye.mrwilde.dev` confirm the session cookie issued by `/login` is carried by the next request, login with a seeded
    account, database-backed sessions, monthly report aggregation, a queued job, Horizon metrics, scheduler output, a full
    bank-import round trip across the web and worker containers, and the approved external integrations. With `FORTIFY_REGISTRATION_ENABLED=false` the check
    for registration is the opposite of the others: `/register` must return **404** and `/` must render with no sign-up link. A reachable registration form
    here means the flag did not take effect, not that the deployment is healthy.
14. Confirm no MariaDB, Redis, Horizon dashboard, scheduler, or debug endpoint is publicly accessible.

### Redeployment and rollback

- Every subsequent deploy re-runs the entrypoint, so migrations are applied automatically. Snapshot MariaDB before any deploy that carries new migrations; the 51
  existing migrations include changes that are not cleanly reversible in practice.
- After deploying application code that queued workers must pick up, run `php artisan horizon:terminate` so supervisors restart on the new code. Redeploying
  `can-eye-horizon` satisfies this implicitly; a web-only deploy does not.
- Rollback is: redeploy the previous image in Dokploy, then restore the pre-deploy MariaDB snapshot if the failed deploy migrated the schema. Rolling code back
  without rolling the schema back is only safe for additive migrations.

## Verification checklist

- Readiness is a real page **plus** a session, never `/up` alone — see "`/up` is a dependency probe". Require `https://can-eye.mrwilde.dev/` and `/login`
  to render over the Let's Encrypt certificate, the `/login` response to set the session cookie, and a seeded login to persist across requests. The
  cross-request check only works over HTTPS because `SESSION_SECURE_COOKIE=true` marks the cookie `Secure`.
- `https://can-eye.mrwilde.dev/up` returns a healthy response and its `DiagnosingHealth` listener actually exercises the database and Redis. Treat this as a
  dependency probe, not as the readiness signal.
- Dokploy's deployment status is deliberately not on this checklist: it reads `done` once Swarm accepts the spec, so it is green on a crash-looping container —
  see "Dokploy `done` is not readiness". Evidence comes from the container log and `docker service ps --no-trunc`.
- Laravel reports `staging` and debug mode is disabled.
- Database migrations completed from the web container's entrypoint before it accepted traffic, and the web service can create and read a session.
- The application saw a real client IP and treated the request as secure — confirm trusted proxies are effective rather than assuming.
- Monthly report aggregation completes using the MariaDB-compatible `DATE_FORMAT` expression.
- A queued application job is processed by Horizon and its metrics appear in Redis-backed Horizon state.
- Scheduler logs show `horizon:snapshot` every five minutes and the schedules from `bootstrap/app.php` and `routes/console.php`.
- A CSV bank import uploaded through the web container is read successfully by the Horizon container — this is the specific flow that fails without shared
  storage, so upload alone is not sufficient evidence.
- Storage is verified by reading the file back, not by a successful write: every disk sets `'throw' => false`, so failures are silent.
- The Horizon dashboard is not reachable by a seeded account absent from `HORIZON_AUTHORIZED_EMAILS`. With registration disabled on staging there is no way
  to self-register a fresh account for this check, so seed one.
- `/register` returns 404 and `/` renders with no sign-up link, confirming `FORTIFY_REGISTRATION_ENABLED=false` reached the running container.
- No production Basiq, Redbark, Gmail, GitHub, mail, or storage credentials are present unless explicitly approved for staging.
- MariaDB and Redis have no external ports and no public domain.
- The service count is five: three Applications, one MariaDB/MySQL service, and one Redis.

### Container-verified behaviour

Observed on 2026-09-19 on an image built from this branch @95cf2cf against MariaDB 11.8 and Redis 7, with all three roles started with **no command and no
args** — the configuration the provisioning plan prescribes. The staging deploy should reproduce these results; a divergence is a finding, not noise.

| Role / subject                          | Observed                                                                                                                              |
|-----------------------------------------|---------------------------------------------------------------------------------------------------------------------------------------|
| Role argv                               | `docker inspect` reported `Cmd=[]` and `Entrypoint=[/usr/local/bin/entrypoint.sh]` on every role, and `ps` in the `horizon` container showed `php artisan horizon` — the no-argument dispatch path really ran |
| `web`                                   | Applied 51 migrations, then served `/up` 200, `/` 200, `/login` 200, `/register` 404, `/horizon` 403. `/login` answered with `laravel-session` marked `secure; httponly; samesite=lax`: in-container plain HTTP can only assert the cookie is *set*, because a client never resends a `Secure` cookie over `http://`, so the cross-request check belongs to step 13 over HTTPS |
| `horizon`                               | Master, supervisor and worker all ran as `www-data`; `horizon:liveness` exited 0 (`master ... is alive`); ran a `RunTransactionAnalysisJob` dispatched from the web container to DONE in 60ms |
| `scheduler`                             | `scheduler:heartbeat` advanced the heartbeat mtime across a 120s window, and the container reported healthy after the 90s start period |
| Shared volume                           | A fresh named volume mounted at `/var/www/html/storage/app/private` came up `www-data`-owned via copy-up; a file written by `www-data` in the `horizon` container was visible from `web` |
| `APP_DEBUG=true`, `BOOST_ENABLED` unset | Did **not** break `route:cache`: the container booted healthy with `bootstrap/cache/routes-v7.php` written, `route:list --json` parsing and `/` serving 200. That earlier concern is disproved. `APP_DEBUG=false` still stands, for stack-trace exposure on a public domain |

### Staging-verified behaviour: Sentry (#433)

Observed on 2026-09-20 on the live staging deployment, image built from `develop` @`2ee98d6`, all three services carrying the same `SENTRY_DSN`.

| Subject                     | Observed                                                                                                                              |
|-----------------------------|---------------------------------------------------------------------------------------------------------------------------------------|
| Environment tag             | The SDK resolved `environment='staging'` in both the `web` and `horizon` containers with no `SENTRY_ENVIRONMENT` set anywhere — `APP_ENV`, exported by `docker/entrypoint.sh`, is the only source. Local events tag `local` from the same DSN, so the two environments separate themselves |
| `web` — SDK reachable       | `php artisan sentry:test` reported `DSN discovered` and sent event `efde8019aa5b4fc9b026b6dd1addf69e`                                  |
| `web` — real exception path | `report(new RuntimeException(...))` through the new `withExceptions` hook produced event `0e8fdca7ca2c4c93809293a1149d22c9`. This is the load-bearing check: it exercises the handler, not merely the transport |
| `horizon` — worker path     | A closure job dispatched to `redis@default` was picked up by the live Horizon worker and recorded in `queue:failed`. An in-process `queue:work --once` run of the same failing job then returned event `0f878f05e25348b0ada74cbe91e583c7` with `environment='staging'`, proving the worker's exception path reaches Sentry rather than only failing the job |
| Policy switches at runtime  | `send_default_pii=false`, `traces_sample_rate=NULL`, `breadcrumbs.sql_bindings=false`, `tracing.sql_bindings=false`, `enable_logs=false` — read back from the resolved SDK options inside the container, not from the repository file |
| Healthchecks unaffected     | All three containers stayed `running (healthy)` through the rollout; `/up` 200, `/` 200, `/login` 200, `/register` 404, `/horizon` 403 |
| Probe cleanup               | Both probe failures were removed with `queue:flush` (`No failed jobs found` afterwards) and no probe files remain in any container. The baseline was zero failed jobs, so nothing real was discarded |

`SENTRY_DSN` was applied through the `application.saveEnvironment` API endpoint. The Dokploy **MCP** wrapper for that endpoint returns HTTP 400 for every
payload — it omits the `buildArgs`, `buildSecrets` and `createEnvFile` fields the endpoint requires — so the call was made directly against the API with those
three fields echoed back verbatim from each application's current record. `application.update` was deliberately **not** used: it rewrites the whole
application record, and a nulled `command`/`args` would replace the image `ENTRYPOINT` and bypass the role dispatch #430 introduced. After the write, each
service was confirmed to have gained exactly one variable, with every other variable byte-identical and `command`/`args` still empty.

## Open prerequisites

Prerequisite 1 is satisfied; 2 onward are still outstanding.

1. Satisfied: #369, #370, #371, #372 and #383 are merged to `develop`, the branch Dokploy builds. #383 landing is what makes prerequisite 3 effective —
   without its config gate the variable set there would have nothing to read it. The worker services additionally rely on #430's entrypoint dispatch —
   verify the built image as described under "Step 0".
2. Create the shared import volume in Dokploy and mount it at `/var/www/html/storage/app/private` on both `can-eye-web` and `can-eye-horizon`. The storage
   arrangement itself is settled — shared volume, `FILESYSTEM_DISK=local`. Never adopt `FILESYSTEM_DISK=s3`: `league/flysystem-aws-s3-v3` is absent from
   `composer.lock` and the import path calls `Storage::disk('local')->path()` directly.
3. Set `FORTIFY_REGISTRATION_ENABLED=false` on the staging service. The controls here are not symmetrical, so treat them differently: the Horizon gate
   (#370) **fails closed** — leave `HORIZON_AUTHORIZED_EMAILS` unset and nobody outside `local` gets in, so populating it grants access. The registration
   toggle (#383) **fails open**: it defaults to `true`, so omitting the variable leaves public sign-up enabled on the domain. `SESSION_SECURE_COOKIE` sits in
   that same fail-open class — `config/session.php` carries no default of its own — so both of those must be set explicitly to be safe. `CACHE_STORE` and
   `APP_KEY` are a separate category again: first-boot preconditions, covered in step 8 of the deployment sequence.
4. Confirm Dokploy's managed MariaDB service version, data-volume path, internal hostname, and authentication fields.
5. Confirm the Dokploy internal Redis hostname and supported managed Redis version/authentication fields.
6. Choose the mail sandbox and external integration policy, and decide whether staging should exercise Basiq/Redbark/Gmail/GitHub integrations or keep them
   disabled until credentials and callback URLs are approved.
7. After the source is accessible, inspect the reference `compare-build` Dockerfiles directly in Dokploy or GitHub and reconcile entrypoints, health checks, and
   environment names before creating the services.
8. If PostgreSQL is required later, first add a PostgreSQL branch to `ReportAggregator::monthExpression()` and run the complete report test module before
   changing `DB_CONNECTION`.
