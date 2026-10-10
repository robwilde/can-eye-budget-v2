<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\CategorySource;
use App\Enums\PipelineTrigger;
use App\Enums\TransactionDirection;
use App\Enums\TransactionSource;
use App\Livewire\Dashboard;
use App\Models\Account;
use App\Models\Category;
use App\Models\PipelineAuditEntry;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserRule;
use App\Services\TransactionAnalysisPipeline;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

function firstImportCategory(string $parent, string $child): Category
{
    $parentCategory = Category::query()->firstOrCreate(['name' => $parent], ['is_hidden' => false]);

    return Category::factory()->withParent($parentCategory)->create(['name' => $child]);
}

function firstImportRow(User $user, Account $account, string $description, int $daysAgo, int $amount, TransactionDirection $direction = TransactionDirection::Debit, array $overrides = []): Transaction
{
    return Transaction::factory()->for($user)->for($account)->create(array_merge([
        'description' => $description,
        'amount' => $amount,
        'direction' => $direction,
        'source' => TransactionSource::Redbark,
        'post_date' => CarbonImmutable::now()->subDays($daysAgo),
        'merchant_name' => null,
        'clean_description' => null,
        'category_id' => null,
        'category_source' => null,
        'transfer_pair_id' => null,
    ], $overrides));
}

function seedFirstImportFixture(User $user, Account $account): void
{
    foreach ([2, 9, 16, 23, 30] as $daysAgo) {
        firstImportRow($user, $account, 'WOOLWORTHS 1234 BRISBANE', $daysAgo, 8_500);
    }

    foreach ([3, 33] as $daysAgo) {
        firstImportRow($user, $account, 'Spotify P1234', $daysAgo, 1_299);
    }

    foreach ([1, 15, 29, 43] as $daysAgo) {
        firstImportRow($user, $account, 'ACME PTY LTD PAYROLL', $daysAgo, 250_000, TransactionDirection::Credit);
    }

    firstImportRow($user, $account, 'MYSTERY SHOP 77', 5, 4_200);
    firstImportRow($user, $account, 'UNKNOWN VENDOR 9', 12, 3_100);
}

beforeEach(function () {
    $this->user = User::factory()->create(['primary_account_id' => null, 'pay_amount' => null, 'pay_frequency' => null, 'next_pay_date' => null]);
    $this->account = Account::factory()->for($this->user)->create();
    $this->groceries = Category::query()->firstOrCreate(['name' => 'Groceries', 'parent_id' => null], ['is_hidden' => false]);
    $this->streaming = firstImportCategory('Entertainment', 'Streaming');
    $this->salary = firstImportCategory('Income', 'Salary');
});

test('generates rules from the first import and categorises most transactions without user action', function () {
    seedFirstImportFixture($this->user, $this->account);

    $run = app(TransactionAnalysisPipeline::class)->run($this->user, PipelineTrigger::Sync);

    $rules = UserRule::query()->where('user_id', $this->user->id)->get();
    $transactions = Transaction::query()->where('user_id', $this->user->id)->get();
    $categorised = $transactions->whereNotNull('category_id');

    expect($run->is_first_sync)->toBeTrue()
        ->and($run->stages_completed)->toContain('rule-mining')
        ->and($rules)->toHaveCount(3)
        ->and($categorised->count())->toBeGreaterThan($transactions->count() / 2)
        ->and($transactions->where('description', 'WOOLWORTHS 1234 BRISBANE')->every(fn (Transaction $t): bool => $t->category_id === $this->groceries->id && $t->category_source === CategorySource::Rule))->toBeTrue()
        ->and($transactions->where('description', 'Spotify P1234')->every(fn (Transaction $t): bool => $t->category_id === $this->streaming->id))->toBeTrue()
        ->and($transactions->where('description', 'ACME PTY LTD PAYROLL')->every(fn (Transaction $t): bool => $t->category_id === $this->salary->id))->toBeTrue()
        ->and($transactions->where('description', 'MYSTERY SHOP 77')->first()->category_id)->toBeNull();

    $audit = PipelineAuditEntry::query()->where('pipeline_run_id', $run->id)->where('stage', 'rule-mining')->where('action', 'rules_created')->sole();
    expect($audit->metadata['rules_created'])->toBe(3);
});

