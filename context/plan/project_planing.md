# Building a personal budgeting app with Laravel 12

**Laravel 12, Livewire, Basiq, and Dokploy form a potent stack for a self-hosted financial dashboard — but the devil is in the architectural decisions you make before writing a single line of code.** This guide provides a complete technical blueprint: from PHP 8.4 money-handling patterns and Livewire charting strategies to Basiq's open banking consent flow and production deployment on Dokploy. The goal is to derisk the hardest integration points upfront so you can move fast with confidence.

The stack centers on an Australia-focused personal budgeting app that pulls real bank data via Basiq's CDR-accredited API, stores and categorizes transactions locally, and renders interactive financial dashboards — all without a JavaScript build step beyond what Livewire and Alpine.js provide. Here's how to make every piece work together.

---

## Laravel 12 and PHP 8.4 give you a surprisingly powerful financial toolkit

**Laravel 12** (released February 24, 2025) is intentionally a maintenance release — most Laravel 11 apps upgrade without code changes. The meaningful additions have landed in 12.x minor releases. **Automatic eager loading (12.8)** eliminates N+1 queries without explicit `with()` calls, which is significant for dashboard pages that aggregate across accounts, transactions, and categories. Other notable additions include failover queue drivers for high-availability job processing, `Http::batch()->defer()` for deferred API calls (useful for background Basiq syncs), and PostgreSQL 18 virtual generated column support.

The real gains come from **PHP 8.4**, released November 2024. Three features are directly relevant to financial domain modeling:

**Property hooks** eliminate getter/setter boilerplate and enforce invariants at the language level. For a budgeting app, this means validation lives on the model itself:

```php
class Transaction {
    public float $amount {
        set => round($value, 2);
    }
    public string $formatted {
        get => '$' . number_format($this->amount, 2);
    }
}
```

**Asymmetric visibility** (`public private(set)`) creates naturally immutable value objects — ideal for financial records that should be readable everywhere but only writable through controlled service methods. The **new BCMath object API** provides proper arbitrary-precision arithmetic without the awkward procedural `bcadd()`/`bcmul()` calls. For a financial app, use this instead of floating-point math whenever you're doing calculations beyond simple storage and display.

The recommended project structure for this app extends Laravel 12's conventions:

```
app/
├── Http/Controllers/
├── Livewire/           # Dashboard, TransactionList, BudgetForm, etc.
├── Models/             # User, Account, Transaction, Category, Budget
├── Services/           # BasiqService, BudgetCalculator, TransactionSync
├── DataTransferObjects/ # PHP 8.4 readonly classes for API data
├── Enums/              # TransactionType, AccountClass, SyncStatus
└── Providers/
```

---

## Livewire 3 handles financial dashboards well — with one critical pattern

The central architectural question is whether **Livewire 3 can replace a JS framework** for interactive financial dashboards. After evaluating the ecosystem, the answer is **yes, with a specific integration pattern for charts**.

Livewire 3 (battle-tested, extensive ecosystem) is the safer choice over the newer Livewire 4 (v4.2.1, February 2026). Livewire 4's Blaze rendering engine offers ~60% reduction in DOM updates and introduces `@island` directives for isolated widget rendering, but Livewire 3 has broader package compatibility. Either works; Livewire 3 is recommended for this project unless you want to be on the cutting edge.

The features that make Livewire viable for financial dashboards are **`wire:navigate`** for SPA-like page transitions, **`#[Computed]` properties with `persist: true`** for caching expensive aggregations across requests, **`wire:lazy`** for deferring below-fold dashboard widgets, **`#[Locked]` properties** to prevent frontend tampering with financial data, and **`wire:poll`** for automatic dashboard refresh (auto-throttled in background tabs).

The limitation people hit is **chart rendering**. Livewire manages server-side state and HTML diffing; JavaScript charting libraries manage a canvas element. These two systems conflict if Livewire tries to diff the chart DOM. The solution is the **`wire:ignore` + Alpine.js bridge pattern**, which is now the standard approach in the Laravel community:

```blade
<div
    x-data="{
        chart: null,
        init() {
            this.chart = new ApexCharts(this.$refs.chart, {
                chart: { type: 'area', height: 350 },
                series: [{ name: 'Spending', data: @js($this->chartData) }],
                xaxis: { type: 'datetime' }
            });
            this.chart.render();
        }
    }"
    x-on:chart-updated.window="chart.updateSeries($event.detail)"
    wire:ignore
>
    <div x-ref="chart"></div>
</div>
```

