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

**Step 0 is implemented on the working branch and awaits merge to `develop`.** The four blocking code changes now exist: a root production image
(`Dockerfile`, `.dockerignore`, `docker/`), a trusted-proxy call in `bootstrap/app.php`, an allow-listed Horizon gate
(`app/Providers/HorizonServiceProvider.php` + `config/horizon.php` `authorized_emails`), and `app/Listeners/VerifyHealthDependencies.php` so `/up` asserts
MariaDB and Redis. Bank-import storage is settled as a shared volume — a Dokploy provisioning step, not a code change. Do not begin the "Deployment sequence"
until those changes are merged to `develop`, because that is the branch every Application service builds.

## Step 0: raise the GitHub work before touching Dokploy

The GitHub work is raised in `robwilde/can-eye-budget-v2`. Parent tracking issue: **#375 "Staging deployment readiness (Dokploy)"** — links this document and
tracks the children below via a task list. Close it only when every blocking child is merged to `develop`.

Children: #369 (production image), #370 (Horizon gate), #371 (trusted proxies), #372 (`/up` dependencies) are blocking; #373 (dev-only dependencies) and #374
(CI gating for staging deploys) are non-blocking and remain open.

| # | Issue | Work item                                                               | Status | Scope                                                                                                                                                                                                                                                        |
|---|---|-------------------------------------------------------------------------|-----------|----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| 1 | #369 | Add production `Dockerfile`, `.dockerignore`, and container entrypoint   | Implemented, pending merge | Multi-stage Node asset build plus PHP 8.4 runtime; installs the extension set listed under "PHP extension requirements"; entrypoint runs migrations and caches config **before** the HTTP server binds; runs `storage:link` in the image, not by hand. Delivered as root `Dockerfile`, `.dockerignore`, `docker/entrypoint.sh`, `docker/nginx/default.conf`, `docker/supervisord.conf`, `docker/php.ini` |
| 2 | — | Provision the shared import volume                                      | Provisioning only | Mount one volume at `/var/www/html/storage/app/private` on both `can-eye-web` and `can-eye-horizon`. Settled — see "Storage decision (settled): shared volume". No repository change; `FILESYSTEM_DISK` stays `local`                                          |
| 3 | #370 | Restrict Horizon dashboard access outside `local`                       | Implemented, pending merge | Gate is now an explicit allow-list: `config('horizon.authorized_emails')` from `HORIZON_AUTHORIZED_EMAILS`, with `local` short-circuited. Fails closed when unset. Registration is still open — see "Security exposure decision"                              |
| 4 | #371 | Configure trusted proxies                                               | Implemented, pending merge | `bootstrap/app.php` now calls `$middleware->trustProxies(at: '*')` with the framework's default header set; correct because the container is only reachable through Traefik on the Dokploy network                                                            |
| 5 | #372 | Add a `DiagnosingHealth` listener that asserts database and Redis       | Implemented, pending merge | `app/Listeners/VerifyHealthDependencies.php` runs `select 1` and a Redis `ping`; auto-discovered from `app/Listeners`. `/up` now returns 500 when either dependency is unreachable, making it a usable Dokploy readiness gate                                 |
| 6 | #373 | Move development-only dependencies out of the production dependency set | Open, non-blocking | `laravel/boost` and `spatie/laravel-ray` sit in `require`, so `composer install --no-dev` still ships them; `playwright` sits in `dependencies` with an empty `devDependencies`, so `npm ci` pulls it into the build stage                                     |
| 7 | #374 | Decide whether staging deploys are gated on CI                          | Open, non-blocking | `.github/workflows/lint.yml` and `tests.yml` exist but nothing ties a staging deploy to them                                                                                                                                                                  |

