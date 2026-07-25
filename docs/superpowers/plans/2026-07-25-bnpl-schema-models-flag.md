# BNPL Schema, Models and Feature Flag Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Land the two tables, three enums, two models and the feature flag that every other sub-task of epic #355 depends on, with the whole feature dark by default.

**Architecture:** Two new tables following the app's per-domain log convention (`BasiqRefreshLog`, `PipelineRun`, `PipelineAuditEntry`, `BankImport`, `AnalysisSuggestion`) — no auditing package is being added. `bnpl_orders` holds one row per parsed schedule email: current state plus full provenance. `bnpl_order_events` is its append-only audit trail, shaped after `pipeline_audit_entries`. Nothing reads or writes these tables yet; #357–#362 do that. **No migration touches `planned_transactions` or `transactions`.**

**Tech Stack:** PHP 8.4, Laravel 12, Pest 4, MySQL (DDEV), Pint, Larastan/PHPStan, Rector, GrumPHP.

**Issue:** #356 (sub-issue of epic #355)
**Spec:** `docs/superpowers/specs/2026-07-25-bnpl-email-planned-payments-design.md`
**Branch:** `feat/356-bnpl-schema-models-flag` (already created, off `develop`)
**PR target:** `develop`

---

## Environment

**Never run `php`, `artisan`, `composer`, `npm` or `vendor/bin/*` directly.** This project runs in DDEV. Use the `op` aliases:

| What | Command |
|---|---|
| Run one test file | `op test.filter BnplOrderTest` |
| Run the whole suite | `op test` |
| Style + static analysis on uncommitted files | `op check.dirty` |
| Migrate | `op migrate` |
| Raw artisan | `ddev exec php artisan <cmd>` |

The Laravel Boost MCP database tool **does not work** here — it runs outside the container and fails with `getaddrinfo for db failed`. Use `ddev mysql` if you need to inspect the schema.

## Code style (non-negotiable in this repo)

- `declare(strict_types=1);` at the top of every PHP file
- `final` classes; explicit return types everywhere; constructor property promotion
- Money is **integer cents** via `App\Casts\MoneyCast`
- `env()` only inside `config/` files — everywhere else use `config()`
- Eloquent only; never the `DB::` facade
- **No code comments.** Use PHPDoc blocks (`@property`, `@param`, `@return`) instead
- Every model needs a full `@property` docblock or PHPStan will fail
- Commit format: `type(#356): subject` — lowercase subject, body explaining *why* and *verification*, footer `Refs #356` and `Verified: <status>`

## File Structure

**Create:**

| File | Responsibility |
|---|---|
| `app/Enums/BnplProvider.php` | The five BNPL providers; `label()` supplies the plan-description prefix #359 needs |
| `app/Enums/BnplOrderStatus.php` | Order lifecycle state; `isApproved()` is the predicate #359's account memory queries on |
| `app/Enums/BnplOrderEventType.php` | The seven audit events |
| `database/migrations/2026_07_25_120000_create_bnpl_orders_table.php` | `bnpl_orders` schema |
| `database/migrations/2026_07_25_120001_create_bnpl_order_events_table.php` | `bnpl_order_events` schema |
| `app/Models/BnplOrder.php` | Order state, relations, `recordEvent()` |
| `app/Models/BnplOrderEvent.php` | One immutable audit row |
| `database/factories/BnplOrderFactory.php` | Order test data, modelled on the sample Petbarn email |
| `database/factories/BnplOrderEventFactory.php` | Event test data |
| `tests/Feature/Models/BnplOrderTest.php` | Casts, relations, cascades, unique constraints, `recordEvent()` |
| `tests/Feature/Models/BnplOrderEventTest.php` | Casts, relation, cascade |

**Modify:**

| File | Change |
|---|---|
| `config/budget.php` | Add the `bnpl_email_import` flag |
| `.env.example` | Add `BUDGET_BNPL_EMAIL_IMPORT=false` after line 74 |

### Two deliberate inclusions beyond the letter of #356

1. **`BnplOrderEventType` is an enum**, not a bare string. #356 says only `status` and `provider` are enum-backed. An audit trail whose event names are free-form strings silently accumulates typos across the four consumers (#359, #360, #361, #362), and a mistyped audit row is permanent and undetectable. The enum costs one file.
2. **`BnplOrder::recordEvent()` ships here**, with no caller yet. All four downstream sub-tasks write events; defining the single write path alongside the table it writes to prevents four divergent implementations, and it is the natural API of the table pair.

---

## Task 1: Feature flag

**Files:**
- Modify: `config/budget.php`
- Modify: `.env.example:74`

- [ ] **Step 1: Add the flag to the config**