The Livewire component dispatches browser events when data changes:

```php
public string $period = '30d';

#[Computed(persist: true)]
public function chartData(): array
{
    return Transaction::query()
        ->where('date', '>=', now()->sub($this->period))
        ->groupByRaw('DATE(date)')
        ->selectRaw('DATE(date) as x, SUM(amount) as y')
        ->get()->toArray();
}

public function updatedPeriod(): void
{
    $this->dispatch('chart-updated', [['data' => $this->chartData]]);
}
```

**Livewire owns data and state; Alpine.js owns the chart canvas.** The `wire:ignore` directive tells Livewire to never touch the chart DOM. This pattern scales to any charting library.

### Which charting library to use

For a financial dashboard, **ApexCharts is the strongest choice**. It natively supports candlestick charts, heatmaps, sparklines, synchronized multi-axis charts, and brush/zoom interactions — all common in financial UIs. The `asantibanez/livewire-charts` package (v4.2.0, maintained, Livewire 3 compatible) wraps ApexCharts in a fluent PHP API for rapid prototyping, but raw ApexCharts via Alpine gives you full control. **Chart.js** is lighter (~60KB vs ~130KB) and fully open source, but lacks financial-specific chart types. Filament's chart widgets (Chart.js under the hood) are excellent if you're already using Filament for admin panels.

A hybrid Livewire + Vue/React approach is viable but unnecessary here. The scenarios where Livewire falls short — sub-100ms drag-and-drop, complex client-side undo/redo, offline PWA support, virtual scrolling of 10K+ rows — don't apply to a personal budgeting app. You're building forms, filterable tables, and charts. That's Livewire's sweet spot.

---

## Basiq integration is the hardest engineering challenge in this project

**Basiq** is a CDR-accredited (Consumer Data Right) open banking platform connecting to **135+ Australian financial institutions** including all Big Four banks. It provides real-time account balances, transaction history, and — critically — **transaction enrichment with 500+ expense categories**, merchant identification, and location data. This saves you from building your own categorization engine.

### Authentication and token lifecycle

Basiq uses a two-scope token model against `https://au-api.basiq.io`:

| Scope | Purpose | TTL |
|---|---|---|
| `SERVER_ACCESS` | Backend operations — create users, fetch data, manage connections | 60 minutes |
| `CLIENT_ACCESS` | Frontend consent UI — bound to a specific userId | 60 minutes |

Tokens are obtained by exchanging your API key (via Basic auth). **Cache `SERVER_ACCESS` tokens with a ~20-minute TTL** (refresh well before expiry). Generate `CLIENT_ACCESS` tokens on-demand per user session. Here's the service architecture:

```php
class BasiqService
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl = 'https://au-api.basiq.io',
    ) {}

    public function serverToken(): string
    {
        return Cache::remember('basiq:server_token', 1200, fn () =>
            Http::asForm()
                ->withHeaders(['Authorization' => "Basic {$this->apiKey}", 'basiq-version' => '3.0'])
                ->post("{$this->baseUrl}/token", ['scope' => 'SERVER_ACCESS'])
                ->throw()->json('access_token')
        );
    }

    public function clientToken(string $basiqUserId): string
    {
        return Http::asForm()
            ->withHeaders(['Authorization' => "Basic {$this->apiKey}", 'basiq-version' => '3.0'])
            ->post("{$this->baseUrl}/token", [
                'scope' => 'CLIENT_ACCESS',
                'userId' => $basiqUserId,
            ])->throw()->json('access_token');
    }

    private function api(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->withToken($this->serverToken())
            ->withHeaders(['basiq-version' => '3.0'])
            ->throw();
    }
}
```

### The consent and connection flow

This is the most architecturally important integration point. Basiq's **Consent UI** is a hosted web application at `consent.basiq.io` — it's a **full-page redirect, not an iframe** (banks block iframes for security). The flow:

1. Your backend creates a Basiq user: `POST /users` with email/mobile
2. Your backend generates a `CLIENT_ACCESS` token bound to that user
3. Your frontend redirects to `https://consent.basiq.io/home?token={clientToken}&state={yourState}`
4. The user consents to data sharing, selects their bank, authenticates with their bank
5. Basiq redirects back to your app with `jobId` parameters
6. Your backend polls `GET /jobs/{jobId}` until all steps complete (`verify-credentials` → `retrieve-accounts` → `retrieve-transactions`)
7. Your backend fetches accounts and transactions via the API

