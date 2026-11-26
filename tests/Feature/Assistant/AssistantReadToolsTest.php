<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Contracts\AssistantTool;
use App\Enums\TransactionDirection;
use App\Http\Assistant\Tools\GetIncomeStatement;
use App\Http\Assistant\Tools\GetNetWorthHistory;
use App\Http\Assistant\Tools\ListAccounts;
use App\Http\Assistant\Tools\ListCategories;
use App\Http\Assistant\Tools\ListPayees;
use App\Http\Assistant\Tools\SearchTransactions;
use App\Models\Account;
use App\Models\AccountDailyBalance;
use App\Models\Budget;
use App\Models\Category;
use App\Models\Payee;
use App\Models\PlannedTransaction;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserRule;
use App\Services\Assistant\AssistantToolRegistry;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-07-15 10:00:00'));
});

function runTool(string $class, User $user, array $arguments = []): array
{
    return app($class)->handle($user, $arguments);
}

it('returns only the requesting user\'s transactions from the search tool', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();
    $mine = Transaction::factory()->for($a)->for(Account::factory()->for($a))->create(['description' => 'MINE', 'post_date' => '2026-07-01']);
    Transaction::factory()->for($b)->for(Account::factory()->for($b))->create(['description' => 'THEIRS', 'post_date' => '2026-07-01']);

    $result = runTool(SearchTransactions::class, $a);

    expect(array_column($result['data'], 'id'))->toBe([$mine->id]);
});

it('treats blank and null arguments as not given and refuses array dates without throwing', function () {
    $user = User::factory()->create();
    Transaction::factory()->for($user)->for(Account::factory()->for($user))->create(['post_date' => '2026-07-01']);

    $blank = runTool(SearchTransactions::class, $user, ['from' => '', 'to' => ' ', 'account_id' => '', 'payee' => null, 'min_amount' => '']);

    expect($blank['data'])->toHaveCount(1)
        ->and(runTool(SearchTransactions::class, $user, ['from' => ['2026-01-01'], 'to' => '2026-02-01']))->toHaveKey('error')
        ->and(runTool(GetIncomeStatement::class, $user, ['from' => '', 'to' => ''])['months'])->not->toBe([])
        ->and(runTool(GetNetWorthHistory::class, $user, ['from' => ['x'], 'to' => '2026-07-01']))->toHaveKey('error');
});

it('uses a full 12 month default window at the end of a long month', function () {
    $this->travelTo(CarbonImmutable::parse('2027-01-31 10:00:00'));
    $user = User::factory()->create();
    Transaction::factory()->for($user)->for(Account::factory()->for($user))->create(['amount' => 500, 'post_date' => '2026-02-10']);

    expect(array_column(runTool(GetIncomeStatement::class, $user)['months'], 'month'))->toBe(['2026-02']);
});

it('refuses another user\'s account or category filter instead of leaking it', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();
    $theirAccount = Account::factory()->for($b)->create();
    $theirCategory = Category::factory()->for($b)->create();

    expect(runTool(SearchTransactions::class, $a, ['account_id' => $theirAccount->id]))->toHaveKey('error')
        ->and(runTool(SearchTransactions::class, $a, ['category_id' => $theirCategory->id]))->toHaveKey('error');
});

it('filters transactions by date, payee text and absolute amount and pages at 50', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $make = fn (string $description, int $amount, string $date) => Transaction::factory()->for($user)->for($account)->create(['description' => $description, 'amount' => $amount, 'post_date' => $date]);
    $make('Coffee Shop', 450, '2026-07-02');
    $make('Coffee Shop', 450, '2026-06-02');
    $make('Rent 100%', 200000, '2026-07-03');
    $make('Rent 1000', 100, '2026-07-04');

    expect(array_column(runTool(SearchTransactions::class, $user, ['payee' => 'coffee', 'from' => '2026-07-01'])['data'], 'description'))->toBe(['Coffee Shop'])
        ->and(count(runTool(SearchTransactions::class, $user, ['min_amount' => 1000])['data']))->toBe(1)
        ->and(count(runTool(SearchTransactions::class, $user, ['max_amount' => 1000])['data']))->toBe(3)
        ->and(count(runTool(SearchTransactions::class, $user, ['payee' => '100%'])['data']))->toBe(1)
        ->and(runTool(SearchTransactions::class, $user, ['from' => 'nope']))->toHaveKey('error');

    foreach (range(1, 52) as $i) {
        $make('Bulk', 100, '2026-05-01');
    }

    $first = runTool(SearchTransactions::class, $user, ['payee' => 'Bulk']);
    $second = runTool(SearchTransactions::class, $user, ['payee' => 'Bulk', 'page' => 2]);

    expect(count($first['data']))->toBe(50)->and($first['has_more'])->toBeTrue()
        ->and(count($second['data']))->toBe(2)->and($second['has_more'])->toBeFalse();
});

it('does not show fields the API hides in any tool result', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    Transaction::factory()->for($user)->for($account)->create(['enrich_data' => ['secret' => 'x']]);
    Payee::factory()->for($user)->create();

    $json = json_encode([runTool(SearchTransactions::class, $user), runTool(ListAccounts::class, $user), runTool(ListPayees::class, $user)]);

    expect($json)->not->toContain('enrich_data')->not->toContain('secret')->not->toContain('feed')->not->toContain('access_url')->not->toContain('path');
});