Append inside the returned array in `config/budget.php`, after the `recurring_detection` entry, matching the existing comment-block style:

```php
    /*
    |--------------------------------------------------------------------------
    | BNPL Email Import
    |--------------------------------------------------------------------------
    |
    | When enabled, a scheduled mailbox scan turns Afterpay (and later Zip,
    | Klarna, PayPal Pay-in-4 and Humm) payment-schedule emails into planned
    | transactions. Disabled until every sub-task of epic #355 has merged:
    | importing schedules without the combined-debit fan-out would make the
    | calendar double-count.
    |
    */

    'bnpl_email_import' => env('BUDGET_BNPL_EMAIL_IMPORT', false),
```

- [ ] **Step 2: Add the env example entry**

In `.env.example`, immediately after the existing `BUDGET_RECURRING_DETECTION=false` on line 74:

```
BUDGET_BNPL_EMAIL_IMPORT=false
```

- [ ] **Step 3: Verify the flag reads false**

Run: `ddev exec php artisan tinker --execute="var_dump(config('budget.bnpl_email_import'));"`
Expected: `bool(false)`

- [ ] **Step 4: Commit**

```bash
git add config/budget.php .env.example
git commit -m "feat(#356): add bnpl email import feature flag

Gates the whole of epic #355. Importing schedules before the
combined-debit fan-out (#362) lands would leave every instalment pip
unclaimed alongside a posted combined debit, so the calendar would
double-count. Mirrors the existing budget.recurring_detection pattern.

Verification: artisan tinker reports config('budget.bnpl_email_import')
is false with no env override.

Refs #356
Verified: manual config read"
```

---

## Task 2: `BnplProvider` enum

**Files:**
- Create: `app/Enums/BnplProvider.php`
- Test: `tests/Unit/Enums/BnplProviderTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Enums/BnplProviderTest.php`:

```php
<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\BnplProvider;

test('afterpay label is the display name used in plan descriptions', function () {
    expect(BnplProvider::Afterpay->label())->toBe('Afterpay');
});

test('paypal label is cased for display, not for its value', function () {
    expect(BnplProvider::Paypal->value)->toBe('paypal')
        ->and(BnplProvider::Paypal->label())->toBe('PayPal');
});

test('every provider has a label', function () {
    foreach (BnplProvider::cases() as $provider) {
        expect($provider->label())->not->toBe('');
    }
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `op test.filter BnplProviderTest`
Expected: FAIL with `Class "App\Enums\BnplProvider" not found`

- [ ] **Step 3: Write the enum**

Create `app/Enums/BnplProvider.php`:

```php
<?php

declare(strict_types=1);

namespace App\Enums;

enum BnplProvider: string
{
    case Afterpay = 'afterpay';
    case Zip = 'zip';
    case Klarna = 'klarna';
    case Paypal = 'paypal';
    case Humm = 'humm';

    public function label(): string
    {
        return match ($this) {
            self::Afterpay => 'Afterpay',
            self::Zip => 'Zip',
            self::Klarna => 'Klarna',
            self::Paypal => 'PayPal',
            self::Humm => 'Humm',
        };
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `op test.filter BnplProviderTest`
Expected: PASS, 3 tests

---

## Task 3: `BnplOrderStatus` and `BnplOrderEventType` enums

**Files:**
- Create: `app/Enums/BnplOrderStatus.php`
- Create: `app/Enums/BnplOrderEventType.php`
- Test: `tests/Unit/Enums/BnplOrderStatusTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Enums/BnplOrderStatusTest.php`:

```php
<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\BnplOrderEventType;
use App\Enums\BnplOrderStatus;

test('isApproved covers both the reviewed and the automatic path', function () {
    expect(BnplOrderStatus::Approved->isApproved())->toBeTrue()
        ->and(BnplOrderStatus::AutoApproved->isApproved())->toBeTrue();
});

test('isApproved is false for pending and rejected orders', function () {
    expect(BnplOrderStatus::PendingReview->isApproved())->toBeFalse()
        ->and(BnplOrderStatus::Rejected->isApproved())->toBeFalse();
});

test('every status has a label', function () {
    foreach (BnplOrderStatus::cases() as $status) {
        expect($status->label())->not->toBe('');
    }
});

test('the seven audit events the spec names all exist', function () {
    $values = array_map(fn (BnplOrderEventType $e): string => $e->value, BnplOrderEventType::cases());

    expect($values)->toEqualCanonicalizing([
        'email_pulled',
        'plan_created',
        'review_requested',
        'approved',
        'auto_approved',
        'category_set',
        'rejected',
    ]);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `op test.filter BnplOrderStatusTest`
Expected: FAIL with `Class "App\Enums\BnplOrderEventType" not found`

- [ ] **Step 3: Write both enums**

Create `app/Enums/BnplOrderStatus.php`:

```php
<?php

declare(strict_types=1);

namespace App\Enums;

enum BnplOrderStatus: string
{
    case PendingReview = 'pending_review';
    case Approved = 'approved';
    case AutoApproved = 'auto_approved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::PendingReview => 'Pending review',
            self::Approved => 'Approved',
            self::AutoApproved => 'Auto-approved',
            self::Rejected => 'Rejected',
        };
    }

