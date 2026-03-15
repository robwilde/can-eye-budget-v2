# Dev Log

## 2026-03-13 — Issue #1: Scaffold Laravel 12 with Livewire Starter Kit

### The Change

Completed the scaffolding verification and configuration on top of the initial Laravel 12 + Livewire starter kit install.

**Files modified:**
- `config/database.php` — Configured SQLite WAL mode, busy timeout (10s), synchronous normal
- `routes/web.php` — Added smoke-test route behind auth middleware

**Files created:**
- `app/Services/.gitkeep`, `app/DTOs/.gitkeep`, `app/Enums/.gitkeep` — Base directory structure
- `app/Livewire/SmokeTest.php` — Counter component (multi-file format)
- `resources/views/livewire/smoke-test.blade.php` — Smoke test Blade view
- `resources/views/smoke-test.blade.php` — Page wrapper using app layout
- `tests/Feature/SmokeTestComponentTest.php` — 3 Pest tests: renders, increments, auth guard

### The Reasoning

- **SQLite WAL mode**: Enables concurrent reads/writes critical for web apps. `busy_timeout=10000` prevents "database locked" errors. `synchronous=normal` balances performance and durability.
- **Multi-file component for SmokeTest**: The project uses view-based (⚡) SFC for full-page settings components, but standalone reusable components belong in `app/Livewire/` as separate classes — matching the existing `Logout` action pattern.
- **Directory structure**: `Services/`, `DTOs/`, `Enums/` created early so the team has clear conventions from day one.

### The Tech Debt

- None introduced. The smoke test component can be removed once real features are in place.

### Verification

- `npm run build` — Vite + Tailwind CSS v4 compiles cleanly
- `./vendor/bin/pest --compact` — 36 tests pass (82 assertions)
- `./vendor/bin/pint --dirty --format agent` — All PHP files pass formatting

## 2026-03-13 — Issue #2: Create Account Model, Migration, Factory, and Seeder

### The Change

Built the Account domain layer: enums, migration, model, factory, seeder, and full test coverage.

**Files created:**
- `app/Enums/AccountClass.php` — 9-case string-backed enum matching Basiq API types (kebab-case values)
- `app/Enums/AccountStatus.php` — 3-case string-backed enum (active, inactive, closed)
- `database/migrations/2026_03_13_000000_create_accounts_table.php` — accounts table with user FK (cascade delete), unique nullable basiq_account_id, 3-char currency default AUD, bigint balance in cents
- `app/Models/Account.php` — Eloquent model with fillable, enum casts, BelongsTo user relationship
- `database/factories/AccountFactory.php` — Default transaction state + 8 composable states (savings, creditCard, loan, mortgage, investment, withBasiq, inactive, closed)
- `database/seeders/AccountSeeder.php` — 6 diverse accounts for test@example.com user
- `tests/Feature/Models/AccountTest.php` — 17 feature tests covering factory states, relationships, cascade delete, enum casting, uniqueness
- `tests/Unit/Enums/AccountClassTest.php` — 3 unit tests for case count, backing values, from() resolution
- `tests/Unit/Enums/AccountStatusTest.php` — 2 unit tests for case count and backing values

**Files modified:**
- `app/Models/User.php` — Added `accounts(): HasMany` relationship
- `database/seeders/DatabaseSeeder.php` — Added `AccountSeeder::class` call after user creation

### The Reasoning

- **Cents as integers**: `bigInteger('balance')` stores cents to avoid floating-point precision issues in financial calculations. All downstream code must divide by 100 for display.
- **Enum casts**: Using PHP 8.1 backed enums with Laravel's `casts()` method provides type safety from database to application layer. Invalid values throw exceptions immediately rather than silently passing.
- **Composable factory states**: States like `withBasiq()` can be chained with any account type (`Account::factory()->savings()->withBasiq()->create()`), keeping test setup expressive and DRY.
- **Cascade delete on FK**: When a user is deleted, all their accounts are automatically cleaned up at the database level — no orphaned records.

### The Tech Debt

- None introduced. The `.gitkeep` in `app/Enums/` from Issue #1 can be removed now that real enum files exist.

### Verification

- `php artisan migrate:fresh --seed --no-interaction` — Migration and seeder run cleanly
- `php artisan test --compact` — 57 tests pass (124 assertions)
- `vendor/bin/pint --dirty --format agent` — All PHP files pass formatting

## 2026-03-13 — PR #29: Address Copilot Review Comments

### The Change

Created missing `phpstan-baseline.neon` file referenced by `phpstan.neon.dist`.

**Files created:**
- `phpstan-baseline.neon` — Empty baseline with `parameters: ignoreErrors: []` so PHPStan can load successfully