test('never overrides a manually categorised transaction when a generated rule matches it', function () {
    seedFirstImportFixture($this->user, $this->account);
    $pipeline = app(TransactionAnalysisPipeline::class);
    $pipeline->run($this->user, PipelineTrigger::Sync);

    $other = Category::factory()->create(['name' => 'Household']);
    $manual = firstImportRow($this->user, $this->account, 'WOOLWORTHS 5678 TOOWONG', 0, 120_000, TransactionDirection::Debit, [
        'category_id' => $other->id,
        'category_source' => CategorySource::Manual,
    ]);
    $automatic = firstImportRow($this->user, $this->account, 'WOOLWORTHS 9999 INDOOROOPILLY', 0, 6_400);

    $pipeline->run($this->user->refresh(), PipelineTrigger::Sync);

    expect($manual->refresh()->category_id)->toBe($other->id)
        ->and($manual->category_source)->toBe(CategorySource::Manual)
        ->and($automatic->refresh()->category_id)->toBe($this->groceries->id);
});

test('skips a generated rule that would contradict a manual categorisation on the first import', function () {
    seedFirstImportFixture($this->user, $this->account);
    $other = Category::factory()->create(['name' => 'Household']);
    $manual = firstImportRow($this->user, $this->account, 'WOOLWORTHS 5678 TOOWONG', 4, 120_000, TransactionDirection::Debit, [
        'category_id' => $other->id,
        'category_source' => CategorySource::Manual,
    ]);

    app(TransactionAnalysisPipeline::class)->run($this->user, PipelineTrigger::Sync);

    $values = UserRule::query()->where('user_id', $this->user->id)->get()->map(fn (UserRule $rule): string => $rule->triggers[0]['value'])->all();

    expect($manual->refresh()->category_id)->toBe($other->id)
        ->and($manual->category_source)->toBe(CategorySource::Manual)
        ->and($values)->toContain('Spotify')
        ->and($values)->not->toContain('WOOLWORTHS');
});

test('categorises a newly seen transaction on the second sync using the generated rules', function () {
    seedFirstImportFixture($this->user, $this->account);
    $pipeline = app(TransactionAnalysisPipeline::class);

    $pipeline->run($this->user, PipelineTrigger::Sync);

    $newRow = firstImportRow($this->user, $this->account, 'WOOLWORTHS 9999 INDOOROOPILLY', 0, 6_400);

    $second = $pipeline->run($this->user->refresh(), PipelineTrigger::Sync);

    $newRow->refresh();

    expect($second->is_first_sync)->toBeFalse()
        ->and($second->stages_skipped)->toContain('rule-mining')
        ->and($newRow->category_id)->toBe($this->groceries->id)
        ->and($newRow->category_source)->toBe(CategorySource::Rule);
});

test('only creates rules for merchants present in the import', function () {
    firstImportRow($this->user, $this->account, 'WOOLWORTHS 1234 BRISBANE', 2, 8_500);

    app(TransactionAnalysisPipeline::class)->run($this->user, PipelineTrigger::Sync);

    $rules = UserRule::query()->where('user_id', $this->user->id)->get();

    expect($rules)->toHaveCount(1)
        ->and($rules->first()->triggers[0]['value'])->toBe('WOOLWORTHS');
});

test('does not mine again after the first sync', function () {
    firstImportRow($this->user, $this->account, 'WOOLWORTHS 1234 BRISBANE', 2, 8_500);
    $this->user->forceFill(['primary_account_id' => $this->account->id])->save();

    $run = app(TransactionAnalysisPipeline::class)->run($this->user, PipelineTrigger::Sync);

    expect($run->is_first_sync)->toBeFalse()
        ->and($run->stages_skipped)->toContain('rule-mining')
        ->and(UserRule::query()->where('user_id', $this->user->id)->exists())->toBeFalse();
});

test('reports how many were categorised and how many need attention on the dashboard after the first import', function () {
    seedFirstImportFixture($this->user, $this->account);

    app(TransactionAnalysisPipeline::class)->run($this->user, PipelineTrigger::Sync);

    Livewire::actingAs($this->user->refresh())
        ->test(Dashboard::class)
        ->assertSeeHtml('data-test="dashboard-first-import-card"')
        ->assertSeeText('11 transactions categorised by 3 new rules, 2 need your attention.')
        ->assertSeeHtml('data-test="dashboard-first-import-link"');
});