    public function isApproved(): bool
    {
        return $this === self::Approved || $this === self::AutoApproved;
    }
}
```

Create `app/Enums/BnplOrderEventType.php`:

```php
<?php

declare(strict_types=1);

namespace App\Enums;

enum BnplOrderEventType: string
{
    case EmailPulled = 'email_pulled';
    case PlanCreated = 'plan_created';
    case ReviewRequested = 'review_requested';
    case Approved = 'approved';
    case AutoApproved = 'auto_approved';
    case CategorySet = 'category_set';
    case Rejected = 'rejected';
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `op test.filter BnplOrderStatusTest`
Expected: PASS, 4 tests

- [ ] **Step 5: Commit**

```bash
git add app/Enums/BnplProvider.php app/Enums/BnplOrderStatus.php app/Enums/BnplOrderEventType.php tests/Unit/Enums/
git commit -m "feat(#356): add bnpl provider, status and event enums

BnplProvider::label() supplies the 'Afterpay - Petbarn' description
prefix #359 builds. BnplOrderStatus::isApproved() is the predicate the
account-memory lookup queries on, and it must cover both Approved and
AutoApproved or a memory seeded automatically would never be found.
BnplOrderEventType is an enum rather than a bare string because a
mistyped audit event is silent and permanent.

Verification: 7 unit tests over labels, isApproved across all four
states, and the full set of seven event values.

Refs #356
Verified: op test.filter passes"
```

---

## Task 4: `bnpl_orders` migration and model

> **Tasks 4 and 5 are one unit of work and produce ONE commit.** `BnplOrder` references
> `BnplOrderEvent` in `events()` and `recordEvent()`, so until Task 5 creates that class
> PHPStan fails on an unknown type — and GrumPHP runs PHPStan pre-commit, so Task 4 cannot
> commit alone. Do Task 4, then Task 5, then commit once at Task 5 Step 9. Do not attempt
> `op check.dirty` between them.

**Files:**
- Create: `database/migrations/2026_07_25_120000_create_bnpl_orders_table.php`
- Create: `app/Models/BnplOrder.php`
- Create: `database/factories/BnplOrderFactory.php`
- Test: `tests/Feature/Models/BnplOrderTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Models/BnplOrderTest.php`:

```php
<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\BnplOrderStatus;
use App\Enums\BnplProvider;
use App\Enums\RecurrenceFrequency;
use App\Models\Account;
use App\Models\BnplOrder;
use App\Models\Category;
use App\Models\PlannedTransaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;

test('factory creates a valid pending order', function () {
    $order = BnplOrder::factory()->create();

    expect($order)->toBeInstanceOf(BnplOrder::class)
        ->and($order->exists)->toBeTrue()
        ->and($order->status)->toBe(BnplOrderStatus::PendingReview)
        ->and($order->category_id)->toBeNull();
});

test('provider status and frequency are cast to enums', function () {
    $order = BnplOrder::factory()->create();

    expect($order->provider)->toBeInstanceOf(BnplProvider::class)
        ->and($order->status)->toBeInstanceOf(BnplOrderStatus::class)
        ->and($order->frequency)->toBe(RecurrenceFrequency::Every2Weeks);
});

test('frequency is null for an unsupported cadence', function () {
    $order = BnplOrder::factory()->create([
        'frequency' => null,
        'review_note' => 'unsupported_cadence',
    ]);

    expect($order->fresh()->frequency)->toBeNull()
        ->and($order->fresh()->review_note)->toBe('unsupported_cadence');
});

test('total and instalment amount are stored as whole cents', function () {
    $order = BnplOrder::factory()->create(['total' => 7445, 'instalment_amount' => 1861]);

    expect($order->fresh()->total)->toBe(7445)
        ->and($order->fresh()->instalment_amount)->toBe(1861);
});

test('parsed_payload is cast to array', function () {
    $payload = ['orderRef' => '953186001', 'instalments' => [['date' => '2026-08-07', 'amount' => 1861]]];
    $order = BnplOrder::factory()->create(['parsed_payload' => $payload]);

    expect($order->fresh()->parsed_payload)->toBe($payload);
});

test('belongs to a user, account, category and planned transaction', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $category = Category::factory()->create();
    $plan = PlannedTransaction::factory()->for($user)->for($account)->create();

    $order = BnplOrder::factory()->for($user)->create([
        'account_id' => $account->id,
        'category_id' => $category->id,
        'planned_transaction_id' => $plan->id,
    ]);

    expect($order->user->id)->toBe($user->id)
        ->and($order->account->id)->toBe($account->id)
        ->and($order->category->id)->toBe($category->id)
        ->and($order->plannedTransaction->id)->toBe($plan->id);
});

test('the default factory scopes the account to the order user', function () {
    $order = BnplOrder::factory()->create();

    expect($order->account->user_id)->toBe($order->user_id);
});

test('for() attaches an account owned by the same user', function () {
    $user = User::factory()->create();

    $order = BnplOrder::factory()->for($user)->create();

    expect($order->user_id)->toBe($user->id)
        ->and($order->account->user_id)->toBe($user->id);
});

test('cascades on user delete', function () {
    $user = User::factory()->create();
    BnplOrder::factory()->for($user)->create();

    $user->delete();

    expect(BnplOrder::query()->count())->toBe(0);
});

test('survives account deletion with a null account', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $order = BnplOrder::factory()->for($user)->create(['account_id' => $account->id]);

    $account->delete();

    expect($order->fresh()->account_id)->toBeNull();
});

test('survives planned transaction deletion with a null link', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $plan = PlannedTransaction::factory()->for($user)->for($account)->create();
    $order = BnplOrder::factory()->for($user)->create(['planned_transaction_id' => $plan->id]);

    $plan->delete();

    expect($order->fresh()->planned_transaction_id)->toBeNull();
});

test('rejects a second order for the same gmail message', function () {
    $user = User::factory()->create();
    BnplOrder::factory()->for($user)->create(['gmail_message_id' => 'abc@afterpay.com']);

    BnplOrder::factory()->for($user)->create(['gmail_message_id' => 'abc@afterpay.com']);
})->throws(UniqueConstraintViolationException::class);

test('rejects the same order arriving under a second message id', function () {
    $user = User::factory()->create();
    BnplOrder::factory()->for($user)->create([
        'provider' => BnplProvider::Afterpay,
        'order_ref' => '953186001',
        'gmail_message_id' => 'first@afterpay.com',
    ]);

    BnplOrder::factory()->for($user)->create([
        'provider' => BnplProvider::Afterpay,
        'order_ref' => '953186001',
        'gmail_message_id' => 'second@afterpay.com',
    ]);
})->throws(UniqueConstraintViolationException::class);

test('two users may hold the same order ref independently', function () {
    BnplOrder::factory()->create(['order_ref' => '953186001']);
    $second = BnplOrder::factory()->create(['order_ref' => '953186001']);

    expect($second->exists)->toBeTrue()
        ->and(BnplOrder::query()->count())->toBe(2);
});

test('isSettled is true when the last due date is yesterday', function () {
    $order = BnplOrder::factory()->create([
        'last_due_date' => CarbonImmutable::today()->subDay(),
    ]);

    expect($order->isSettled())->toBeTrue();
});

test('isSettled is false when the last due date is today', function () {
    $order = BnplOrder::factory()->create([
        'last_due_date' => CarbonImmutable::today(),
    ]);

    expect($order->isSettled())->toBeFalse();
});

test('isSettled is false when the last due date is in the future', function () {
    $order = BnplOrder::factory()->create([
        'last_due_date' => CarbonImmutable::today()->addWeeks(6),
    ]);

    expect($order->isSettled())->toBeFalse();
});

test('the settled factory state produces a settled order', function () {
    $order = BnplOrder::factory()->settled()->create();

    expect($order->isSettled())->toBeTrue();
});

test('auto approved state carries a category and a review timestamp', function () {
    $order = BnplOrder::factory()->autoApproved()->create();

    expect($order->status)->toBe(BnplOrderStatus::AutoApproved)
        ->and($order->category_id)->not->toBeNull()
        ->and($order->reviewed_at)->not->toBeNull();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `op test.filter BnplOrderTest`
Expected: FAIL with `Class "App\Models\BnplOrder" not found`

- [ ] **Step 3: Write the migration**

Create `database/migrations/2026_07_25_120000_create_bnpl_orders_table.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bnpl_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 20);
            $table->string('retailer');
            $table->string('order_ref', 64);
            $table->bigInteger('total');
            $table->bigInteger('instalment_amount');
            $table->unsignedTinyInteger('instalment_count');
            $table->date('first_due_date');
            $table->date('last_due_date');
            $table->string('frequency', 32)->nullable();
            $table->foreignId('account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('card_last4', 4)->nullable();
            $table->string('status', 20)->default('pending_review');
            $table->string('review_note')->nullable();
            $table->foreignId('planned_transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->string('gmail_message_id');
            $table->string('subject');
            $table->timestamp('email_date')->nullable();
            $table->text('snippet')->nullable();
            $table->string('gmail_url', 2048);
            $table->json('parsed_payload')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'gmail_message_id']);
            $table->unique(['user_id', 'provider', 'order_ref']);
            $table->index(['user_id', 'provider', 'retailer']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bnpl_orders');
    }
};
```

- [ ] **Step 4: Write the model**

Create `app/Models/BnplOrder.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\BnplOrderEventType;
use App\Enums\BnplOrderStatus;
use App\Enums\BnplProvider;
use App\Enums\RecurrenceFrequency;
use Carbon\CarbonImmutable;
use Database\Factories\BnplOrderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $user_id
 * @property BnplProvider $provider
 * @property string $retailer
 * @property string $order_ref
 * @property int $total
 * @property int $instalment_amount
 * @property int $instalment_count
 * @property CarbonImmutable $first_due_date
 * @property CarbonImmutable $last_due_date
 * @property RecurrenceFrequency|null $frequency
 * @property int|null $account_id
 * @property int|null $category_id
 * @property string|null $card_last4
 * @property BnplOrderStatus $status
 * @property string|null $review_note
 * @property int|null $planned_transaction_id
 * @property string $gmail_message_id
 * @property string $subject
 * @property CarbonImmutable|null $email_date
 * @property string|null $snippet
 * @property string $gmail_url
 * @property array<string, mixed>|null $parsed_payload
 * @property CarbonImmutable|null $reviewed_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class BnplOrder extends Model
{
    /** @use HasFactory<BnplOrderFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'provider',
        'retailer',
        'order_ref',
        'total',
        'instalment_amount',
        'instalment_count',
        'first_due_date',
        'last_due_date',
        'frequency',
        'account_id',
        'category_id',
        'card_last4',
        'status',
        'review_note',
        'planned_transaction_id',
        'gmail_message_id',
        'subject',
        'email_date',
        'snippet',
        'gmail_url',
        'parsed_payload',
        'reviewed_at',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return BelongsTo<PlannedTransaction, $this> */
    public function plannedTransaction(): BelongsTo
    {
        return $this->belongsTo(PlannedTransaction::class);
    }

    /** @return HasMany<BnplOrderEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(BnplOrderEvent::class);
    }

    /**
     * The single write path for this order's audit trail. Callers never build a
     * BnplOrderEvent directly, so the event vocabulary stays closed.
     *
     * @param  array<string, mixed>  $payload
     */
    public function recordEvent(BnplOrderEventType $event, array $payload = [], string $actor = 'system'): BnplOrderEvent
    {
        return $this->events()->create([
            'event' => $event,
            'payload' => $payload === [] ? null : $payload,
            'actor' => $actor,
        ]);
    }

    /**
     * A settled schedule has no instalment left to forecast, so it seeds the
     * retailer category memory without ever producing a planned transaction.
     */
    public function isSettled(): bool
    {
        return $this->last_due_date->lessThan(CarbonImmutable::today());
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => BnplProvider::class,
            'status' => BnplOrderStatus::class,
            'frequency' => RecurrenceFrequency::class,
            'total' => MoneyCast::class,
            'instalment_amount' => MoneyCast::class,
            'first_due_date' => 'date',
            'last_due_date' => 'date',
            'email_date' => 'datetime',
            'reviewed_at' => 'datetime',
            'parsed_payload' => 'array',
        ];
    }
}
```

- [ ] **Step 5: Write the factory**

Create `database/factories/BnplOrderFactory.php`. Dates are relative to today so the live/settled distinction never depends on the calendar:

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\BnplOrderStatus;
use App\Enums\BnplProvider;
use App\Enums\RecurrenceFrequency;
use App\Models\Account;
use App\Models\BnplOrder;
use App\Models\Category;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BnplOrder>
 */
final class BnplOrderFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $firstDue = CarbonImmutable::today()->addWeeks(2);

        return [
            'user_id' => User::factory(),
            'provider' => BnplProvider::Afterpay,
            'retailer' => fake()->company(),
            'order_ref' => (string) fake()->unique()->numberBetween(100_000_000, 999_999_999),
            'total' => 7445,
            'instalment_amount' => 1861,
            'instalment_count' => 4,
            'first_due_date' => $firstDue,
            'last_due_date' => $firstDue->addWeeks(6),
            'frequency' => RecurrenceFrequency::Every2Weeks,
            'account_id' => fn (array $attributes) => Account::factory()->create(['user_id' => $attributes['user_id']])->id,
            'category_id' => null,
            'card_last4' => '8357',
            'status' => BnplOrderStatus::PendingReview,
            'review_note' => null,
            'planned_transaction_id' => null,
            'gmail_message_id' => fake()->unique()->uuid().'@afterpay.com',
            'subject' => 'Thank you for your Afterpay order',
            'email_date' => CarbonImmutable::now()->subDay(),
            'snippet' => 'Petbarn · $74.45 · 4 payments of $18.61',
            'gmail_url' => 'https://mail.google.com/mail/u/0/#search/rfc822msgid:'.fake()->uuid(),
            'parsed_payload' => null,
            'reviewed_at' => null,
        ];
    }

    public function autoApproved(): self
    {
        return $this->state(fn (array $attributes) => [
            'status' => BnplOrderStatus::AutoApproved,
            'category_id' => Category::factory(),
            'reviewed_at' => CarbonImmutable::now(),
        ]);
    }

    public function approved(): self
    {
        return $this->state(fn (array $attributes) => [
            'status' => BnplOrderStatus::Approved,
            'category_id' => Category::factory(),
            'reviewed_at' => CarbonImmutable::now(),
        ]);
    }

    public function rejected(): self
    {
        return $this->state(fn (array $attributes) => [
            'status' => BnplOrderStatus::Rejected,
            'reviewed_at' => CarbonImmutable::now(),
        ]);
    }

    public function settled(): self
    {
        $firstDue = CarbonImmutable::today()->subWeeks(8);

        return $this->state(fn (array $attributes) => [
            'first_due_date' => $firstDue,
            'last_due_date' => $firstDue->addWeeks(6),
        ]);
    }
}
```