### The Reasoning

- **Copilot flagged 4 comments on PR #29.** After assessment: 1 was valid (missing baseline file), 1 was valid but correct as-is (nullable institution — intentional for Basiq API compatibility), 1 was invalid (Copilot wrong about `notPath` in pint.json), and 1 was cosmetic (OPCODE_SYNTAX.md scope).
- **Only the baseline fix required a code change.** Without this file, PHPStan would fail immediately on any `vendor/bin/phpstan analyse` invocation with a file-not-found error.

### The Tech Debt

- None introduced. PHPStan has 3 pre-existing errors in `app/Models/User.php` that should be addressed in a future session.

### Verification

- `vendor/bin/phpstan analyse --no-progress --memory-limit=512M` — Runs successfully (3 pre-existing errors, no config/baseline errors)

## 2026-03-14 — Issue #3: Create Transaction Model, Migration, Factory, and Seeder

### The Change

Built the Transaction domain layer with a prerequisite Category model, enums, migrations, factories, seeders, and full test coverage.

**Files created:**
- `app/Enums/TransactionDirection.php` — 2-case string-backed enum (debit, credit)
- `app/Enums/TransactionStatus.php` — 2-case string-backed enum (posted, pending)
- `database/migrations/2026_03_14_000000_create_categories_table.php` — Self-referencing categories with nullable parent_id FK (nullOnDelete)
- `database/migrations/2026_03_14_000001_create_transactions_table.php` — Full transaction schema: user/account/category FKs, amount in cents, direction, description, post_date, Basiq fields, enrich_data JSON, composite index on [user_id, post_date]
- `app/Models/Category.php` — Eloquent model with self-referencing parent/children relationships + transactions HasMany
- `app/Models/Transaction.php` — Eloquent model with enum casts, date casts, array cast for enrich_data, BelongsTo relationships
- `database/factories/CategoryFactory.php` — Default category + withParent() state
- `database/factories/TransactionFactory.php` — Default debit transaction with Australian merchant data + 5 states (debit, credit, withCategory, fromBasiq, pending)
- `database/seeders/CategorySeeder.php` — 12 parent categories with 30+ subcategories
- `database/seeders/TransactionSeeder.php` — 20 varied transactions for test user across multiple accounts
- `tests/Feature/Models/TransactionTest.php` — 22 feature tests covering factory validity, all states, relationships, cascade/null-on-delete, enum casting, date casting, JSON casting, unique basiq_id
- `tests/Unit/Enums/TransactionDirectionTest.php` — 3 unit tests
- `tests/Unit/Enums/TransactionStatusTest.php` — 3 unit tests

**Files modified:**
- `app/Models/User.php` — Added `transactions(): HasMany` relationship
- `app/Models/Account.php` — Added `transactions(): HasMany` relationship
- `database/seeders/DatabaseSeeder.php` — Added CategorySeeder and TransactionSeeder calls

### The Reasoning

- **Categories as prerequisite**: The transaction schema specifies `category_id` as a FK to `categories`. Creating a minimal Category model/migration first keeps the FK constraint valid and avoids tech debt of a missing reference.
- **Consistent FK behaviour**: `user_id` and `account_id` use `cascadeOnDelete` (matching Account pattern — when the owner goes, so do their transactions). `category_id` uses `nullOnDelete` because categories are classification metadata, not ownership — deleting a category shouldn't delete transactions.
- **Factory user consistency**: The TransactionFactory creates `$user` once and passes it to both `user_id` and `Account::factory()->for($user)`, ensuring the transaction's user and its account's user are always the same entity.
- **Composite index [user_id, post_date]**: Most budget queries will be "show me my transactions for this date range" — this index makes that query efficient.

### The Tech Debt

- None introduced.

### Verification

- `php artisan migrate:fresh --seed --no-interaction` — All 7 migrations and 3 seeders run cleanly
- `php artisan test --compact` — 85 tests pass (170 assertions)
- `vendor/bin/pint --dirty --format agent` — All PHP files pass formatting

## 2026-03-14 — Create comprehensive op.conf for Can Eye Budget V2

### The Change

Created a full `op.conf` with 35 command aliases organized into 7 sections.

**Files created/overwritten:**
- `op.conf` — Complete OpCode configuration with Testing (6), Code Quality (7), Database (6), Development (5), Artisan Helpers (5), Assets (3), and DDEV (6) commands

### The Reasoning