Subsequent connections (adding more banks) use `action=connect`. Managing/extending consent uses `action=manage` and `action=extend`. The UI is customizable via Basiq's dashboard (logo, colors, consent policy text).

### Data sync architecture

The transaction schema from Basiq is rich — it includes amount, direction (debit/credit), description, post date, institution, and (with Enrich enabled) merchant name, logo, ABN, location with geocoordinates, and **ANZSIC categorization at four levels** (division → subdivision → group → class). This is extraordinarily valuable for a budgeting app — you get categorization essentially for free.

Design your local schema to store both raw Basiq data and your app's own categorization layer:

```php
Schema::create('transactions', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();
    $table->foreignId('account_id')->constrained()->cascadeOnDelete();
    $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
    $table->bigInteger('amount');                    // cents — always integers
    $table->string('direction', 10);                 // debit, credit
    $table->string('description', 500)->nullable();
    $table->string('clean_description', 500)->nullable(); // from Enrich
    $table->date('post_date');
    $table->date('transaction_date')->nullable();
    $table->string('status', 20)->default('posted'); // posted, pending
    $table->string('basiq_id', 100)->unique()->nullable();
    $table->string('basiq_account_id', 100)->nullable();
    $table->string('merchant_name', 255)->nullable();
    $table->string('anzsic_code', 10)->nullable();   // ANZSIC class code
    $table->json('enrich_data')->nullable();          // full enrich payload
    $table->timestamps();
    $table->index(['user_id', 'post_date']);
    $table->index('anzsic_code');
});
```

Sync via a queued job that handles pagination and upserts:

```php
class SyncTransactionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private readonly User $user,
        private readonly ?string $since = null,
    ) {}

    public function handle(BasiqService $basiq): void
    {
        $filter = $this->since
            ? "transaction.postDate.gt('{$this->since}')"
            : null;

        $basiq->paginateTransactions($this->user->basiq_user_id, $filter)
            ->each(function (array $txn) {
                Transaction::updateOrCreate(
                    ['basiq_id' => $txn['id']],
                    $this->mapTransaction($txn)
                );
            });
    }
}
```

### Pricing and sandbox

Basiq offers a **free sandbox** with a test institution called "Hooli" (ID: `AU00000`). Test credentials include profiles simulating various financial scenarios (regular income, gambling, BNPL activity). Production pricing starts at approximately **$0.39 per user per month** — billed per user regardless of connection count. Minimum 12-month contract. The Enrich API is separately enabled. There's a 500-connection sandbox limit (expandable via support).

Register webhooks for `connection.created` and `transaction.created` events to trigger background syncs rather than relying solely on polling.

---

## Money as integers and the SQLite-to-PostgreSQL migration path

**Store all monetary values as integers (cents).** This is non-negotiable for a financial app, and it sidesteps the most dangerous SQLite limitation: **SQLite does not enforce decimal precision**. A `DECIMAL(5,2)` column in SQLite will silently accept and potentially mangle values that violate the constraint. PostgreSQL enforces `NUMERIC(10,2)` strictly and throws `NumericValueOutOfRange` on violation.

By storing `$150.75` as `15075` in a `bigInteger` column, you get identical behavior on both databases, avoid all floating-point issues, and simplify arithmetic. Use accessors/mutators or PHP 8.4 property hooks for display formatting.

Laravel 12 continues Laravel 11's position of SQLite as the **default database for new projects**. For local development, configure WAL mode and a generous busy timeout:

```php
// config/database.php
'sqlite' => [
    'driver' => 'sqlite',
    'database' => database_path('database.sqlite'),
    'foreign_key_constraints' => true,
    'busy_timeout' => 10000,
    'journal_mode' => 'wal',
    'synchronous' => 'normal',
],
```

The key cross-database gotchas to avoid in migrations:

- **JSON columns**: `json()` maps to `TEXT` on SQLite but real `JSON`/`JSONB` on PostgreSQL. Avoid complex JSON queries in SQLite; they'll work differently in production.
- **Timestamps**: Stored as text in SQLite, native `TIMESTAMP` in PostgreSQL. Eloquent abstracts this, but raw queries may diverge.
- **ALTER TABLE**: SQLite doesn't support adding foreign keys after table creation. Define all foreign keys in the initial `create` migration.
- **Enum columns**: Use `string` with validation rather than `enum()` for full cross-database compatibility.
- **Indexes**: PostgreSQL supports partial, GIN, and expression indexes that don't exist in SQLite. Add these via raw statements gated by `DB::getDriverName() === 'pgsql'`.