- [ ] **Step 6: Run the migration**

Run: `op migrate`
Expected: `2026_07_25_120000_create_bnpl_orders_table ... DONE`

- [ ] **Step 7: Run the order tests**

Run: `op test.filter BnplOrderTest`
Expected: every test in the file **errors** with `Class "App\Models\BnplOrderEvent" not found`, because `BnplOrder::events()` type-hints a class Task 5 has not created yet. That is expected at this point and is why Tasks 4 and 5 share one commit. Do not try to fix it by stubbing the class or by deleting `events()`/`recordEvent()` — go straight on to Task 5, which resolves it. If you see any *other* error, fix that before continuing.

---

## Task 5: `bnpl_order_events` migration and model

**Files:**
- Create: `database/migrations/2026_07_25_120001_create_bnpl_order_events_table.php`
- Create: `app/Models/BnplOrderEvent.php`
- Create: `database/factories/BnplOrderEventFactory.php`
- Test: `tests/Feature/Models/BnplOrderEventTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Models/BnplOrderEventTest.php`:

```php
<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\BnplOrderEventType;
use App\Models\BnplOrder;
use App\Models\BnplOrderEvent;

test('factory creates a valid event', function () {
    $event = BnplOrderEvent::factory()->create();

    expect($event)->toBeInstanceOf(BnplOrderEvent::class)
        ->and($event->exists)->toBeTrue();
});

test('event is cast to an enum and payload to an array', function () {
    $event = BnplOrderEvent::factory()->create([
        'event' => BnplOrderEventType::PlanCreated,
        'payload' => ['planned_transaction_id' => 12],
    ]);

    expect($event->fresh()->event)->toBe(BnplOrderEventType::PlanCreated)
        ->and($event->fresh()->payload)->toBe(['planned_transaction_id' => 12]);
});

test('belongs to an order', function () {
    $order = BnplOrder::factory()->create();
    $event = BnplOrderEvent::factory()->for($order)->create();

    expect($event->bnplOrder->id)->toBe($order->id);
});

test('cascades on order delete', function () {
    $order = BnplOrder::factory()->create();
    BnplOrderEvent::factory()->for($order)->create();

    $order->delete();

    expect(BnplOrderEvent::query()->count())->toBe(0);
});

test('recordEvent writes one row attributed to the system by default', function () {
    $order = BnplOrder::factory()->create();

    $event = $order->recordEvent(BnplOrderEventType::EmailPulled);

    expect($event->event)->toBe(BnplOrderEventType::EmailPulled)
        ->and($event->actor)->toBe('system')
        ->and($event->payload)->toBeNull()
        ->and($order->events()->count())->toBe(1);
});

test('recordEvent stores a payload and an explicit actor', function () {
    $order = BnplOrder::factory()->create();

    $event = $order->recordEvent(BnplOrderEventType::CategorySet, ['category_id' => 7], '3');

    expect($event->payload)->toBe(['category_id' => 7])
        ->and($event->actor)->toBe('3');
});

test('an order accumulates its events in write order', function () {
    $order = BnplOrder::factory()->create();

    $order->recordEvent(BnplOrderEventType::EmailPulled);
    $order->recordEvent(BnplOrderEventType::ReviewRequested);

    $events = $order->events()->orderBy('id')->pluck('event')->all();

    expect($events)->toBe([BnplOrderEventType::EmailPulled, BnplOrderEventType::ReviewRequested]);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `op test.filter BnplOrderEventTest`
Expected: FAIL with `Class "App\Models\BnplOrderEvent" not found`

- [ ] **Step 3: Write the migration**

Create `database/migrations/2026_07_25_120001_create_bnpl_order_events_table.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bnpl_order_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bnpl_order_id')->constrained()->cascadeOnDelete();
            $table->string('event', 32);
            $table->json('payload')->nullable();
            $table->string('actor')->nullable();
            $table->timestamps();

            $table->index(['bnpl_order_id', 'event']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bnpl_order_events');
    }
};
```

- [ ] **Step 4: Write the model**

Create `app/Models/BnplOrderEvent.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BnplOrderEventType;
use Carbon\CarbonImmutable;
use Database\Factories\BnplOrderEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $bnpl_order_id
 * @property BnplOrderEventType $event
 * @property array<string, mixed>|null $payload
 * @property string|null $actor
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class BnplOrderEvent extends Model
{
    /** @use HasFactory<BnplOrderEventFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'bnpl_order_id',
        'event',
        'payload',
        'actor',
    ];

    /** @return BelongsTo<BnplOrder, $this> */
    public function bnplOrder(): BelongsTo
    {
        return $this->belongsTo(BnplOrder::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event' => BnplOrderEventType::class,
            'payload' => 'array',
        ];
    }
}
```

- [ ] **Step 5: Write the factory**

Create `database/factories/BnplOrderEventFactory.php`:

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\BnplOrderEventType;
use App\Models\BnplOrder;
use App\Models\BnplOrderEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BnplOrderEvent>
 */
final class BnplOrderEventFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'bnpl_order_id' => BnplOrder::factory(),
            'event' => BnplOrderEventType::EmailPulled,
            'payload' => null,
            'actor' => 'system',
        ];
    }
}
```

