# Repository Guidelines

CanEye Budget v2 (`mrwilde/can-eye-budget`) — an Australian personal-budgeting app. This file orients an AI assistant; it complements `CLAUDE.md` / `CLAUDE.local.md` (the workflow bible) and `docs/plans/*`.

## Project Overview

Personal budgeting around a **pay-cycle**. Users feed bank transactions in via three paths — CSV statement import (`league/csv`), Basiq open-banking sync (`au-api.basiq.io`), and manual entry — then the app **reconciles** posted transactions against **planned/recurring** transactions and renders a calendar + 12-month balance forecast + pay-cycle dashboard. Server-rendered Laravel 12 monolith (no SPA): Livewire 4 full-page components + Flux 2 UI + Tailwind v4. Money is stored as **integer cents** everywhere.

## Architecture & Data Flow

**Ingress → Ingestor → Reconcile → Event → (queue) Pipeline → Calendar/Projection.**

1. **Unified ingress** — every posted transaction funnels through `App\Services\TransactionIngestor::ingest(Transaction)` (`app/Services/TransactionIngestor.php`). Callers: `ImportCsvTransactionsJob`, `SyncTransactionsJob`, `Livewire/TransactionModal`. It saves the row, returns early if already linked (`planned_transaction_id !== null`), else reverse-matches a plan → `link()` + `event(TransactionReconciled)` or `event(TransactionEntered)`.
2. **Reconciliation** — `App\Services\ReconciliationPolicy` is the single source of truth (static helpers, `AMOUNT_TOLERANCE = 0.10`, `DATE_TOLERANCE_DAYS = 3`). Three consumers share it so they never disagree: ingress (`ReconciliationMatcher::findPlanForTransaction`), async backstop (`PlannedTransactionMatcher::matchForUser`, `LOOKBACK_DAYS = 45`, greedy 1:1, ties→null), and calendar dedup (`DayActivityLoader`). Reconciling synchronously at ingress is what prevents a planned pip and an entered pip on the same calendar day.
3. **Lifecycle events** (`app/Events/`): `TransactionEntered`, `TransactionReconciled`, `PlannedTransactionCreated` are emitted but have **no listeners** — deliberate seams. The events that DO have auto-discovered listeners are `TransactionCategoryUpdated` → `PropagateTransactionCategory` and `PlannedTransactionCategoryUpdated` → `PropagatePlannedTransactionCategory` (fired from model `booted()` hooks; no `EventServiceProvider` — Laravel listener auto-discovery).
4. **Analysis pipeline** — import/sync jobs end with `RunTransactionAnalysisJob::dispatch($user)` → `App\Services\TransactionAnalysisPipeline::run()`. Ordered stages (wired in `AppServiceProvider::register`): `IdentifyPrimaryAccountStage` → `SetPayCycleStage` → `IdentifyRecurringTransactionsStage` (feature-flagged off) → `UserRulesStage` → `MatchPlannedTransactionsStage`. Each stage writes a `PipelineAuditEntry`; suggestions land in `AnalysisSuggestion`.
5. **Read side** — `App\Support\Calendar\DayActivityLoader::load()` (calendar pips, suppresses a planned pip when a posting reconciles it) and `App\Services\Projection\MonthlyProjectionService::forUser()` (running balance from `primaryAccount->balance`, records `firstNegativeDate`).

## Key Directories (`app/`)

| Dir | Purpose | Reps |
|---|---|---|
| `Services/` | Stateless `final readonly` business logic (the engine) | `TransactionIngestor`, `ReconciliationMatcher`, `IncomePatternDetector` |
| `Services/PipelineStages/` | 5 `PipelineStageContract` impls | `SetPayCycleStage` |
| `Services/CsvImport/` | CSV parse/map | `CsvParserService`, `CsvColumnMapper` |
| `Services/Projection/` | Balance forecast | `MonthlyProjectionService`, `BalanceProjection` |
| `Livewire/` | UI components (full-page + modal) | `TransactionModal` (~907 lines, most-tested), `CalendarView`, `Dashboard/` |
| `Models/` | 13 Eloquent models | `Transaction`, `PlannedTransaction`, `User` |
| `Enums/` | ~24 backed enums carrying behaviour | `RecurrenceFrequency`, `TransactionSource` |
| `Support/` | Framework-agnostic helpers | `Calendar/DayActivityLoader`, `Recurring/MerchantSignature`, `AmountParser` |
| `DTOs/` | Spatie LaravelData `Dto`s | `PipelineContext`, `StageResult`, `IncomePattern` |
| `Jobs/` | Queued (`ShouldQueue`+`ShouldBeUnique`+`WithoutOverlapping`) | `SyncTransactionsJob`, `ImportCsvTransactionsJob` |
| `Events/` · `Listeners/` | Decoupling seams + category propagation | `TransactionReconciled` · `PropagateTransactionCategory` |
| `Casts/` | `MoneyCast` (int cents ↔ value, `format()`) | — |
| `Actions/`, `Contracts/`, `Providers/`, `Http/`, `Console/Commands/` | Invokables, DI interfaces, providers, controllers/middleware, artisan | `CreateNewUser`, `BasiqServiceContract` |