test('drops the first import report once the window has passed', function () {
    seedFirstImportFixture($this->user, $this->account);

    app(TransactionAnalysisPipeline::class)->run($this->user, PipelineTrigger::Sync);

    Livewire::actingAs($this->user->refresh())
        ->test(Dashboard::class)
        ->assertSeeHtml('data-test="dashboard-first-import-card"');

    $this->travel(8)->days();

    Livewire::actingAs($this->user->refresh())
        ->test(Dashboard::class)
        ->assertDontSeeHtml('data-test="dashboard-first-import-card"');
});

test('the first import report uses singular wording for a single rule and row', function () {
    firstImportRow($this->user, $this->account, 'WOOLWORTHS 1234 BRISBANE', 2, 8_500);

    app(TransactionAnalysisPipeline::class)->run($this->user, PipelineTrigger::Sync);

    Livewire::actingAs($this->user->refresh())
        ->test(Dashboard::class)
        ->assertSeeText('1 transaction categorised by 1 new rule, 0 need your attention.');
});

test('the first import report ignores rules and rows from later syncs', function () {
    seedFirstImportFixture($this->user, $this->account);
    $pipeline = app(TransactionAnalysisPipeline::class);
    $pipeline->run($this->user, PipelineTrigger::Sync);

    $this->travel(1)->minute();
    firstImportRow($this->user, $this->account, 'WOOLWORTHS 9999 INDOOROOPILLY', 0, 6_400);
    firstImportRow($this->user, $this->account, 'LATER MYSTERY 1', 0, 900);
    $pipeline->run($this->user->refresh(), PipelineTrigger::Sync);

    Livewire::actingAs($this->user->refresh())
        ->test(Dashboard::class)
        ->assertSeeText('11 transactions categorised by 3 new rules, 2 need your attention.');
});

test('creates a salary rule from what every pay shares when payment references vary', function () {
    foreach ([1, 15, 29, 43] as $index => $daysAgo) {
        firstImportRow($this->user, $this->account, sprintf('DIRECT CREDIT 000%d ACME PTY LTD', 123 + $index), $daysAgo, 250_000, TransactionDirection::Credit, ['merchant_name' => 'Acme Pty Ltd']);
    }

    app(TransactionAnalysisPipeline::class)->run($this->user, PipelineTrigger::Sync);

    $rule = UserRule::query()->where('user_id', $this->user->id)->sole();

    expect($rule->triggers[0]['value'])->toBe('ACME')
        ->and(Transaction::query()->where('user_id', $this->user->id)->pluck('category_id')->unique()->all())->toBe([$this->salary->id]);
});

test('categorises the next pay on the second sync even though its reference differs', function () {
    foreach ([1, 15, 29, 43] as $index => $daysAgo) {
        firstImportRow($this->user, $this->account, sprintf('DIRECT CREDIT 000%d ACME PTY LTD', 123 + $index), $daysAgo, 250_000, TransactionDirection::Credit, ['merchant_name' => 'Acme Pty Ltd']);
    }
    $pipeline = app(TransactionAnalysisPipeline::class);
    $pipeline->run($this->user, PipelineTrigger::Sync);

    $nextPay = firstImportRow($this->user, $this->account, 'DIRECT CREDIT 000999 ACME PTY LTD', 0, 250_000, TransactionDirection::Credit, ['merchant_name' => 'Acme Pty Ltd']);
    $second = $pipeline->run($this->user->refresh(), PipelineTrigger::Sync);

    expect($second->is_first_sync)->toBeFalse()
        ->and($nextPay->refresh()->category_id)->toBe($this->salary->id)
        ->and($nextPay->category_source)->toBe(CategorySource::Rule);
});

test('skips the salary rule when pays share nothing distinctive', function () {
    foreach ([1, 15, 29, 43] as $index => $daysAgo) {
        firstImportRow($this->user, $this->account, sprintf('DIRECT CREDIT 000%d', 123 + $index), $daysAgo, 250_000, TransactionDirection::Credit, ['merchant_name' => 'Acme Pty Ltd']);
    }

    app(TransactionAnalysisPipeline::class)->run($this->user, PipelineTrigger::Sync);

    expect(UserRule::query()->where('user_id', $this->user->id)->exists())->toBeFalse();
});

test('does not file unrelated payees under groceries because of a short seed token', function () {
    firstImportRow($this->user, $this->account, 'GERALDINE CAFE', 2, 900);

    app(TransactionAnalysisPipeline::class)->run($this->user, PipelineTrigger::Sync);

    expect(Transaction::query()->where('user_id', $this->user->id)->sole()->category_id)->toBeNull();
});