- [ ] **Step 6: Run the migration**

Run: `op migrate`
Expected: `2026_07_25_120001_create_bnpl_order_events_table ... DONE`

- [ ] **Step 7: Run both model test files to verify they pass**

Run: `op test.filter BnplOrder`
Expected: PASS — 14 tests in `BnplOrderTest`, 7 in `BnplOrderEventTest`. The four cases that failed at the end of Task 4 now pass.

- [ ] **Step 8: Verify the migrations roll back cleanly**

Run: `ddev exec php artisan migrate:rollback --step=2`
Expected: both `bnpl_order_events` and `bnpl_orders` rolled back, no foreign-key error. `bnpl_order_events` must go first — the migration timestamps already order it that way.

Run: `op migrate`
Expected: both re-applied.

- [ ] **Step 9: Commit**

```bash
git add database/migrations/ app/Models/BnplOrder.php app/Models/BnplOrderEvent.php database/factories/ tests/Feature/Models/
git commit -m "feat(#356): add bnpl_orders and bnpl_order_events tables

Foundation for epic #355. bnpl_orders carries one row per parsed
schedule email; bnpl_order_events is its append-only audit trail, shaped
after pipeline_audit_entries including plain timestamps rather than a
\$timestamps = false model just to drop one column.

Two unique constraints, not one. unique(user_id, gmail_message_id) makes
a re-scan a no-op. unique(user_id, provider, order_ref) is what stops a
resent confirmation - same order, new message id - creating a second
planned transaction and doubling the forecast.

frequency is nullable because an unevenly spaced schedule must persist
for review rather than be forced into a cadence that misrepresents it.

Verification: 21 feature tests covering enum and money casts, all four
relations, cascade and nullOnDelete behaviour, both unique constraints,
same order ref across two users, and recordEvent. Rollback of both
migrations verified.

Refs #356
Verified: op test.filter BnplOrder passes, migrate:rollback clean"
```