## Development Commands

**All tooling runs inside DDEV. NEVER run `php`/`artisan`/`composer`/`npm`/`vendor/bin/*` directly.** Use `op` (OpCode) aliases from `op.conf` (they wrap `ddev exec`); `ddev exec …` is the fallback.

```bash
op test                       # full suite  → ddev exec php artisan test --compact
op test.filter <name>         # single test → ... --filter="<name>"
op test.unit | test.feature | test.browser    # one suite (--testsuite=…)
op test.coverage | test.parallel | test.mutate # coverage / --parallel / --mutate --min=85
op lint                       # pint --parallel (write)   ;  op lint.dirty = pint --dirty
op check.dirty                # pint + phpstan on changed PHP
op analyse                    # phpstan (memory 512M)
op ci                         # lint.check + mago.lint + analyse + test
op migrate[.fresh|.rollback|.status]   # DB
op seed | seed.basiq | seed.sandbox    # seeders
op build                      # ddev exec npm run build (Vite)
op horizon | op logs          # queues / pail tail
```
`op test*` are **flock-guarded single-flight** (`/tmp/op-test.lock`) — never background or stack them. First-run bootstrap: `ddev composer setup`. Host-level composer scripts also exist (`composer dev/test/lint`) but `op`/`ddev exec` is the project convention.

## Code Conventions & Common Patterns

Enforced by `pint.json` (preset `laravel` + strict ruleset) and `tests/Arch.php`:

- **Always** `declare(strict_types=1)`, `final` classes, explicit return types, constructor property promotion, curly braces, `strict_comparison`, `date_time_immutable`, `global_namespace_import`, `mb_str_functions`. Class element order: traits → cases → constants → properties → constructor → magic → methods.
- **Service objects**: `final readonly class` with promoted private deps. Stateless policy/util → static helpers (`ReconciliationPolicy`, `MerchantSignature::for()`, `MoneyCast::format()`). Arch rule: `App\Services` must be `final`.
- **DI**: constructor injection for services/jobs; **method injection** in Livewire actions/`render()`, controllers, and job `handle()` — e.g. `acceptPrimaryAccount(int $id, SuggestionApplier $applier)`. Pass explicit `User`, not `auth()`, into shared appliers.
- **Money = integer cents** (Arch rule: `App\Models` must not use `floatval`). `MoneyCast` coerces get/set; arithmetic in cents; Basiq strings via `(int) bcmul($amount, '100', 0)`; Blade gets `'formatMoney' => MoneyCast::format(...)`.
- **Enums carry behaviour** (Arch rule: string-backed): `RecurrenceFrequency::nextOccurrence()`, `TransactionSource::forAnalysis()` (`[Basiq, Csv]`), `PayFrequency::toRecurrenceFrequency()`, `BankImportStatus::isTerminal()`. Rule engine drives off `RuleTriggerField`/`Operator`/`RuleActionType` via `match`.
- **DTOs**: Spatie `LaravelData\Dto` (Arch rule: `App\DTOs` extend `Dto` + `final`) for transport; plain `final readonly` value objects (`PayCyclePip`, `BalancePoint`, `ParsedTransactionDto`) for views.
- **Livewire**: `#[Computed]` derived props, `#[On('event')]` listeners, `#[Locked]` tamper-proof props, `#[Validate]` inline rules; bust computed cache with `unset($this->prop)` (annotate `@phpstan-ignore property.notFound`); `placeholder()` skeleton for `lazy`; cross-component `$this->dispatch('transaction-saved')`; `Flux::toast(...)`.
- **Queries**: composable scopes (`Transaction::query()->current()->excludingTransfers()`); memory-safe back-application via `->lazyById()->each(...)`. Prefer `Model::query()` over `DB::`; `config()` not `env()`.
- All dates are `CarbonImmutable` (`Date::use(CarbonImmutable::class)` in `AppServiceProvider`). Timezone `Australia/Brisbane`.

## Important Files