test('mines only once for a user whose setup stays incomplete across syncs', function () {
    $card = Account::factory()->for($this->user)->creditCard()->create();
    $this->account->delete();
    firstImportRow($this->user, $card, 'WOOLWORTHS 1234 BRISBANE', 2, 8_500);

    $first = app(TransactionAnalysisPipeline::class)->run($this->user, PipelineTrigger::Sync);

    firstImportRow($this->user, $card, 'SPOTIFY P1234', 3, 1_299);

    $second = app(TransactionAnalysisPipeline::class)->run($this->user->fresh(), PipelineTrigger::Sync);

    expect($first->is_first_sync)->toBeTrue()
        ->and($first->stages_completed)->toContain('rule-mining')
        ->and($second->is_first_sync)->toBeTrue()
        ->and($second->stages_skipped)->toContain('rule-mining')
        ->and(PipelineAuditEntry::query()->where('stage', 'rule-mining')->where('action', 'rules_created')->count())->toBe(1);
});

test('the first import report does not count a manually categorised row a mined rule matched', function () {
    foreach ([2, 9, 16] as $daysAgo) {
        firstImportRow($this->user, $this->account, 'WOOLWORTHS 1234 BRISBANE', $daysAgo, 8_500);
    }
    firstImportRow($this->user, $this->account, 'WOOLWORTHS 5678 SYDNEY', 5, 6_000, overrides: [
        'category_id' => $this->groceries->id,
        'category_source' => CategorySource::Manual,
    ]);

    app(TransactionAnalysisPipeline::class)->run($this->user, PipelineTrigger::Sync);

    Livewire::actingAs($this->user->refresh())
        ->test(Dashboard::class)
        ->assertSeeText('3 transactions categorised by 1 new rule, 0 need your attention.');
});

test('the first import report still shows after a second sync with incomplete setup', function () {
    $card = Account::factory()->for($this->user)->creditCard()->create();
    $this->account->delete();
    firstImportRow($this->user, $card, 'WOOLWORTHS 1234 BRISBANE', 2, 8_500);
    $pipeline = app(TransactionAnalysisPipeline::class);
    $pipeline->run($this->user, PipelineTrigger::Sync);

    $this->travel(1)->minute();
    $pipeline->run($this->user->refresh(), PipelineTrigger::Sync);

    Livewire::actingAs($this->user->refresh())
        ->test(Dashboard::class)
        ->assertSeeText('1 transaction categorised by 1 new rule, 0 need your attention.');
});

test('keeps a credit-guarded salary rule when the employer is named like a seeded merchant', function () {
    foreach ([1, 15, 29, 43] as $index => $daysAgo) {
        firstImportRow($this->user, $this->account, sprintf('DIRECT CREDIT 000%d WOOLWORTHS', 123 + $index), $daysAgo, 250_000, TransactionDirection::Credit, ['merchant_name' => 'Woolworths']);
    }
    foreach ([2, 9, 16] as $daysAgo) {
        firstImportRow($this->user, $this->account, 'WOOLWORTHS 1234 BRISBANE', $daysAgo, 8_500);
    }

    app(TransactionAnalysisPipeline::class)->run($this->user, PipelineTrigger::Sync);

    $transactions = Transaction::query()->where('user_id', $this->user->id)->get();
    expect($transactions->where('direction', TransactionDirection::Credit)->every(fn (Transaction $t): bool => $t->category_id === $this->salary->id))->toBeTrue()
        ->and($transactions->where('direction', TransactionDirection::Debit)->every(fn (Transaction $t): bool => $t->category_id === $this->groceries->id))->toBeTrue();
});

test('does not mine a salary rule from legal suffixes alone', function () {
    foreach ([1, 15, 29, 43] as $index => $daysAgo) {
        firstImportRow($this->user, $this->account, sprintf('DIRECT CREDIT 000%d PTY LTD', 123 + $index), $daysAgo, 250_000, TransactionDirection::Credit, ['merchant_name' => 'Pty Ltd']);
    }

    app(TransactionAnalysisPipeline::class)->run($this->user, PipelineTrigger::Sync);

    expect(UserRule::query()->where('user_id', $this->user->id)->exists())->toBeFalse();
});