---

## Task 6: Quality gates and pull request

**Files:** none — verification only.

- [ ] **Step 1: Style and static analysis on the new files**

Run: `op check.dirty`
Expected: Pint reports no style violations; PHPStan reports `[OK] No errors`.

Common failures and their fixes:
- *Missing `@property`* — the model docblock is incomplete; add the column.
- *`Unable to resolve the template type`* on a relation — the `/** @return BelongsTo<X, $this> */` annotation is missing or wrong.
- *Enum not covered in `match`* — every case needs an arm; there is no `default`.

- [ ] **Step 2: Rector dry run**

Run: `op rector`
Expected: no suggested changes for the new files. If it suggests any, apply with `op rector.fix` and re-run `op check.dirty`.

- [ ] **Step 3: Full test suite**

Run: `op test`
Expected: the whole suite green. Nothing existing reads these tables, so any failure here is a regression from the migration — most likely a `RefreshDatabase` ordering problem — and must be fixed, not skipped.

- [ ] **Step 4: Confirm no forbidden schema change**

Run: `git diff develop --stat -- database/migrations/`
Expected: exactly two files, both new. **Neither `planned_transactions` nor `transactions` may appear anywhere in the diff** — that is an explicit acceptance criterion of #356 and of the epic.

- [ ] **Step 5: Push and open the PR**

