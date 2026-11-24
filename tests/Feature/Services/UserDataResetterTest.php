<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\BankImport;
use App\Models\Budget;
use App\Models\BudgetPlan;
use App\Models\Category;
use App\Models\DataExport;
use App\Models\Document;
use App\Models\GmailCredential;
use App\Models\Goal;
use App\Models\Merchant;
use App\Models\PlannedTransaction;
use App\Models\RedbarkFeed;
use App\Models\Statement;
use App\Models\StatementReconciliation;
use App\Models\Tag;
use App\Models\Transaction;
use App\Models\TransactionAttachment;
use App\Models\TransactionSplit;
use App\Models\User;
use App\Models\UserRule;
use App\Services\UserCategoryProvisioner;
use App\Services\UserDataResetter;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * @return array<string, int>
 */
function resetOwnedCounts(User $user): array
{
    $counts = [];

    foreach (UserDataResetter::TABLES as $table) {
        $counts[$table] = DB::table($table)->where('user_id', $user->id)->count();
    }

    return $counts;
}

/**
 * @return array{files: list<string>}
 */
function populateUser(User $user): array
{
    Storage::fake('local');

    $account = Account::factory()->for($user)->create();
    $category = Category::factory()->create(['user_id' => $user->id]);
    $transaction = Transaction::factory()->for($user)->for($account)->debit()->create(['category_id' => $category->id]);
    Transaction::factory()->for($user)->for($account)->credit()->create();
    TransactionSplit::factory()->create(['transaction_id' => $transaction->id, 'category_id' => $category->id]);
    $plan = BudgetPlan::factory()->for($user)->create();
    Budget::factory()->inPlan($plan)->create();
    UserRule::factory()->for($user)->create();
    PlannedTransaction::factory()->for($user)->create();
    RedbarkFeed::factory()->for($user)->create();
    Tag::factory()->for($user)->create();
    Merchant::factory()->for($user)->create();
    Goal::factory()->for($user)->create();
    GmailCredential::factory()->for($user)->create();

    $statement = Statement::factory()->for($user)->create();
    $bankImport = BankImport::factory()->for($user)->create();
    $document = Document::factory()->for($user)->create();
    $attachment = TransactionAttachment::factory()->create(['transaction_id' => $transaction->id]);
    DataExport::factory()->for($user)->create();

    $reconciliation = StatementReconciliation::factory()->for($user)->create();
    $user->forceFill(['vector_store_id' => 'vs_old'])->save();
    $files = [$reconciliation->stored_path, $statement->stored_path, $bankImport->stored_path, $document->stored_path, $attachment->path, 'exports/'.$user->id.'/1.zip'];

    foreach ($files as $file) {
        Storage::disk('local')->put($file, 'bytes');
    }

    $user->update(['primary_account_id' => $account->id, 'pay_amount' => 300_000, 'pay_frequency' => 'fortnightly', 'next_pay_date' => now()->addDays(3)]);

    return ['files' => $files];
}

it('resets only the signed-in user\'s data', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    populateUser($user);
    populateUser($other);
    $otherBefore = resetOwnedCounts($other);

    expect(resetOwnedCounts($user)['accounts'])->toBeGreaterThan(0);

    app(UserDataResetter::class)->reset($user);

    $after = resetOwnedCounts($user);
    $kept = ['categories', 'category_groups', 'user_rules', 'user_rule_groups'];

    foreach ($after as $table => $count) {
        if (! in_array($table, $kept, true)) {
            expect($count)->toBe(0, $table);
        }
    }

    expect(resetOwnedCounts($other))->toBe($otherBefore)
        ->and(TransactionSplit::query()->count())->toBe(1)
        ->and(TransactionAttachment::query()->count())->toBe(1);
});

it('keeps the login, preferences and connected mailbox and clears the pay cycle and primary account', function () {
    $user = User::factory()->withTwoFactor()->create(['base_currency' => 'NZD', 'timezone' => 'Pacific/Auckland']);
    $user->updatePreferences(['theme' => 'dark']);
    populateUser($user);
    $passwordHash = $user->password;
    $secret = $user->two_factor_secret;

    app(UserDataResetter::class)->reset($user);

    $fresh = $user->fresh();

    expect($fresh->exists)->toBeTrue()
        ->and($fresh->password)->toBe($passwordHash)
        ->and($fresh->two_factor_secret)->toBe($secret)
        ->and($fresh->base_currency)->toBe('NZD')
        ->and($fresh->timezone)->toBe('Pacific/Auckland')
        ->and($fresh->preferences->theme->value)->toBe('dark')
        ->and($fresh->pay_amount)->toBeNull()
        ->and($fresh->pay_frequency)->toBeNull()
        ->and($fresh->next_pay_date)->toBeNull()
        ->and($fresh->primary_account_id)->toBeNull()
        ->and($fresh->vector_store_id)->toBeNull()
        ->and(GmailCredential::query()->where('user_id', $user->id)->count())->toBe(1);
});

it('gives the user the default categories again after a reset', function () {
    $user = User::factory()->create();
    app(UserCategoryProvisioner::class)->provision($user);
    populateUser($user);

    app(UserDataResetter::class)->reset($user);

    expect(Category::query()->where('user_id', $user->id)->where('name', 'Groceries')->exists())->toBeTrue()
        ->and(UserRule::query()->where('user_id', $user->id)->exists())->toBeTrue();
});

it('deletes the stored files of the reset user only', function () {
    $user = User::factory()->create();
    $files = populateUser($user)['files'];
    Storage::disk('local')->put('statements/other-users.pdf', 'keep');

    app(UserDataResetter::class)->reset($user);

    foreach ($files as $file) {
        Storage::disk('local')->assertMissing($file);
    }

    Storage::disk('local')->assertExists('statements/other-users.pdf');
});

it('rolls back the whole reset when a delete fails', function () {
    $user = User::factory()->create();
    $files = populateUser($user)['files'];
    $before = resetOwnedCounts($user);
    $payAmount = $user->fresh()->pay_amount;

    DB::unprepared("CREATE TRIGGER fail_budget_delete BEFORE DELETE ON budgets BEGIN SELECT RAISE(ABORT, 'delete failed'); END");

    expect(fn () => app(UserDataResetter::class)->reset($user))->toThrow(QueryException::class);

    expect(resetOwnedCounts($user))->toBe($before)
        ->and($user->fresh()->pay_amount)->toBe($payAmount);

    foreach ($files as $file) {
        Storage::disk('local')->assertExists($file);
    }
});

it('leaves the audit trail in place', function () {
    $user = User::factory()->create();
    AuditEvent::query()->create(['user_id' => $user->id, 'action' => 'test.action', 'outcome' => 'success']);

    app(UserDataResetter::class)->reset($user);

    expect(AuditEvent::query()->where('user_id', $user->id)->where('action', 'test.action')->exists())->toBeTrue();
});