**Testing strategy**: Use SQLite `:memory:` for fast unit tests (model logic, calculations, value objects). Use PostgreSQL in CI for feature tests (Livewire components, API integration, transaction integrity). This catches subtle differences early:

```xml
<!-- phpunit.xml for local development -->
<env name="DB_CONNECTION" value="sqlite"/>
<env name="DB_DATABASE" value=":memory:"/>
```

```yaml
# CI: Feature tests against PostgreSQL
services:
  postgres:
    image: postgres:16
    env:
      POSTGRES_DB: testing
      POSTGRES_USER: test
      POSTGRES_PASSWORD: test
```

---

## PEST v4 testing patterns for financial correctness

**PEST PHP v4.4.1** (February 2026), built on PHPUnit 12, brings three features especially relevant to this project: **mutation testing** validates that your financial calculations are genuinely tested (not just covered), **test sharding** splits suites for fast CI, and **architecture presets** enforce structural conventions automatically.

The critical testing patterns for a budgeting app:

**Mutation testing for financial calculations** — code coverage alone is insufficient for money math. Mutation testing introduces small code changes (replacing `+` with `-`, `>` with `>=`) and verifies your tests catch them:

```php
it('calculates monthly budget remaining', function () {
    $budget = Budget::factory()->create(['limit' => 50000]); // $500.00
    Transaction::factory()->count(3)->create([
        'budget_id' => $budget->id,
        'amount' => -10000, // -$100.00 each
    ]);

    expect($budget->remaining())->toBe(20000); // $200.00
})->covers(Budget::class);

// Run: ./vendor/bin/pest --mutate --min=85 --covered-only
```

**Mocking Basiq API calls** using Laravel's HTTP fake:

```php
it('syncs transactions from Basiq', function () {
    Http::fake([
        'au-api.basiq.io/token' => Http::response(['access_token' => 'test'], 200),
        'au-api.basiq.io/users/*/transactions*' => Http::response([
            'data' => [
                ['id' => 'txn_1', 'amount' => '-45.50', 'postDate' => '2026-03-01T00:00:00Z',
                 'description' => 'WOOLWORTHS', 'direction' => 'debit'],
            ],
            'links' => ['next' => null],
        ], 200),
    ]);

    $user = User::factory()->create(['basiq_user_id' => 'bu_123']);
    SyncTransactionsJob::dispatchSync($user);

    expect(Transaction::count())->toBe(1)
        ->and(Transaction::first()->amount)->toBe(-4550);
});
```

**Architecture testing** enforces conventions project-wide:

```php
arch()->preset()->laravel();
arch()->preset()->security();
arch('services are final')
    ->expect('App\Services')->toBeFinal();
arch('models use soft deletes')
    ->expect('App\Models\Transaction')->toUse('Illuminate\Database\Eloquent\SoftDeletes');
arch('no floating point money')
    ->expect('App\Models')
    ->not->toUse(['floatval', 'doubleval']);
```

**Dataset-driven tests** cover financial edge cases systematically:

```php
dataset('money_edge_cases', [
    'zero'          => [0, 0, 0],
    'one cent'      => [1, 1, 2],
    'large amount'  => [99999999, 1, 100000000],
    'negative'      => [-5000, 3000, -2000],
]);

it('adds amounts correctly', function (int $a, int $b, int $expected) {
    expect($a + $b)->toBe($expected);
})->with('money_edge_cases');
```

Structure tests as `tests/Unit/` (models, services, value objects with SQLite), `tests/Feature/` (Livewire components, API integration, webhooks with PostgreSQL in CI), and `tests/Arch/` (architecture rules). Use sharding in CI for speed:

```yaml
strategy:
  matrix:
    shard: [1/3, 2/3, 3/3]
steps:
  - run: ./vendor/bin/pest --parallel --shard=${{ matrix.shard }}
```

---

## Deploying on Dokploy with Docker Compose