```bash
git push -u origin feat/356-bnpl-schema-models-flag
gh pr create --base develop --title "feat(#356): bnpl schema, models and feature flag" --label backend --label migration --body "$(cat <<'EOF'
Closes the foundation sub-task of epic #355.

## What

- `bnpl_orders` — one row per parsed BNPL schedule email, state plus provenance
- `bnpl_order_events` — append-only audit trail, shaped after `pipeline_audit_entries`
- `BnplProvider`, `BnplOrderStatus`, `BnplOrderEventType` enums
- `budget.bnpl_email_import` flag, default **false**

## Why two unique constraints

`unique(user_id, gmail_message_id)` makes a re-scan a no-op.
`unique(user_id, provider, order_ref)` is the one that matters for correctness: a provider
resending a confirmation gives the same order a new message id, and without it that copy
would create a second `PlannedTransaction` and double the forecast.

## Notes for review

- `frequency` is **nullable** — an unevenly spaced schedule must persist for review rather
  than be forced into a cadence that misrepresents it (#357 leaves it null, #359 routes it)
- `BnplOrderEventType` is an enum although #356's text says only provider and status are.
  A mistyped audit event is silent and permanent, and four sub-tasks write these rows
- `BnplOrder::recordEvent()` ships with no caller for the same reason — one write path
  instead of four divergent ones
- **No migration touches `planned_transactions` or `transactions`**

## Verification

- 21 feature tests, 7 unit tests
- `migrate:rollback --step=2` clean, then re-applied
- `op check.dirty` and `op test` green

Refs #356
EOF
)"
gh pr edit --add-reviewer Copilot
```