- **`ddev exec` prefix on all Laravel/PHP commands**: Ensures commands run inside the DDEV container where PHP, Composer, and Node are available. Host-level DDEV commands (`start`, `stop`, `launch`) run without the prefix.
- **`op` chaining in `ci` and `clear`**: Composite commands reference other op codes so changes to individual commands propagate automatically.
- **`#?` usage comments**: Every command has a description, and `make.*` commands include usage examples so `op ?` serves as a self-contained reference.
- **Dot-separated naming**: Groups related commands visually (`test.filter`, `migrate.fresh`, `lint.dirty`) while keeping tab-completion useful.

### The Tech Debt

- None introduced.

### Verification

- `op ?` — All 7 sections render with descriptions and usage hints
- `op -l` — All 35 commands listed

## 2026-03-15 — Issue #5: Create Budget Model, Migration, Factory, and Seeder

### The Change

Built the Budget domain layer: BudgetPeriod enum, migration, model, factory, seeder, and full test coverage.

**Files created:**
- `app/Enums/BudgetPeriod.php` — 3-case string-backed enum (Monthly, Weekly, Yearly)
- `database/migrations/2026_03_14_153216_create_budgets_table.php` — budgets table with user FK (cascadeOnDelete), nullable category FK (nullOnDelete), limit_amount in cents, period, start/end dates, composite index on [user_id, period]
- `app/Models/Budget.php` — Eloquent model with enum/date/integer casts, BelongsTo user & category, HasMany transactions (linked through shared category_id), `remaining()` method
- `database/factories/BudgetFactory.php` — Default monthly budget + 6 states (withCategory, monthly, weekly, yearly, overBudget, underBudget)
- `database/seeders/BudgetSeeder.php` — 4 sample budgets for test@example.com (Groceries $800, Fuel $300, Takeaway $150, Electricity $250)
- `tests/Feature/Models/BudgetTest.php` — 19 feature tests covering factory, relationships, cascade/null-on-delete, casts, remaining() calculation, factory states
- `tests/Unit/Enums/BudgetPeriodTest.php` — 3 unit tests for case count, backing values, from() resolution

**Files modified:**
- `app/Models/User.php` — Added `budgets(): HasMany` relationship
- `app/Models/Category.php` — Added `budgets(): HasMany` relationship
- `database/seeders/DatabaseSeeder.php` — Added `BudgetSeeder::class` call after TransactionSeeder

### The Reasoning

- **transactions() via shared category_id**: `HasMany(Transaction::class, 'category_id', 'category_id')` links budgets to transactions through their shared category rather than a direct budget_id FK on transactions. Keeps the transaction table clean and naturally groups spending by category.
- **overBudget/underBudget factory states**: Use `afterCreating` callbacks to create transactions with calculated amounts, producing deterministic remaining() values for test assertions.
- **Composite index [user_id, period]**: Budget lookups will typically filter by user and period type.

### The Tech Debt

- `remaining()` does not scope by date range — needs period-aware filtering for production use.
- ~~`remaining()` sums all transactions for the category regardless of user~~ — Fixed in PR #32 review.

### Verification

- `op test.filter BudgetTest` — 19 tests pass (28 assertions)
- `op test.filter BudgetPeriodTest` — 3 tests pass (7 assertions)
- `op test` — 121 tests pass (240 assertions), full suite green
- `op lint.dirty` — All PHP files pass formatting

## 2026-03-15 — Issue #7: Eager Loading Scopes and Relationship Traversal Tests

### The Change

Added `withRelations` scopes and feature tests for relationship traversals across all models.

*(Completed in prior sessions — see commits 6289832, 1a4b832, 21e4a82)*

## 2026-03-15 — Issue #8: Implement Integer-Cents Money Accessors

### The Change

Created a reusable `MoneyCast` Eloquent cast to centralise money column handling, replacing ad-hoc `'integer'` casts on all money columns.

**Files created:**
- `app/Casts/MoneyCast.php` — Custom `CastsAttributes` implementation with `get()`/`set()` (int coercion) and `static format(int $cents): string` using pure integer arithmetic (no floats)
- `tests/Unit/Casts/MoneyCastTest.php` — 11 unit tests: cast get/set, format edge cases (zero, negative, large, padded cents), and model integration assertions

**Files modified:**
- `app/Models/Account.php` — `'balance' => MoneyCast::class`
- `app/Models/Transaction.php` — `'amount' => MoneyCast::class`
- `app/Models/Budget.php` — `'limit_amount' => MoneyCast::class`

### The Reasoning

- **Behaviour-preserving refactor**: `MoneyCast::get()` and `set()` do `(int) $value` — identical to the built-in `'integer'` cast — so all existing code and tests continue working without changes.
- **`format()` uses pure integer arithmetic**: `intdiv()` + `%` avoids floating-point entirely. `number_format()` is called with an integer argument (no decimal places) so it only adds thousand separators.
- **Single source of truth**: Any future money formatting, validation, or conversion logic has one place to live rather than being scattered across Blade views or controllers.

