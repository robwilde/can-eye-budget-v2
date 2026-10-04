<?php

declare(strict_types=1);

use App\Enums\PayFrequency;
use App\Enums\RecurrenceFrequency;
use App\Enums\TransactionDirection;
use App\Enums\TransactionSource;
use App\Enums\TransactionStatus;
use App\Enums\TransferLinkSource;
use App\Livewire\CalendarView;
use App\Livewire\Dashboard;
use App\Livewire\Dashboard\PayCycleCalendar;
use App\Models\Account;
use App\Models\Category;
use App\Models\PlannedTransaction;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Projection\MonthlyProjectionService;
use App\Services\Reports\ReportAggregator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

const MATRIX_AMOUNT = 12_345;

const MATRIX_ENTERED_KINDS = [
    'expense' => ['spend' => MATRIX_AMOUNT, 'income' => 0],
    'income' => ['spend' => 0, 'income' => MATRIX_AMOUNT],
    'pending expense' => ['spend' => MATRIX_AMOUNT, 'income' => 0],
    'transfer tracked to tracked' => ['spend' => 0, 'income' => 0],
    'transfer tracked to untracked' => ['spend' => MATRIX_AMOUNT, 'income' => 0],
    'transfer untracked to tracked' => ['spend' => 0, 'income' => MATRIX_AMOUNT],
    'transfer untracked to untracked' => ['spend' => 0, 'income' => 0],
    'transfer category unpaired' => ['spend' => 0, 'income' => 0],
    'transfer category marked not a transfer' => ['spend' => MATRIX_AMOUNT, 'income' => 0],
];

const MATRIX_PLANNED_KINDS = [
    'expense' => ['spend' => MATRIX_AMOUNT, 'income' => 0],
    'income' => ['spend' => 0, 'income' => MATRIX_AMOUNT],
    'inactive expense' => ['spend' => 0, 'income' => 0],
    'transfer tracked to tracked' => ['spend' => 0, 'income' => 0],
    'transfer tracked to untracked' => ['spend' => MATRIX_AMOUNT, 'income' => 0],
    'transfer untracked to tracked' => ['spend' => 0, 'income' => MATRIX_AMOUNT],
    'transfer untracked to untracked' => ['spend' => 0, 'income' => 0],
    'transfer category without destination' => ['spend' => 0, 'income' => 0],
];

beforeEach(function (): void {
    fake()->seed(20261004);
    $this->travelTo(CarbonImmutable::create(2026, 6, 15, 10));

    $this->user = User::factory()->create([
        'pay_amount' => 500_000,
        'pay_frequency' => PayFrequency::Fortnightly,
        'next_pay_date' => '2026-06-25',
    ]);
    $this->tracked = Account::factory()->for($this->user)->create(['name' => 'Everyday', 'balance' => 1_000_000]);
    $this->trackedSavings = Account::factory()->for($this->user)->savings()->create(['name' => 'Saver']);
    $this->untracked = Account::factory()->for($this->user)->create(['name' => 'Landlord', 'is_tracked' => false]);
    $this->untrackedOther = Account::factory()->for($this->user)->create(['name' => 'Friend', 'is_tracked' => false]);
    $this->user->update(['primary_account_id' => $this->tracked->id]);

    $this->groceries = Category::factory()->create(['name' => 'Groceries']);
    $this->transferCategory = Category::factory()->create(['name' => 'Transfer']);

    $this->actingAs($this->user);
});

function matrixPosted(User $user, Account $account, TransactionDirection $direction, array $overrides = []): Transaction
{
    return Transaction::factory()->for($user)->for($account)->create([
        'amount' => MATRIX_AMOUNT,
        'direction' => $direction,
        'description' => 'MATRIX ROW',
        'clean_description' => null,
        'post_date' => '2026-06-12',
        'status' => TransactionStatus::Posted,
        'source' => TransactionSource::Manual,
        ...$overrides,
    ]);
}

function matrixPair(User $user, Account $from, Account $to): void
{
    $debit = matrixPosted($user, $from, TransactionDirection::Debit);
    $credit = matrixPosted($user, $to, TransactionDirection::Credit, ['transfer_pair_id' => $debit->id]);
    $debit->update(['transfer_pair_id' => $credit->id]);
}

function matrixSeedEntered(object $t, string $kind): void
{
    match ($kind) {
        'expense' => matrixPosted($t->user, $t->tracked, TransactionDirection::Debit, ['category_id' => $t->groceries->id]),
        'income' => matrixPosted($t->user, $t->tracked, TransactionDirection::Credit),
        'pending expense' => matrixPosted($t->user, $t->tracked, TransactionDirection::Debit, ['status' => TransactionStatus::Pending]),
        'transfer tracked to tracked' => matrixPair($t->user, $t->tracked, $t->trackedSavings),
        'transfer tracked to untracked' => matrixPair($t->user, $t->tracked, $t->untracked),
        'transfer untracked to tracked' => matrixPair($t->user, $t->untracked, $t->tracked),
        'transfer untracked to untracked' => matrixPair($t->user, $t->untracked, $t->untrackedOther),
        'transfer category unpaired' => matrixPosted($t->user, $t->tracked, TransactionDirection::Debit, ['category_id' => $t->transferCategory->id]),
        'transfer category marked not a transfer' => matrixPosted($t->user, $t->tracked, TransactionDirection::Debit, [
            'category_id' => $t->transferCategory->id,
            'transfer_link_source' => TransferLinkSource::Unlinked,
        ]),
    };
}