it('returns money from tools as integer cents', function () {
    $user = User::factory()->create();
    Account::factory()->for($user)->create(['name' => 'Everyday', 'balance' => 123456]);

    $row = runTool(ListAccounts::class, $user)['data'][0];

    expect($row['balance'])->toBe(123456)->and($row['balance'])->toBeInt();
});

it('pages list tools at 50 without skipping a row', function () {
    $user = User::factory()->create();
    Category::factory()->for($user)->count(52)->sequence(fn ($s) => ['name' => sprintf('Cat %02d', $s->index)])->create();

    $first = runTool(ListCategories::class, $user);
    $second = runTool(ListCategories::class, $user, ['page' => 2]);

    expect($first['data'])->toHaveCount(50)->and($first['has_more'])->toBeTrue()
        ->and(array_column($second['data'], 'name'))->toBe(['Cat 50', 'Cat 51'])->and($second['has_more'])->toBeFalse();
});

it('lists only the user\'s own categories, budgets, rules and planned transactions', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    Category::factory()->for($user)->create(['name' => 'Mine']);
    Category::factory()->for($other)->create(['name' => 'Theirs']);
    Budget::factory()->for($other)->create();
    UserRule::factory()->for($other)->create();
    PlannedTransaction::factory()->for($other)->create();

    expect(array_column(runTool(ListCategories::class, $user)['data'], 'name'))->toBe(['Mine'])
        ->and(app(AssistantToolRegistry::class)->forUser($user))->toHaveKeys(['list_rules', 'get_budgets', 'list_planned_transactions']);

    foreach (['get_budgets', 'list_rules', 'list_planned_transactions'] as $name) {
        expect(app(AssistantToolRegistry::class)->forUser($user)[$name]->handle($user, [])['data'])->toBe([]);
    }
});

it('counts transfers in the income statement tool by the tracked rule', function () {
    $user = User::factory()->create();
    $tracked = Account::factory()->for($user)->create(['is_tracked' => true]);
    $other = Account::factory()->for($user)->create(['is_tracked' => true]);
    $untracked = Account::factory()->for($user)->create(['is_tracked' => false]);
    $pair = function (Account $from, Account $to, int $amount) use ($user): void {
        $out = Transaction::factory()->for($user)->for($from)->create(['amount' => $amount, 'direction' => TransactionDirection::Debit, 'post_date' => '2026-07-05']);
        $in = Transaction::factory()->for($user)->for($to)->create(['amount' => $amount, 'direction' => TransactionDirection::Credit, 'post_date' => '2026-07-05', 'transfer_pair_id' => $out->id]);
        $out->update(['transfer_pair_id' => $in->id]);
    };
    $pair($tracked, $untracked, 70000);
    $pair($tracked, $other, 30000);

    $result = runTool(GetIncomeStatement::class, $user, ['from' => '2026-07-01', 'to' => '2026-07-31']);

    expect($result['months'])->toHaveCount(1)
        ->and($result['months'][0]['spend'])->toBe(70000)
        ->and($result['months'][0]['income'])->toBe(0);
});

it('caps the income statement tool at 36 monthly buckets', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    foreach (range(0, 59) as $i) {
        Transaction::factory()->for($user)->for($account)->create(['amount' => 1000, 'post_date' => CarbonImmutable::parse('2026-07-10')->subMonths($i)->toDateString()]);
    }

    $result = runTool(GetIncomeStatement::class, $user, ['all_time' => true]);

    expect($result['months'])->toHaveCount(36)->and($result['truncated'])->toBeTrue()
        ->and($result['months'][35]['month'])->toBe('2026-07')
        ->and($result['months'][0]['month'])->toBe('2023-08');
});

it('caps net worth history at 400 points', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $rows = [];

    foreach (CarbonImmutable::parse('2026-07-15')->subDays(499)->daysUntil('2026-07-16') as $day) {
        $rows[] = ['account_id' => $account->id, 'balance_date' => $day->toDateString(), 'start_balance' => 1000, 'end_balance' => 1000, 'inflows' => 0, 'outflows' => 0, 'currency' => 'AUD', 'created_at' => now(), 'updated_at' => now()];
    }

    AccountDailyBalance::query()->insert($rows);

    $result = runTool(GetNetWorthHistory::class, $user, ['from' => '2025-01-01', 'to' => '2026-07-15']);

    expect($result['points'])->toHaveCount(400)->and($result['truncated'])->toBeTrue()
        ->and($result['points'][399])->toBe(['date' => '2026-07-15', 'net_worth' => 1000]);
});

it('hides preview-only tools from users without preview features', function () {
    $registry = new AssistantToolRegistry;
    $registry->register(fn (): AssistantTool => new class implements AssistantTool
    {
        public function name(): string
        {
            return 'preview_tool';
        }

        public function description(): string
        {
            return 'x';
        }

        public function parameters(): array
        {
            return ['type' => 'object', 'properties' => new stdClass];
        }

        public function requiresPreview(): bool
        {
            return true;
        }

        public function handle(User $user, array $arguments): array
        {
            return [];
        }
    });
    $off = User::factory()->create();
    $on = User::factory()->create();
    $on->updatePreferences(['previewFeatures' => true]);

    expect($registry->forUser($off))->toBe([])->and($registry->forUser($on->fresh()))->toHaveKey('preview_tool');
});

it('registers every read tool for an ordinary user', function () {
    expect(array_keys(app(AssistantToolRegistry::class)->forUser(User::factory()->create())))->toBe([
        'list_accounts', 'search_transactions', 'list_categories', 'list_payees', 'list_rules', 'get_budgets', 'list_planned_transactions', 'get_income_statement', 'get_net_worth_history',
    ]);
});