**Dokploy** is a free, open-source, self-hosted PaaS (~24K GitHub stars) built on Docker, Docker Swarm, and Traefik. It installs with a single command on any Ubuntu VPS and provides a web dashboard for managing applications, databases, SSL certificates, and deployments. For a personal budgeting app, it's ideal — you maintain full control over your financial data while getting Heroku-like deployment ergonomics.

The production stack requires five services: **PHP-FPM** (running Laravel), **PostgreSQL 16**, **Redis 7** (queues, cache, sessions), a **queue worker**, and a **scheduler**. The most maintainable approach uses Supervisor inside a single container for the app + worker + scheduler, with separate containers for PostgreSQL and Redis.

**Dockerfile for production** (PHP 8.4, all required extensions):

```dockerfile
FROM php:8.4-fpm AS base

RUN apt-get update && apt-get install -y \
    git unzip curl libpng-dev libonig-dev libxml2-dev \
    libzip-dev libpq-dev libicu-dev g++ supervisor \
    && docker-php-ext-install pdo pdo_pgsql mbstring zip bcmath intl opcache

RUN curl -fsSL https://deb.nodesource.com/setup_22.x | bash - \
    && apt-get install -y nodejs

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www
COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-scripts

COPY package.json package-lock.json ./
RUN npm ci && npm run build

COPY . .
RUN composer dump-autoload --optimize

COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/custom.ini

RUN php artisan config:cache && php artisan route:cache && php artisan view:cache \
    && chown -R www-data:www-data storage bootstrap/cache

EXPOSE 8000
CMD ["supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
```

The **Supervisor config** runs PHP-FPM, two queue workers, and the scheduler in a single container:

```ini
[supervisord]
nodaemon=true

[program:php-fpm]
command=php-fpm -F
autostart=true
autorestart=true

[program:queue-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/artisan queue:work redis --sleep=3 --tries=3 --max-time=3600
numprocs=2
autostart=true
autorestart=true

[program:scheduler]
command=sh -c "while true; do php /var/www/artisan schedule:run; sleep 60; done"
autostart=true
autorestart=true
```

Deploy via **Docker Compose** in Dokploy, which handles Traefik routing and Let's Encrypt SSL automatically. Configure environment variables through Dokploy's UI (project-level for shared secrets, service-level for per-container config). Enable **auto-deploy** from your GitHub repository's main branch, or trigger deployments via Dokploy's webhook API from GitHub Actions after tests pass.

**Resource requirements**: A **2 vCPU / 4GB RAM** VPS (€5–10/month on Hetzner) comfortably runs the entire stack. Dokploy itself uses ~1GB idle. PostgreSQL and Redis add ~500MB. The Laravel app with two queue workers sits around ~200MB. This leaves headroom for traffic spikes and Basiq sync jobs.

---

## Putting it all together: the implementation roadmap

With all the pieces researched, here's a phased build order that minimizes risk and maximizes feedback loops:

**Phase 1 — Foundation (Week 1–2):** Scaffold Laravel 12 with the Livewire starter kit. Configure SQLite with WAL mode. Set up PEST with architecture presets. Build the core Eloquent models (User, Account, Transaction, Category, Budget) with integer-cents money storage. Write model unit tests with mutation testing enabled.

**Phase 2 — Basiq integration (Week 3–4):** Implement `BasiqService` with token caching. Build the consent redirect flow (Livewire component → redirect → callback handler → job polling). Create `SyncTransactionsJob` with pagination and upsert logic. Register webhooks. Test everything against Basiq's sandbox with Http::fake in PEST and real sandbox calls in manual testing.

**Phase 3 — Dashboard (Week 5–6):** Build Livewire dashboard components: account overview, spending by category (ApexCharts pie/donut), spending over time (ApexCharts area), transaction list with filters/pagination. Use `#[Computed(persist: true)]` for expensive aggregations. Implement the `wire:ignore` + Alpine pattern for all charts.

**Phase 4 — Production deployment (Week 7):** Switch to PostgreSQL. Run the full PEST suite against PostgreSQL in CI. Deploy on Dokploy with Docker Compose. Configure SSL, environment variables, and auto-deploy. Set up scheduled Basiq connection refreshes (daily via `Schedule::job()`).

The riskiest integration — Basiq's consent flow and asynchronous job-based data retrieval — should be tackled early in Phase 2. Everything else is standard Laravel CRUD with a charting layer. The stack is deliberately boring where it can be and sophisticated only where it must be: at the bank data boundary and the chart rendering layer.