- Entry points: `routes/web.php` (`Route::view` full-page Livewire: dashboard/calendar/transactions/accounts/rules; `POST webhooks/basiq` → `BasiqWebhookController`; `GET basiq/callback`), `routes/settings.php` (`Route::livewire(..., 'pages::settings.*')`), `routes/console.php` (scheduled `basiq:fail-stuck-refresh-logs` every 5 min).
- Wiring: `app/Providers/AppServiceProvider.php` (singletons/aliases, **pipeline stage order**, view composers), `app/Providers/FortifyServiceProvider.php` (auth views `pages::auth.*`, 5/min limiters).
- Layout: `resources/views/layouts/app/sidebar.blade.php` — Flux sidebar + **globally mounts `<livewire:transaction-modal/>` and `<livewire:feedback-widget/>`** (open modal anywhere via `dispatch('open-transaction-modal', date)`).
- Frontend: `resources/css/app.css` (Tailwind v4 CSS-first + hand-rolled **CIB** design system: `cib-teal/yellow/black` tokens, `.cib-card`, signature `.cib-yellow-pill` "Add …" button), `resources/js/app.js` (only exposes ApexCharts + FeedbackPlus), `vite.config.js`.
- Config: `config/budget.php` (single flag `recurring_detection`, default **false**), `config/services.php` (Basiq + GitHub feedback), `config/horizon.php`.
- Docs of record (no README): `CLAUDE.md`, `CLAUDE.local.md`, `docs/plans/2026-06-05-transaction-reconciliation-lifecycle-design.md`, `docs/basiq-sandbox-setup.md`.

## Runtime/Tooling Preferences

- **PHP ^8.4** (DDEV `8.4`), `ext-bcmath`. **Laravel 12.56**, Livewire 4.2 + Flux 2.13, Fortify 1.36, Horizon 5.45, Spatie LaravelData 4.21, `league/csv` 9.28.
- **Package manager: npm** (`package-lock.json`; no pnpm/bun). Frontend: Vite 7 + Tailwind v4 (`@tailwindcss/vite`, no `tailwind.config.js`).
- **DDEV** dev env: nginx-fpm, MariaDB 11.8, Redis 7 addon (queues/cache/Horizon), web `mem_limit 4g`. Playwright Chromium auto-installed on `ddev start`.
- Quality gates: **Pint** (strict), **PHPStan/larastan level 6** (`paths: app, bootstrap, config, database/{factories,seeders}, routes` — **`tests/` not analysed**), **GrumPHP pre-commit** runs Pint `--test` then PHPStan. Rector is installed but unconfigured.

## Testing & QA

- **Pest 4** (`pestphp/pest` v4.4.6) over PHPUnit. (Note: the Boost block in `CLAUDE.md` says Pest 3 — stale; lockfile is authoritative.) Suites in `phpunit.xml`: `Unit`, `Feature`, `Browser`, `Arch` (`tests/Arch.php`).
- **Test DB** = SQLite `:memory:` (set inline in `phpunit.xml`; queue `sync`, cache/session/mail `array`, `BCRYPT_ROUNDS=4`). No `.env.testing` needed. `RefreshDatabase` applied to **Feature + Browser only** (via `tests/Pest.php`) — **Unit tests are pure, no DB**.
- **Factories**: relationships via `->for()` + named states — `User::factory()->withPayCycle()`, `Account::factory()->for($user)->creditCard()/csvImport()`, `Transaction::factory()->for($user)->for($account)->manual()->debit()`.
- **Date-sensitive tests**: freeze the clock — `$this->travelTo(CarbonImmutable::create(2026, 6, 15))` (usually in `beforeEach`) or `CarbonImmutable::setTestNow(...)` with explicit reset. Guards month-boundary flakiness.
- **Assertions are strict**: `expect()->toBe()` (identity) chained with `->and()`; money asserted as exact int cents (`->toBe(300000)`); enums `->toBe(TransactionDirection::Debit)`; Livewire `->assertSet()/assertHasErrors()/assertDispatched()`; artisan `->expectsOutputToContain()->assertSuccessful()`.
- **Browser** = Pest 4 browser plugin on **Playwright** (not Dusk): `visit('/path')->assertSee()->click(...)`, drive Livewire via `->script("Livewire.dispatch(...)")`, assert on stable hooks (`.cyc-pip.plan`, `[role="combobox"] input`). Files end `*BrowserTest.php`.
- **Run gates**: `op test` (or `op test.filter <name>` while iterating); CI gate `op ci`; mutation min score 85% (`op test.mutate`). When changing a method signature, grep all of `tests/` for callers.

## Workflow (must follow)

Issue-first: create a GitHub issue → branch `type/<issue>-slug` → commits `type(#issue): subject` (body explains *why* + verification, footer `Refs #<issue>` / `Closes #<issue>`) → **PR targets `develop`, never `main`** → request Copilot reviewer + apply one type label (`bug`|`enhancement`) + ≥1 layer label (`backend`|`frontend`), squash-merge into `develop`. `gh` account `robwilde`. Refute false-positive review suggestions with evidence rather than applying them. Write an Obsidian devlog after code changes. Infra/docs (`CLAUDE.md`, `.ddev/*`) commit directly to `develop`.
