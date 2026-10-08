# Audit trail design record

**Issue:** #363
**Date:** 2026-10-08
**Status:** As built (delivered by #562 and #563)

## Problem

#363 asked what the audit trail records, who is the actor, how long rows live, and how it relates to the existing domain logs. The implementation exists; this record states the answers and cites the code that enforces them.

## Decisions

### 1. What is audited: user entry points

One `audit_events` row per user entry point, never per touched model row. One user action can still produce more than one row: logout passes through `AttributeRequestToUser` (`http.logout`) and `AuditAuthentication` (`auth.logout`), and `AuditTrailTest` asserts both. Four entry points feed `App\Support\Audit\AuditRecorder`:

- **HTTP routes.** `App\Http\Middleware\AttributeRequestToUser` is appended to the `web` group (`bootstrap/app.php`). It records every authenticated request whose method is not safe and whose route is not `*livewire.update` (`isAuditable`). Action is `http.<route name>`, falling back to `http.<lowercase method>` when the route is unnamed or the name fails `AuditRecorder::accepts`. Outcome is `Failure` on an exception, a status >= 400, or a newly flashed `errors` bag (`succeeded`).
- **Livewire methods.** `App\Livewire\Hooks\AuditComponentCalls`, registered in `App\Providers\AuditServiceProvider::register()`, records every public method defined on the component subclass (`auditedAction`), as `livewire.<component name>.<method>`. Listener-dispatched methods are resolved through `listenerMethod`. Opt-out is the `#[NotAudited]` attribute (`app/Livewire/Attributes/NotAudited.php`); today only `ImportBank::pollStatus` and `TransactionList::pollMerchantBrands` use it. Outcome is `Failure` on an exception or when the component's error bag gained errors during the call (`addedErrors`).
- **Auth events.** `App\Listeners\AuditAuthentication` handles `Login`, `Logout`, `Failed`, `Registered`, `PasswordReset`, `Verified` and Fortify's `TwoFactorAuthenticationFailed`, recording `auth.login`, `auth.logout`, `auth.register`, `auth.password_reset` or `auth.email_verified`. `Failed` and `TwoFactorAuthenticationFailed` are recorded as `Failure` under `auth.login`.
- **Queued jobs.** `App\Support\Audit\AttributeJobToUser` (job middleware, constructed with a user id and an `audit` flag) records `job.<class basename>` on success (skipped when the job was released) and on exception. `ImportCsvTransactionsJob`, `RunTransactionAnalysisJob` and `SyncRedbarkFeedJob` audit; `EnrichMerchantBrandsJob` and `ResolveMerchantBrandJob` attribute the user to Sentry but do not audit.

Coverage is enforced by `tests/Feature/Audit/UserActionInventoryTest.php`: every callable Livewire method is audited or in the exempt list; every non-safe, non-infrastructure route is named, passes through `AttributeRequestToUser` and is in the inventory; every class under `app/Jobs` is in the job inventory with its declared audit behaviour.

Not covered: mutations that run without a user entry point (see Still open).

### 2. Actor

`audit_events.user_id` is a nullable foreign key with `nullOnDelete` (`database/migrations/2026_10_04_200000_create_audit_events_table.php`). Each entry point passes the actor explicitly (middleware and job middleware) or `AuditRecorder::payload` falls back to `Auth::id()`. The same id is bound to Sentry by `App\Support\Audit\SentryActor::bind` (user id only, plus the `request_id` tag). Jobs have no separate actor column: the job class is the `action` (`job.SyncRedbarkFeedJob`) and the owning user is `user_id`.

### 3. Retention and grouping

`config/audit.php` `retention_days` reads `AUDIT_RETENTION_DAYS`, default 365. `App\Models\AuditEvent` uses `MassPrunable`; `prunable()` selects rows older than that many days. `AuditServiceProvider::boot()` schedules `model:prune --model=AuditEvent` daily. `request_id` (a UUID put into `Context` by `AttributeRequestToUser`) is indexed and lets rows from one web request be grouped.

### 4. No before/after data, validated names

The table holds only `user_id`, `action`, `subject_type`, `subject_id`, `outcome`, `request_id`, `created_at`; there is no field for values or diffs. `AuditRecorder` rejects any action or subject type that does not match `NAME` (letters, `_ . : -`, digit runs of at most two, max 100 characters) and any subject id that is not an integer, UUID or ULID. That is a format restriction, not a privacy guarantee: a value such as `john.smith` still matches `NAME`. What keeps sensitive values out is the schema having no value column and callers passing fixed labels, which is an obligation on every new caller. `recordSafely` swallows and reports validation or sink failures instead of failing the user's request.

### 5. Hand-rolled recorder

`AuditRecorder::record` writes the same payload to three sinks: a database row (`storeRow`), a JSON line on the `audit` log channel (`writeLog`; `config/logging.php` `audit`, stream `AUDIT_LOG_STREAM`, default `php://stderr`) and a Sentry breadcrumb (`addBreadcrumb`, warning level for failures). `recordSafely` runs each sink in its own try/catch. The `mrpunyapal/laravel-auditor` package is a code-audit development tool, unrelated to this runtime trail.

### 6. Existing domain logs stay

`PipelineRun` and `PipelineAuditEntry` (per-pipeline-run stages and actions, keyed by `pipeline_run_id`), `BankImport` (status, counts, `row_errors`), `AnalysisSuggestion`, `RedbarkSyncLog` (per-sync result and `errors`, shown in the providers settings panel) and `BnplOrderEvent` (order lifecycle with a string `actor`) are unchanged. They describe domain progress; `audit_events` records which user triggered what, and with what outcome.

### 7. Purpose and limits

The trail is for debugging and reviewing what a user did. No application code or view reads `audit_events` (only `AuditEvent`, `AuditRecorder` and `AuditServiceProvider` reference the model), so there is no user-facing history. Rows are ordinary, mutable database rows with no hash chain or signature, so the trail is not tamper-evident and is not a compliance record. Sentry receives only the user id and breadcrumbs; the database is the system of record.

## Still open

Each needs its own issue if wanted:

- `subject_type` and `subject_id` exist in the schema and in `AuditRecorder::record`, but no caller passes them, so every row has both null.
- Mutations without a user entry point are not audited: the scheduled commands `app:expire-redbark-holds` and `app:scan-bnpl-emails` (`routes/console.php`) record nothing themselves, and `EnrichMerchantBrandsJob` and `ResolveMerchantBrandJob` do not audit. A scheduled Redbark sync is still recorded through `SyncRedbarkFeedJob`, which carries `AttributeJobToUser` with the feed's user id.
- No value or diff capture.