Rows 1, 3, 4 and 5 (#369, #370, #371, #372) are release blockers: implemented on the working branch, not yet merged. Do not begin the "Deployment sequence" until
they are merged to `develop`, because that is the branch every Application service below is configured to build. Row 2 is a Dokploy provisioning step, not a merge
gate — but the volume must exist before the first bank import.

## Dokploy inventory

Captured from the Dokploy project inventory and service details:

- Dokploy contains 9 projects in the current organization.
- Reference project: `comparebuild` (`Bc6MlLuvuhhiZ9hVzCstL`), description `this is the doc portal for the project`.
- Reference staging environment: `staging` (`GtCAOGo5_32kZ0SkazCqp`).
- Reference staging has four services, all reported `done`:
    - Application `backend` (`q6L3rVbJlA35H-GXkuQjA`)
    - Application `frontend` (`Hvb0NgWmza7tE2PiU37Fb`)
    - Application `scheduler` (`de50uCfU75TcCqfihx2Mk`)
    - Managed PostgreSQL (`eVKzjC2hMINUmlAHRpa1G`)
- Reference staging has no Redis service and no external PostgreSQL port.
- No `can-eye` project, environment, application, database, Redis service, or domain was present in the inventory.

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

The root `Dockerfile`, `.dockerignore`, and `docker/` configs now exist on the working branch (there is still no `nixpacks.toml`, `Procfile`, or production
`docker-compose` file; `.ddev/` remains development-only). Point Dokploy at the root `Dockerfile` once #369 is merged to `develop`.

| Planned service     | Dokploy build mode    | Dockerfile/Nixpacks decision                                             | Prerequisite                                    |
|---------------------|-----------------------|--------------------------------------------------------------------------|-------------------------------------------------|
| `can-eye-web`       | Application           | Root `Dockerfile`; do not use Nixpacks                                   | #369 merged to `develop`                        |
| `can-eye-horizon`   | Application           | Reuse the same root `Dockerfile`; do not use Nixpacks                    | Same Dockerfile as `can-eye-web`                |
| `can-eye-scheduler` | Application           | Reuse the same root `Dockerfile`; do not use Nixpacks                    | Same Dockerfile as `can-eye-web`                |
| `can-eye-mariadb`   | Managed MariaDB/MySQL | Managed MariaDB 11.8 image; neither Nixpacks nor a repository Dockerfile | Create the managed database service and volume  |
| `can-eye-redis`     | Managed Redis         | Managed Redis 7.x image; neither Nixpacks nor a repository Dockerfile    | Create the managed Redis service                |

This is the cleanest match to the reference records, where all three Application records have `buildType=dockerfile` and `dockerfile=Dockerfile`. The one
authored Dockerfile should build the Vite assets and provide the PHP 8.4 HTTP runtime; the Horizon and scheduler services reuse that image and override only
their commands.

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
- `/up` is registered via `withRouting(health: '/up')`. The framework handler dispatches `DiagnosingHealth` and returns 200 unless a listener throws
  (`Illuminate\Foundation\Configuration\ApplicationBuilder`). `app/Listeners/VerifyHealthDependencies.php` (#372) is that listener: it runs `select 1` on the
  default connection and pings Redis, so `/up` returns 500 when MariaDB or Redis is unreachable instead of reporting healthy on a broken application. Note the
  scope: `select 1` proves connectivity, **not** schema state, so `/up` can still return 200 against a reachable but unmigrated database. Migration ordering is
  guaranteed by `docker/entrypoint.sh` running `migrate` before `exec`, not by this listener.
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

Still open, and deliberately out of scope for #370:

- `config/fortify.php:149` enables `Features::registration()`, so registration remains open on the public domain. Consider disabling it on staging and seeding
  accounts instead.
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
  build path/context, `buildType=dockerfile`, and `dockerfile=Dockerfile`. This requires the root Dockerfile from #369 merged to `develop`; do not select Nixpacks.
- The image prerequisite is a multi-stage Node build that runs `npm ci` and `npm run build`; the runtime contains PHP 8.4, the extensions listed under "PHP
  extension requirements", Composer production dependencies, and an HTTP server listening on port 80.
- Serve the Laravel `public` directory. Do not run `npm run dev` in staging.
- Use one replica initially, matching the reference.
- Configure the Laravel environment listed in the environment matrix below.
- `storage:link` runs in `docker/entrypoint.sh` (`--force`, idempotent), never as a manual step: the container filesystem is ephemeral and a hand-run symlink is
  lost on every redeploy.
- Migrations run from the entrypoint before the HTTP server binds, gated on `RUN_MIGRATIONS=true` and taking a cache lock via `--isolated`, so the container
  cannot report ready on an unmigrated schema. Set `RUN_MIGRATIONS=true` on this service only.
- Define a health check against Laravel's `/up` route. #372 gives `/up` a `DiagnosingHealth` listener asserting MariaDB and Redis, so it is now a real readiness
  gate: it returns 500 when either dependency is unreachable. The image also declares an equivalent `HEALTHCHECK` with a 90s start period.
- Attach `can-eye.mrwilde.dev` to port 80 with HTTPS and Let's Encrypt after the first migration succeeds.

### Service: can-eye-horizon

- Create a second Application service named `can-eye-horizon` from the same repository, owner, branch, root build path/context, `buildType=dockerfile`, and the
  same root `Dockerfile`; do not select Nixpacks.
- Use one replica.
- Override the container command with `php artisan horizon`.
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
- Override the container command with `php artisan schedule:work`, matching the reference scheduler service.
- Do not attach a domain or external port.
- Reuse the same application, database, Redis, and storage environment values as the web service.
- Note that two of the three schedules in `routes/console.php` are `Schedule::call()` closures that execute in this container and write to the database directly,
  so this service needs working database credentials, not just Redis.
- Confirm scheduler logs show the five-minute Horizon snapshot and the application schedules, including Redbark synchronization and hold expiry.
- Deploy this service only after `can-eye-web` is deployed and migrations have run, so scheduled commands never hit an unmigrated schema.

## Environment matrix

Set the common application environment on all three Application services. Values below are names and policies, not credentials:

| Variable                                                                 | Staging value/policy                                                                                                                     |
|--------------------------------------------------------------------------|------------------------------------------------------------------------------------------------------------------------------------------|
| `APP_NAME`                                                               | `Can Eye Budget (Staging)`                                                                                                               |
| `APP_ENV`                                                                | `staging`                                                                                                                                |
| `APP_KEY`                                                                | Generate a new staging key; never reuse another environment's key                                                                         |
| `APP_DEBUG`                                                              | `false`                                                                                                                                  |
| `APP_URL`                                                                | `https://can-eye.mrwilde.dev`                                                                                                            |
| `APP_TIMEZONE`                                                           | `Australia/Brisbane`                                                                                                                     |
| `LOG_CHANNEL` / `LOG_STACK`                                              | Keep the repository's supported stack; send logs to Dokploy/container logging                                                             |
| `DB_CONNECTION`                                                          | `mariadb` — must be set explicitly; the repository default is `sqlite`                                                                    |
| `DB_HOST` / `DB_PORT`                                                    | Dokploy MariaDB internal hostname / `3306`                                                                                               |
| `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD`                            | Staging-only MariaDB values                                                                                                              |
| `SESSION_DRIVER`                                                         | `database`                                                                                                                               |
| `SESSION_SECURE_COOKIE`                                                  | `true` — the domain is HTTPS-only                                                                                                        |
| `TRUSTED_PROXIES`                                                        | Not required: `bootstrap/app.php` calls `trustProxies(at: '*')` (#371). Only set this if the topology stops being Traefik-only              |
| `QUEUE_CONNECTION`                                                       | `redis`                                                                                                                                  |
| `CACHE_STORE`                                                            | `redis`                                                                                                                                  |
| `REDIS_CLIENT`                                                           | `phpredis`; the image must install `ext-redis`                                                                                            |
| `REDIS_HOST` / `REDIS_PORT` / `REDIS_PASSWORD`                           | Dokploy Redis internal hostname / `6379` / staging-only credential                                                                        |
| `REDIS_PREFIX`                                                           | Connection-wide key prefix; defaults to `slug(APP_NAME)-database-`. Set explicitly so staging cannot collide with another app             |
| `CACHE_PREFIX`                                                           | Unique staging prefix, for example `can_eye_staging`                                                                                     |
| `HORIZON_PREFIX`                                                         | Unique staging prefix, for example `can_eye_staging_horizon:`                                                                             |
| `HORIZON_NAME`                                                           | `can-eye-staging-horizon` on the Horizon service                                                                                         |
| `HORIZON_PATH`                                                           | Optional defence in depth now the gate is an allow-list; the default is `horizon`                                                          |
| `HORIZON_AUTHORIZED_EMAILS`                                              | Comma-separated allow-list of emails permitted to open the dashboard outside `local`. Empty means nobody can — fails closed                |
| `RUN_MIGRATIONS`                                                         | `true` on `can-eye-web` **only**; unset (defaults `false`) on `can-eye-horizon` and `can-eye-scheduler` so only one service migrates       |
| `FILESYSTEM_DISK`                                                        | `local`, paired with the shared volume at `storage/app/private` — see "Storage decision (settled): shared volume". Never set `s3`          |
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
| `RAY_ENABLED`                                                            | `false` on all three services. `ray.php` defaults it to `true`, so Ray is on in staging unless this is set: 18 watchers per request and per queued job, and a 2s-timeout curl to `RAY_HOST:RAY_PORT` on every log line and exception. Those default to `host.docker.internal` and `23517` (`ray.php:114`, `ray.php:119`), neither of which resolves on a Dokploy container |
| `BOOST_ENABLED`                                                          | `false` on all three services. Boost activates on `local` **or** `APP_DEBUG=true`, and publishes an unauthenticated, CSRF-exempt `POST /_boost/browser-logs` plus a JS-injecting `web` middleware. Setting it `false` removes that route only — it does not make `APP_DEBUG=true` safe on a public domain, where the debug error page still exposes stack traces, configuration and query bindings. `APP_DEBUG` stays `false` |

Repository defaults are adequate for `APP_LOCALE`, `SESSION_LIFETIME`, `BROADCAST_CONNECTION`, `BCRYPT_ROUNDS`, and `LOG_LEVEL`.

### Secret handling

Generate all staging secrets in Dokploy's environment-variable storage. Do not paste values from the reference service output into this plan or into another
environment. Any live-looking credentials previously exposed in Dokploy output should be rotated in their owning system.

## Deployment sequence

Steps 1 and 2 are the gate. Do not start step 3 until step 2 is complete.

1. Done: parent issue #375 and children #369–#374 exist in `robwilde/can-eye-budget-v2`.
2. Merge #369, #370, #371 and #372 to `develop`. Confirm the branch contains the root `Dockerfile`, `docker/entrypoint.sh` migrating before serving,
   `trustProxies` in `bootstrap/app.php`, the allow-listed Horizon gate, and `app/Listeners/VerifyHealthDependencies.php`. #373 and #374 do not gate the deploy.
3. Confirm the GitHub repository is accessible to Dokploy.
4. Create project `can-eye-budget-v2` and environment `staging`.
5. Create and deploy MariaDB and Redis; wait for both services to report ready and record their internal hostnames.
6. Create the shared import volume (settled decision) before any Application is deployed.
7. Create `can-eye-web`, `can-eye-horizon`, and `can-eye-scheduler` from the same source and Dockerfile. Keep one replica for each initially and do not deploy
   any Application yet. Mount the shared volume on `can-eye-web` and `can-eye-horizon`.
8. Set common environment variables and service-specific command/name values. Set `APP_DEBUG=false`, `DB_CONNECTION=mariadb`, `RUN_MIGRATIONS=true` on `can-eye-web` only, and `HORIZON_AUTHORIZED_EMAILS` before the first deployment.
9. Deploy `can-eye-web` with **no domain attached**. The entrypoint runs `php artisan migrate --force` before binding the HTTP port, so the service only reports
   ready once the schema exists. Take a MariaDB snapshot before this first migration.
10. Confirm readiness genuinely: `/up` must be green *with* the database/Redis listener active, and the container log must show the migration completing. `/up`
    returning 200 on its own proves only that PHP booted.
11. Attach `can-eye.mrwilde.dev` to `can-eye-web` with HTTPS/Let's Encrypt. Traffic reaches the application only from this point, and only against a migrated
    schema. Run any approved staging seed/setup command once, now.
12. Deploy `can-eye-horizon` and `can-eye-scheduler` so both start against the migrated schema. Never run migrations from these services.
13. Exercise `/up`, registration/login, database-backed sessions, monthly report aggregation, a queued job, Horizon metrics, scheduler output, a full bank-import
    round trip across the web and worker containers, and the approved external integrations.
14. Confirm no MariaDB, Redis, Horizon dashboard, scheduler, or debug endpoint is publicly accessible.

### Redeployment and rollback

- Every subsequent deploy re-runs the entrypoint, so migrations are applied automatically. Snapshot MariaDB before any deploy that carries new migrations; the 51
  existing migrations include changes that are not cleanly reversible in practice.
- After deploying application code that queued workers must pick up, run `php artisan horizon:terminate` so supervisors restart on the new code. Redeploying
  `can-eye-horizon` satisfies this implicitly; a web-only deploy does not.
- Rollback is: redeploy the previous image in Dokploy, then restore the pre-deploy MariaDB snapshot if the failed deploy migrated the schema. Rolling code back
  without rolling the schema back is only safe for additive migrations.

## Verification checklist

- `https://can-eye.mrwilde.dev/up` returns a healthy response over the Let's Encrypt certificate, **and** its `DiagnosingHealth` listener actually exercises the
  database and Redis.
- Laravel reports `staging` and debug mode is disabled.
- Database migrations completed from the web container's entrypoint before it accepted traffic, and the web service can create and read a session.
- The application saw a real client IP and treated the request as secure — confirm trusted proxies are effective rather than assuming.
- Monthly report aggregation completes using the MariaDB-compatible `DATE_FORMAT` expression.
- A queued application job is processed by Horizon and its metrics appear in Redis-backed Horizon state.
- Scheduler logs show `horizon:snapshot` every five minutes and the schedules from `bootstrap/app.php` and `routes/console.php`.
- A CSV bank import uploaded through the web container is read successfully by the Horizon container — this is the specific flow that fails without shared
  storage, so upload alone is not sufficient evidence.
- Storage is verified by reading the file back, not by a successful write: every disk sets `'throw' => false`, so failures are silent.
- The Horizon dashboard is not reachable by a freshly registered account.
- No production Basiq, Redbark, Gmail, GitHub, mail, or storage credentials are present unless explicitly approved for staging.
- MariaDB and Redis have no external ports and no public domain.
- The service count is five: three Applications, one MariaDB/MySQL service, and one Redis.

## Open prerequisites

1. Merge #369, #370, #371 and #372 to `develop`. This is the gating prerequisite for everything else; the changes exist on the working branch but Dokploy builds
   `develop`.
2. Create the shared import volume in Dokploy and mount it at `/var/www/html/storage/app/private` on both `can-eye-web` and `can-eye-horizon`. The storage
   arrangement itself is settled — shared volume, `FILESYSTEM_DISK=local`. Never adopt `FILESYSTEM_DISK=s3`: `league/flysystem-aws-s3-v3` is absent from
   `composer.lock` and the import path calls `Storage::disk('local')->path()` directly.
3. Decide whether `Features::registration()` stays enabled on a public staging domain, and populate `HORIZON_AUTHORIZED_EMAILS`. The Horizon gate itself is
   resolved (#370) and fails closed when that variable is unset.
4. Confirm Dokploy's managed MariaDB service version, data-volume path, internal hostname, and authentication fields.
5. Confirm the Dokploy internal Redis hostname and supported managed Redis version/authentication fields.
6. Choose the mail sandbox and external integration policy, and decide whether staging should exercise Basiq/Redbark/Gmail/GitHub integrations or keep them
   disabled until credentials and callback URLs are approved.
7. After the source is accessible, inspect the reference `compare-build` Dockerfiles directly in Dokploy or GitHub and reconcile entrypoints, health checks, and
   environment names before creating the services.
8. If PostgreSQL is required later, first add a PostgreSQL branch to `ReportAggregator::monthExpression()` and run the complete report test module before
   changing `DB_CONNECTION`.