### The Tech Debt

- None introduced. The `format()` method is available but not yet consumed by any views — that will come when UI components are built.

### Verification

- `op test.filter MoneyCastTest` — 11 tests pass (11 assertions)
- `op test.filter AccountTest` — 16 tests pass (26 assertions)
- `op test.filter TransactionTest` — 22 tests pass (36 assertions)
- `op test.filter BudgetTest` — 20 tests pass (29 assertions)
- `op test` — 150 tests pass (295 assertions), full suite green
- `op lint.dirty` — Pint fixed import ordering and style, re-verified all tests pass

## 2026-03-16 — Issue #9: Create Domain Enums

### The Change

Completed the domain enum layer: added missing `SyncStatus` enum, added `Fortnightly` case to `BudgetPeriod`, normalized `declare(strict_types=1)` across all enum files, and added corresponding tests and factory states.

**Files created:**
- `app/Enums/SyncStatus.php` — 4-case string-backed enum (Pending, InProgress, Completed, Failed) with kebab-case backing values matching `AccountClass` convention
- `tests/Unit/Enums/SyncStatusTest.php` — 3 unit tests for case count, backing values, from() resolution

**Files modified:**
- `app/Enums/AccountClass.php` — Added `declare(strict_types=1)` to match other enums
- `app/Enums/AccountStatus.php` — Added `declare(strict_types=1)` to match other enums
- `app/Enums/BudgetPeriod.php` — Added `Fortnightly` case, reordered to frequency-ascending (Weekly, Fortnightly, Monthly, Yearly)
- `database/factories/BudgetFactory.php` — Added `fortnightly()` state method
- `tests/Unit/Enums/BudgetPeriodTest.php` — Updated count to 4, added Fortnightly assertions
- `tests/Feature/Models/BudgetTest.php` — Added fortnightly factory state test

### The Reasoning

- **Naming: `TransactionDirection` not `TransactionType`**: Issue #9 spec says `TransactionType`, but the codebase already uses `TransactionDirection` — which matches the Basiq API field name and the DB column. No rename needed.
- **`SyncStatus` standalone**: No model or migration wiring yet — this enum is for Phase 2 Basiq sync integration. Created now to complete the domain enum inventory.
- **Frequency-ascending ordering**: Cases ordered Weekly → Fortnightly → Monthly → Yearly so the progression is self-documenting.
- **Kebab-case `'in-progress'`**: Matches the convention in `AccountClass` (`'credit-card'`, `'term-deposit'`).

### The Tech Debt

- `SyncStatus` awaits Phase 2 model wiring (Basiq sync tables).

### Verification

- `op test.unit` — 29 tests pass (56 assertions)
- `op test.filter BudgetTest` — 21 tests pass (30 assertions)
- `op lint.dirty` — All PHP files pass formatting

---

## 2026-03-16 — Issue #10: Set Up Pest Architecture Presets and Mutation Testing

### The Change

Added architecture enforcement tests and mutation testing capability.

**Files created:**
- `tests/Arch.php` — 6 architecture tests: `laravel` preset, `security` preset, services must be final, models cannot use `floatval()`, DTOs must be readonly, enums must be string-backed

**Files modified:**
- `phpunit.xml` — Added `Arch` test suite pointing to `tests/Arch.php` so arch tests run with the full suite
- `op.conf` — Added `test.mutate` alias (`--mutate --min=85 --covered-only`)

### The Reasoning

- **`phpunit.xml` change required**: `tests/Arch.php` sits at the test root, outside the `Unit/` and `Feature/` directories. Without registering it as its own test suite, PHPUnit (and therefore Pest) silently skips it. Adding a dedicated `Arch` suite keeps the file at the conventional location while ensuring it runs with `op test`.
- **Vacuous arch rules on empty namespaces**: `App\Services` and `App\DTOs` only contain `.gitkeep` — the arch tests pass now but will enforce conventions (final services, readonly DTOs) as soon as real classes are added.
- **`not->toUse(['floatval'])` on models**: Protects the integer-cents pattern established by `MoneyCast`. If someone accidentally calls `floatval()` in a model, the arch test catches it at test time.
- **Mutation testing via runtime flag**: No config file changes needed — `--mutate --min=85 --covered-only` is passed at runtime through the `op test.mutate` alias.

### The Tech Debt

- Mutation testing score threshold (85%) may need tuning once the first full run completes — it could be too low or too high for the current test suite.

### Verification

- `op test` — 160 tests pass (355 assertions), up from 154/307
- `op lint.dirty` — All PHP files pass formatting