test('does not mine a salary rule from punctuated legal suffixes alone', function (string $suffix) {
    foreach ([1, 15, 29, 43] as $index => $daysAgo) {
        firstImportRow($this->user, $this->account, sprintf('DIRECT CREDIT 000%d %s', 123 + $index, $suffix), $daysAgo, 250_000, TransactionDirection::Credit, ['merchant_name' => $suffix]);
    }

    app(TransactionAnalysisPipeline::class)->run($this->user, PipelineTrigger::Sync);

    expect(UserRule::query()->where('user_id', $this->user->id)->exists())->toBeFalse();
})->with(['hyphen' => 'PTY-LTD', 'slash' => 'PTY/LTD']);

test('the first import report still renders after a mined rule is deleted', function () {
    seedFirstImportFixture($this->user, $this->account);
    app(TransactionAnalysisPipeline::class)->run($this->user, PipelineTrigger::Sync);

    UserRule::query()->where('user_id', $this->user->id)->orderBy('id')->firstOrFail()->delete();

    Livewire::actingAs($this->user->refresh())
        ->test(Dashboard::class)
        ->assertOk()
        ->assertSeeHtml('data-test="dashboard-first-import-card"')
        ->assertSeeText('new rules');
});

test('the first import link opens a list of every uncategorised row it counted', function () {
    seedFirstImportFixture($this->user, $this->account);
    firstImportRow($this->user, $this->account, 'ANCIENT MYSTERY 55', 200, 900);
    app(TransactionAnalysisPipeline::class)->run($this->user, PipelineTrigger::Sync);

    $html = Livewire::actingAs($this->user->refresh())->test(Dashboard::class)->html();

    expect(preg_match('/data-test="dashboard-first-import-link"/', $html))->toBe(1)
        ->and(preg_match('/href="([^"]*categorised=uncategorised[^"]*)"/', $html, $matches))->toBe(1);

    $this->actingAs($this->user)
        ->get(html_entity_decode($matches[1]))
        ->assertSee('ANCIENT MYSTERY 55')
        ->assertSee('MYSTERY SHOP 77')
        ->assertSee('UNKNOWN VENDOR 9');
});

test('does not scan for manual contradictions for seeds that match no imported row', function () {
    firstImportRow($this->user, $this->account, 'WOOLWORTHS 1234 BRISBANE', 2, 8_500);

    $scans = 0;
    DB::listen(function ($query) use (&$scans): void {
        if (str_contains($query->sql, 'transaction_splits') && str_contains($query->sql, 'limit 1000')) {
            $scans++;
        }
    });

    app(TransactionAnalysisPipeline::class)->run($this->user, PipelineTrigger::Sync);

    expect(UserRule::query()->where('user_id', $this->user->id)->count())->toBe(1)
        ->and($scans)->toBe(1);
});

test('creates no rules and writes no audit marker when the marker insert fails, then creates the full set on retry', function () {
    seedFirstImportFixture($this->user, $this->account);

    $failed = false;
    PipelineAuditEntry::creating(function (PipelineAuditEntry $entry) use (&$failed): void {
        if ($entry->action === 'rules_created' && ! $failed) {
            $failed = true;

            throw new RuntimeException('audit insert failed');
        }
    });

    app(TransactionAnalysisPipeline::class)->run($this->user, PipelineTrigger::Sync);

    $this->user->forceFill(['primary_account_id' => null, 'pay_amount' => null, 'pay_frequency' => null, 'next_pay_date' => null])->save();

    expect($failed)->toBeTrue()
        ->and(UserRule::query()->where('user_id', $this->user->id)->count())->toBe(0)
        ->and(PipelineAuditEntry::query()->where('action', 'rules_created')->exists())->toBeFalse();

    $run = app(TransactionAnalysisPipeline::class)->run($this->user, PipelineTrigger::Sync);

    expect($run->stages_completed)->toContain('rule-mining')
        ->and(UserRule::query()->where('user_id', $this->user->id)->count())->toBe(3);
});

test('a manual run on a fresh user does not mine and the following first sync still does', function () {
    seedFirstImportFixture($this->user, $this->account);

    $manual = app(TransactionAnalysisPipeline::class)->run($this->user, PipelineTrigger::Manual);

    expect($manual->stages_skipped)->toContain('rule-mining')
        ->and(PipelineAuditEntry::query()->where('action', 'rules_created')->exists())->toBeFalse()
        ->and(UserRule::query()->where('user_id', $this->user->id)->exists())->toBeFalse();

    $this->user->forceFill(['primary_account_id' => null, 'pay_amount' => null, 'pay_frequency' => null, 'next_pay_date' => null])->save();

    $sync = app(TransactionAnalysisPipeline::class)->run($this->user->refresh(), PipelineTrigger::Sync);

    expect($sync->stages_completed)->toContain('rule-mining')
        ->and(PipelineAuditEntry::query()->where('action', 'rules_created')->exists())->toBeTrue()
        ->and(UserRule::query()->where('user_id', $this->user->id)->count())->toBe(3);
});