- [ ] **Step 6: Close the issue manually after merge**

PRs target `develop`, so GitHub will not auto-close. After the squash merge:

```bash
gh issue close 356 --comment "Merged to develop."
```

---

## Self-review against the spec

**Coverage of #356's acceptance criteria:**

| Criterion | Task |
|---|---|
| Migrations up and down cleanly | 4.6, 5.6, 5.8 |
| Models, factories, enums and casts in place | 2, 3, 4, 5 |
| `unique(user_id, gmail_message_id)` rejects a duplicate | 4.1 test *rejects a second order for the same gmail message* |
| `unique(user_id, provider, order_ref)` rejects a second message id | 4.1 test *rejects the same order arriving under a second message id* |
| A row persists with `frequency` null and a `review_note` | 4.1 test *frequency is null for an unsupported cadence* |
| No change to `planned_transactions` or `transactions` | 6.4 |
| `config('budget.bnpl_email_import')` defaults false | 1.3 |

**Type consistency check:** `BnplOrder::recordEvent()` is defined in Task 4 and exercised in Task 5's tests — the four affected assertions are explicitly called out as expected failures at the end of Task 4.7, so a worker running tasks in order is not surprised. `BnplOrderEventType` is created in Task 3, before both models reference it. `BnplOrderStatus::isApproved()` has no caller in this plan; it exists for #359's account-memory lookup and is unit-tested here.

**Downstream contracts this plan fixes in place** — later sub-tasks depend on these exact names:

| Symbol | Consumer |
|---|---|
| `BnplProvider::label()` | #359, builds `"Afterpay - Petbarn"` |
| `BnplOrderStatus::isApproved()` | #359, account memory |
| `BnplOrder::recordEvent()` | #359, #360, #361, #362 |
| `BnplOrder::isSettled()` | #359 step 4, #360, #361 |
| `BnplOrder::events()` | #360 |
| `unique(user_id, provider, order_ref)` | #359 step 1 early return |