function matrixPlan(User $user, Account $account, TransactionDirection $direction, array $overrides = []): PlannedTransaction
{
    return PlannedTransaction::factory()->for($user)->create([
        'account_id' => $account->id,
        'amount' => MATRIX_AMOUNT,
        'direction' => $direction,
        'description' => 'MATRIX PLAN',
        'start_date' => '2026-06-18',
        'frequency' => RecurrenceFrequency::DontRepeat,
        'is_active' => true,
        ...$overrides,
    ]);
}

function matrixSeedPlanned(object $t, string $kind): void
{
    match ($kind) {
        'expense' => matrixPlan($t->user, $t->tracked, TransactionDirection::Debit, ['category_id' => $t->groceries->id]),
        'income' => matrixPlan($t->user, $t->tracked, TransactionDirection::Credit),
        'inactive expense' => matrixPlan($t->user, $t->tracked, TransactionDirection::Debit, ['is_active' => false]),
        'transfer tracked to tracked' => matrixPlan($t->user, $t->tracked, TransactionDirection::Debit, ['transfer_to_account_id' => $t->trackedSavings->id]),
        'transfer tracked to untracked' => matrixPlan($t->user, $t->tracked, TransactionDirection::Debit, ['transfer_to_account_id' => $t->untracked->id]),
        'transfer untracked to tracked' => matrixPlan($t->user, $t->untracked, TransactionDirection::Debit, ['transfer_to_account_id' => $t->tracked->id]),
        'transfer untracked to untracked' => matrixPlan($t->user, $t->untracked, TransactionDirection::Debit, ['transfer_to_account_id' => $t->untrackedOther->id]),
        'transfer category without destination' => matrixPlan($t->user, $t->tracked, TransactionDirection::Debit, ['category_id' => $t->transferCategory->id]),
    };
}

function matrixAtomTotals(array $atoms): array
{
    $totals = ['spend' => 0, 'income' => 0];

    foreach ($atoms as $atom) {
        $totals[$atom['direction'] === TransactionDirection::Credit->value ? 'income' : 'spend'] += $atom['total'];
    }

    return $totals;
}

function matrixEnteredSurface(User $user, string $surface): array
{
    $start = CarbonImmutable::create(2026, 6, 1);
    $end = CarbonImmutable::create(2026, 6, 30)->endOfDay();

    return match ($surface) {
        'calendar month totals' => (static function (): array {
            $totals = Livewire::test(CalendarView::class)->instance()->monthTotals;

            return ['spend' => $totals['spend'], 'income' => $totals['income']];
        })(),
        'pay cycle calendar totals' => (static function (): array {
            $totals = Livewire::test(PayCycleCalendar::class)->instance()->totals;

            return ['spend' => $totals['posted'], 'income' => $totals['income']];
        })(),
        'report actuals' => matrixAtomTotals(app(ReportAggregator::class)->atoms($user, 'actual', $start, $end)),
        'dashboard spend last 7 days' => ['spend' => Livewire::test(Dashboard::class)->instance()->spendLast7Days['sum']],
    };
}

function matrixPlannedSurface(User $user, string $surface): array
{
    $start = CarbonImmutable::create(2026, 6, 1);
    $end = CarbonImmutable::create(2026, 6, 30)->endOfDay();

    return match ($surface) {
        'calendar projected totals' => Livewire::test(CalendarView::class)->instance()->projectedTotals,
        'pay cycle calendar planned' => ['counted' => Livewire::test(PayCycleCalendar::class)->instance()->totals['planned']],
        'report plan' => matrixAtomTotals(app(ReportAggregator::class)->atoms($user, 'plan', $start, $end)),
        'balance projection' => (static function () use ($user): array {
            $projection = app(MonthlyProjectionService::class)->forUser($user->fresh(), 1);
            $last = $projection->points[array_key_last($projection->points)];

            return ['net' => $last->balanceCents - $projection->startingBalanceCents];
        })(),
        'needed until payday' => ['spend' => $user->fresh()->totalNeededUntilPayday()],
    };
}

const MATRIX_SURFACES_COUNTING_UNTRACKED_TRANSFERS = ['calendar month totals', 'calendar projected totals'];

function matrixExpected(array $flow, array $actual, string $surface, string $kind): array
{
    if (preg_match('/^transfer (tracked|untracked)/', $kind) === 1 && ! in_array($surface, MATRIX_SURFACES_COUNTING_UNTRACKED_TRANSFERS, true)) {
        $flow = ['spend' => 0, 'income' => 0];
    }

    $derived = [
        'spend' => $flow['spend'],
        'income' => $flow['income'],
        'net' => $flow['income'] - $flow['spend'],
        'counted' => $flow['income'] + $flow['spend'],
    ];

    return array_intersect_key($derived, $actual);
}

test('entered transaction is classified consistently', function (string $surface, string $kind): void {
    matrixSeedEntered($this, $kind);

    $actual = matrixEnteredSurface($this->user, $surface);

    expect($actual)->toEqual(matrixExpected(MATRIX_ENTERED_KINDS[$kind], $actual, $surface, $kind));
})->with([
    'calendar month totals',
    'pay cycle calendar totals',
    'report actuals',
    'dashboard spend last 7 days',
])->with(array_keys(MATRIX_ENTERED_KINDS));

test('planned transaction is classified consistently', function (string $surface, string $kind): void {
    matrixSeedPlanned($this, $kind);

    $actual = matrixPlannedSurface($this->user, $surface);

    expect($actual)->toEqual(matrixExpected(MATRIX_PLANNED_KINDS[$kind], $actual, $surface, $kind));
})->with([
    'calendar projected totals',
    'pay cycle calendar planned',
    'report plan',
    'balance projection',
    'needed until payday',
])->with(array_keys(MATRIX_PLANNED_KINDS));