test('the first import report does not count a split row as needing attention', function () {
    seedFirstImportFixture($this->user, $this->account);
    $split = firstImportRow($this->user, $this->account, 'SPLIT SHOP 88', 6, 5_000);
    $split->splits()->create(['category_id' => $this->groceries->id, 'amount' => $split->amount]);

    app(TransactionAnalysisPipeline::class)->run($this->user, PipelineTrigger::Sync);

    Livewire::actingAs($this->user->refresh())
        ->test(Dashboard::class)
        ->assertSeeText('2 need your attention.');
});

test('does not mine a salary rule from payroll labels alone', function () {
    foreach ([1, 15, 29, 43] as $index => $daysAgo) {
        firstImportRow($this->user, $this->account, sprintf('DIRECT CREDIT 000%d PAYROLL', 123 + $index), $daysAgo, 250_000, TransactionDirection::Credit, ['merchant_name' => 'Payroll']);
    }

    app(TransactionAnalysisPipeline::class)->run($this->user, PipelineTrigger::Sync);

    expect(UserRule::query()->where('user_id', $this->user->id)->exists())->toBeFalse();
});

test('still mines a salary rule when the employer is named beside payroll labels', function () {
    foreach ([1, 15, 29, 43] as $index => $daysAgo) {
        firstImportRow($this->user, $this->account, sprintf('DIRECT CREDIT 000%d ACME PAYROLL', 123 + $index), $daysAgo, 250_000, TransactionDirection::Credit, ['merchant_name' => 'Acme']);
    }

    app(TransactionAnalysisPipeline::class)->run($this->user, PipelineTrigger::Sync);

    expect(UserRule::query()->where('user_id', $this->user->id)->sole()->triggers[0]['value'])->toBe('ACME');
});

test('retries a failed first attempt on the next sync after setup stages completed', function () {
    seedFirstImportFixture($this->user, $this->account);

    $failed = false;
    PipelineAuditEntry::creating(function (PipelineAuditEntry $entry) use (&$failed): void {
        if ($entry->action === 'rules_created' && ! $failed) {
            $failed = true;

            throw new RuntimeException('audit insert failed');
        }
    });

    $first = app(TransactionAnalysisPipeline::class)->run($this->user, PipelineTrigger::Sync);
    $this->user->refresh();

    expect($failed)->toBeTrue()
        ->and($first->stages_failed)->not->toBeEmpty()
        ->and($this->user->primary_account_id)->not->toBeNull()
        ->and(UserRule::query()->where('user_id', $this->user->id)->count())->toBe(0);

    $second = app(TransactionAnalysisPipeline::class)->run($this->user, PipelineTrigger::Sync);

    expect($second->is_first_sync)->toBeFalse()
        ->and($second->stages_completed)->toContain('rule-mining')
        ->and(UserRule::query()->where('user_id', $this->user->id)->count())->toBe(3);
});

test('does not mine for a configured user who never attempted a first import', function () {
    seedFirstImportFixture($this->user, $this->account);
    $this->user->forceFill(['primary_account_id' => $this->account->id])->save();

    $run = app(TransactionAnalysisPipeline::class)->run($this->user->refresh(), PipelineTrigger::Sync);

    expect($run->stages_skipped)->toContain('rule-mining')
        ->and(UserRule::query()->where('user_id', $this->user->id)->exists())->toBeFalse();
});

test('does not seed a merchant that only a manual row mentions', function () {
    firstImportRow($this->user, $this->account, 'NETFLIX.COM', 4, 1_699, overrides: ['source' => TransactionSource::Manual]);
    firstImportRow($this->user, $this->account, 'WOOLWORTHS 1234 BRISBANE', 2, 8_500);

    app(TransactionAnalysisPipeline::class)->run($this->user, PipelineTrigger::Sync);

    $rules = UserRule::query()->where('user_id', $this->user->id)->get();

    expect($rules)->toHaveCount(1)
        ->and(mb_strtoupper($rules->first()->triggers[0]['value']))->not->toContain('NETFLIX');
});

