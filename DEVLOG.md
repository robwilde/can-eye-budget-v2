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