test('does not mine a salary rule from payroll labels that carry punctuation', function () {
    foreach ([1, 15, 29, 43] as $index => $daysAgo) {
        firstImportRow($this->user, $this->account, sprintf('DIRECT CREDIT 000%d PAYROLL:', 123 + $index), $daysAgo, 250_000, TransactionDirection::Credit, ['merchant_name' => 'Payroll']);
    }

    app(TransactionAnalysisPipeline::class)->run($this->user, PipelineTrigger::Sync);

    expect(UserRule::query()->where('user_id', $this->user->id)->exists())->toBeFalse();
});

test('the first import report shows after a retry run that is not flagged as a first sync', function () {
    seedFirstImportFixture($this->user, $this->account);

    $failed = false;
    PipelineAuditEntry::creating(function (PipelineAuditEntry $entry) use (&$failed): void {
        if ($entry->action === 'rules_created' && ! $failed) {
            $failed = true;

            throw new RuntimeException('audit insert failed');
        }
    });

    app(TransactionAnalysisPipeline::class)->run($this->user, PipelineTrigger::Sync);
    $retry = app(TransactionAnalysisPipeline::class)->run($this->user->refresh(), PipelineTrigger::Sync);

    expect($retry->is_first_sync)->toBeFalse()
        ->and($retry->stages_completed)->toContain('rule-mining');

    Livewire::actingAs($this->user->refresh())
        ->test(Dashboard::class)
        ->assertSeeHtml('data-test="dashboard-first-import-card"')
        ->assertSeeText('11 transactions categorised by 3 new rules, 2 need your attention.');
});

test('the first import report counts rules applied by a later sync and not rows imported after mining', function () {
    seedFirstImportFixture($this->user, $this->account);

    $blocking = true;
    Transaction::updating(function (Transaction $transaction) use (&$blocking): void {
        if ($blocking && $transaction->category_source === CategorySource::Rule) {
            throw new RuntimeException('rule application failed');
        }
    });

    $first = app(TransactionAnalysisPipeline::class)->run($this->user, PipelineTrigger::Sync);
    $blocking = false;

    expect($first->stages_completed)->toContain('rule-mining')
        ->and(collect($first->stages_failed)->pluck('stage'))->toContain('user-rules')
        ->and(Transaction::query()->where('user_id', $this->user->id)->whereNotNull('category_id')->exists())->toBeFalse();

    $this->travel(1)->hours();
    firstImportRow($this->user, $this->account, 'WOOLWORTHS 9999 TOOWONG', 0, 7_000);

    app(TransactionAnalysisPipeline::class)->run($this->user->refresh(), PipelineTrigger::Sync);

    Livewire::actingAs($this->user->refresh())
        ->test(Dashboard::class)
        ->assertSeeText('11 transactions categorised by 3 new rules, 2 need your attention.');
});

test('an empty first sync does not stop mining once history arrives', function () {
    app(TransactionAnalysisPipeline::class)->run($this->user, PipelineTrigger::Sync);

    expect(PipelineAuditEntry::query()->where('stage', 'rule-mining')->where('action', 'rules_created')->exists())->toBeFalse();

    seedFirstImportFixture($this->user, $this->account);
    app(TransactionAnalysisPipeline::class)->run($this->user, PipelineTrigger::Sync);

    expect(UserRule::query()->where('user_id', $this->user->id)->exists())->toBeTrue();
});

test('the first import card follows versioned rows to their current state', function () {
    seedFirstImportFixture($this->user, $this->account);
    app(TransactionAnalysisPipeline::class)->run($this->user, PipelineTrigger::Sync);

    $summary = app(App\Services\FirstImportSummary::class);
    $before = $summary->for($this->user);

    $ruled = Transaction::query()->where('user_id', $this->user->id)->where('category_source', CategorySource::Rule->value)->current()->first();
    $ruled->createChild(['category_id' => null, 'category_source' => null]);

    $uncategorised = Transaction::query()->where('user_id', $this->user->id)->whereNull('category_id')->current()->first();
    $uncategorised->createChild(['notes' => 'edited']);

    $after = $summary->for($this->user);

    expect($after['categorised'])->toBe($before['categorised'] - 1)
        ->and($after['needs_attention'])->toBe($before['needs_attention'] + 1);
});
