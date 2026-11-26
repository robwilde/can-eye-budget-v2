<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\AuditOutcome;
use App\Enums\TransactionDirection;
use App\Enums\TransactionSource;
use App\Http\Assistant\Tools\ApplyRule;
use App\Http\Assistant\Tools\CreateRule;
use App\Http\Assistant\Tools\CreateTag;
use App\Http\Assistant\Tools\CreateTransaction;
use App\Http\Assistant\Tools\DeleteTransaction;
use App\Http\Assistant\Tools\ListTags;
use App\Http\Assistant\Tools\PreviewRule;
use App\Http\Assistant\Tools\UnmatchTransfer;
use App\Http\Assistant\Tools\UpdateBudget;
use App\Http\Assistant\Tools\UpdateRule;
use App\Http\Assistant\Tools\UpdateTag;
use App\Http\Assistant\Tools\UpdateTransaction;
use App\Http\Assistant\Tools\UpdateTransfer;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\Budget;
use App\Models\BudgetPlan;
use App\Models\Category;
use App\Models\Tag;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserRule;
use App\Models\UserRuleGroup;
use App\Services\Assistant\AssistantToolRegistry;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-07-15 10:00:00'));
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create(['is_tracked' => true]);
});

function writeTool(string $class, User $user, array $arguments): array
{
    return app($class)->handle($user, $arguments);
}

function ruleDefinition(Category $category, string $needle = 'COFFEE'): array
{
    return [
        'triggers' => [['field' => 'description', 'operator' => 'contains', 'value' => $needle]],
        'actions' => [['type' => 'set_category', 'value' => (string) $category->id]],
    ];
}

function coffeeTransactions(User $user, Account $account, int $count): void
{
    foreach (range(1, $count) as $i) {
        Transaction::factory()->for($user)->for($account)->create(['description' => 'COFFEE '.$i, 'category_id' => null, 'post_date' => '2026-07-01']);
    }
}

it('saves a rule created by the assistant as inactive', function () {
    UserRuleGroup::factory()->for($this->user)->create();
    $category = Category::factory()->for($this->user)->create();

    $result = writeTool(CreateRule::class, $this->user, ['name' => 'Coffee', ...ruleDefinition($category), 'is_active' => true]);

    $rule = UserRule::query()->sole();

    expect($result)->toHaveKey('data')->and($rule->is_active)->toBeFalse()->and($rule->is_auto_apply)->toBeFalse()->and($rule->user_id)->toBe($this->user->id);
});

it('refuses a rule with an invalid definition, the AI action or another user\'s group', function () {
    $group = UserRuleGroup::factory()->for($this->user)->create();
    $category = Category::factory()->for($this->user)->create();
    $foreign = UserRuleGroup::factory()->create();

    expect(writeTool(CreateRule::class, $this->user, ['name' => 'x', 'triggers' => [['field' => 'bogus', 'operator' => 'contains', 'value' => 'a']], 'actions' => ruleDefinition($category)['actions']]))->toHaveKey('error')
        ->and(writeTool(CreateRule::class, $this->user, ['name' => 'x', 'triggers' => ruleDefinition($category)['triggers'], 'actions' => [['type' => 'suggest_category_with_ai', 'value' => 'x']]]))->toHaveKey('error')
        ->and(writeTool(CreateRule::class, $this->user, ['name' => 'x', 'group_id' => $foreign->id, ...ruleDefinition($category)]))->toBe(['error' => 'Not found.'])
        ->and(UserRule::query()->count())->toBe(0)
        ->and($group->exists)->toBeTrue();
});

it('refuses to apply a rule with a stale preview token', function () {
    $category = Category::factory()->for($this->user)->create();
    $rule = UserRule::factory()->for($this->user)->inactive()->create(ruleDefinition($category));
    coffeeTransactions($this->user, $this->account, 20);

    $preview = writeTool(PreviewRule::class, $this->user, ['rule_id' => $rule->id]);

    expect($preview['would_change'])->toBe(20);

    writeTool(UpdateRule::class, $this->user, ['rule_id' => $rule->id, 'triggers' => [['field' => 'description', 'operator' => 'contains', 'value' => 'COFFEE 1']]]);

    $result = writeTool(ApplyRule::class, $this->user, ['rule_id' => $rule->id, 'apply_token' => $preview['apply_token']]);

    expect($result['applied'])->toBeFalse()->and($result)->toHaveKey('apply_token')->and($result['apply_token'])->not->toBe($preview['apply_token'])
        ->and(Transaction::query()->whereNotNull('category_id')->count())->toBe(0);
});

it('applies a rule with a fresh preview token and refuses a forged or reused-after-change one', function () {
    $category = Category::factory()->for($this->user)->create();
    $rule = UserRule::factory()->for($this->user)->inactive()->create(ruleDefinition($category));
    coffeeTransactions($this->user, $this->account, 3);
    $preview = writeTool(PreviewRule::class, $this->user, ['rule_id' => $rule->id]);

    expect(writeTool(ApplyRule::class, $this->user, ['rule_id' => $rule->id, 'apply_token' => $preview['apply_token'].'x'])['applied'])->toBeFalse()
        ->and(writeTool(ApplyRule::class, $this->user, ['rule_id' => $rule->id, 'apply_token' => 'garbage'])['applied'])->toBeFalse()
        ->and(Transaction::query()->whereNotNull('category_id')->count())->toBe(0);

    coffeeTransactions($this->user, $this->account, 1);

    expect(writeTool(ApplyRule::class, $this->user, ['rule_id' => $rule->id, 'apply_token' => $preview['apply_token']])['applied'])->toBeFalse();

    $fresh = writeTool(PreviewRule::class, $this->user, ['rule_id' => $rule->id]);
    $applied = writeTool(ApplyRule::class, $this->user, ['rule_id' => $rule->id, 'apply_token' => $fresh['apply_token']]);

    expect($applied['applied'])->toBeTrue()
        ->and(Transaction::query()->where('category_id', $category->id)->count())->toBe(4)
        ->and($rule->fresh()->is_active)->toBeFalse();
});

it('refuses an apply token issued for another user\'s rule', function () {
    $category = Category::factory()->for($this->user)->create();
    $rule = UserRule::factory()->for($this->user)->inactive()->create(ruleDefinition($category));
    $other = User::factory()->create();
    $preview = writeTool(PreviewRule::class, $this->user, ['rule_id' => $rule->id]);

    expect(writeTool(ApplyRule::class, $other, ['rule_id' => $rule->id, 'apply_token' => $preview['apply_token']]))->toBe(['error' => 'Not found.']);
});

it('expires a rule apply token after 30 minutes', function () {
    $category = Category::factory()->for($this->user)->create();
    $rule = UserRule::factory()->for($this->user)->inactive()->create(ruleDefinition($category));
    coffeeTransactions($this->user, $this->account, 2);
    $preview = writeTool(PreviewRule::class, $this->user, ['rule_id' => $rule->id]);

    $this->travel(31)->minutes();

    $result = writeTool(ApplyRule::class, $this->user, ['rule_id' => $rule->id, 'apply_token' => $preview['apply_token']]);

    expect($result['applied'])->toBeFalse()->and(Transaction::query()->whereNotNull('category_id')->count())->toBe(0);

    $this->travelBack();
    $this->travelTo(Carbon::parse('2026-07-15 10:29:00'));

    expect(writeTool(ApplyRule::class, $this->user, ['rule_id' => $rule->id, 'apply_token' => $preview['apply_token']])['applied'])->toBeTrue();
});

it('deactivates a rule when the assistant changes its conditions', function () {
    $category = Category::factory()->for($this->user)->create();
    $rule = UserRule::factory()->for($this->user)->create(ruleDefinition($category));

    writeTool(UpdateRule::class, $this->user, ['rule_id' => $rule->id, 'name' => 'Renamed', 'description' => 'Notes']);

    expect($rule->fresh()->is_active)->toBeTrue()->and($rule->fresh()->name)->toBe('Renamed');

    writeTool(UpdateRule::class, $this->user, ['rule_id' => $rule->id, 'triggers' => [['field' => 'description', 'operator' => 'contains', 'value' => 'TEA']]]);

    expect($rule->fresh()->is_active)->toBeFalse();

    UserRule::query()->whereKey($rule->id)->update(['is_active' => true]);
    writeTool(UpdateRule::class, $this->user, ['rule_id' => $rule->id, 'strict_mode' => ! $rule->strict_mode]);

    expect($rule->fresh()->is_active)->toBeFalse();

    UserRule::query()->whereKey($rule->id)->update(['is_active' => true]);
    writeTool(UpdateRule::class, $this->user, ['rule_id' => $rule->id, 'strict_mode' => $rule->fresh()->strict_mode]);

    expect($rule->fresh()->is_active)->toBeTrue();
});

it('answers not found when updating another user\'s transaction', function () {
    $victim = User::factory()->create();
    $category = Category::factory()->for($this->user)->create();
    $row = Transaction::factory()->for($victim)->for(Account::factory()->for($victim))->create(['category_id' => null]);

    $result = writeTool(UpdateTransaction::class, $this->user, ['transaction_id' => $row->id, 'notes' => 'x']);
    $recategorise = writeTool(UpdateTransaction::class, $this->user, ['transaction_id' => $row->id, 'category_id' => $category->id]);

    expect($result)->toBe(['error' => 'Not found.'])->and($recategorise)->toBe(['error' => 'Not found.'])
        ->and($row->fresh()->category_id)->toBeNull()->and($row->fresh()->notes)->toBeNull();
});

it('refuses to delete a split line through the transaction tool', function () {
    $parent = Transaction::factory()->for($this->user)->for($this->account)->create();
    $line = Transaction::factory()->for($this->user)->for($this->account)->create(['split_parent_id' => $parent->id]);

    $result = writeTool(DeleteTransaction::class, $this->user, ['transaction_id' => $line->id]);

    expect($result)->toHaveKey('error')->and(Transaction::query()->whereKey($line->id)->exists())->toBeTrue()
        ->and(AuditEvent::query()->where('action', 'assistant.delete_transaction')->count())->toBe(0);
});

it('creates, updates and deletes a manual transaction through the API writer', function () {
    $category = Category::factory()->for($this->user)->create();

    $created = writeTool(CreateTransaction::class, $this->user, ['account_id' => $this->account->id, 'amount' => 1234, 'direction' => 'debit', 'post_date' => '2026-07-10', 'description' => 'Lunch']);
    $id = $created['data']['id'];

    expect($created['data']['amount'])->toBe(1234)->and(Transaction::query()->findOrFail($id)->source)->toBe(TransactionSource::Manual);

    $updated = writeTool(UpdateTransaction::class, $this->user, ['transaction_id' => $id, 'category_id' => $category->id, 'notes' => 'with Sam', 'description' => 'Team lunch']);

    expect($updated['data']['category_id'])->toBe($category->id)->and($updated['data']['notes'])->toBe('with Sam');

    $id = $updated['data']['id'];
    $kept = writeTool(UpdateTransaction::class, $this->user, ['transaction_id' => $id, 'description' => 'Team lunch 2', 'category_id' => null, 'notes' => '']);

    expect($kept['data']['category_id'])->toBe($category->id)->and($kept['data']['notes'])->toBe('with Sam');

    $id = $kept['data']['id'];
    $cleared = writeTool(UpdateTransaction::class, $this->user, ['transaction_id' => $id, 'clear_category' => true, 'clear_notes' => true]);
    $id = $cleared['data']['id'];

    expect($cleared['data']['category_id'])->toBeNull()->and($cleared['data']['notes'])->toBeNull();

    expect(writeTool(DeleteTransaction::class, $this->user, ['transaction_id' => $id]))->toBe(['deleted' => true, 'transaction_id' => $id])
        ->and(Transaction::query()->whereKey($id)->exists())->toBeFalse();
});

it('refuses a transaction on an untracked or foreign account and a hidden or foreign category', function () {
    $untracked = Account::factory()->for($this->user)->create(['is_tracked' => false]);
    $foreignAccount = Account::factory()->create();
    $foreignCategory = Category::factory()->create();
    $base = ['amount' => 100, 'direction' => 'debit', 'post_date' => '2026-07-10', 'description' => 'x'];

    expect(writeTool(CreateTransaction::class, $this->user, $base + ['account_id' => $untracked->id]))->toHaveKey('error')
        ->and(writeTool(CreateTransaction::class, $this->user, $base + ['account_id' => $foreignAccount->id]))->toHaveKey('error')
        ->and(writeTool(CreateTransaction::class, $this->user, $base + ['account_id' => $this->account->id, 'category_id' => $foreignCategory->id]))->toHaveKey('error')
        ->and(writeTool(CreateTransaction::class, $this->user, array_merge($base, ['account_id' => $this->account->id, 'amount' => 0])))->toHaveKey('error')
        ->and(Transaction::query()->count())->toBe(0);
});

it('refuses to edit the amount-bearing description of an imported transaction', function () {
    $row = Transaction::factory()->for($this->user)->for($this->account)->fromRedbark()->create(['description' => 'ORIGINAL']);

    $result = writeTool(UpdateTransaction::class, $this->user, ['transaction_id' => $row->id, 'description' => 'changed']);

    expect($result)->toHaveKey('error')->and($row->fresh()->description)->toBe('ORIGINAL');
});

it('creates, lists and renames tags with the REST rules', function () {
    $created = writeTool(CreateTag::class, $this->user, ['name' => '  Holiday  ', 'colour' => '#112233']);
    $id = $created['data']['id'];

    expect(Tag::query()->findOrFail($id)->name)->toBe('Holiday')
        ->and(writeTool(CreateTag::class, $this->user, ['name' => 'Holiday']))->toHaveKey('error')
        ->and(writeTool(CreateTag::class, $this->user, ['name' => 'Bad', 'colour' => 'red']))->toHaveKey('error')
        ->and(array_column(writeTool(ListTags::class, $this->user, [])['data'], 'name'))->toBe(['Holiday'])
        ->and(writeTool(UpdateTag::class, $this->user, ['tag_id' => $id, 'name' => 'Trips'])['data']['name'])->toBe('Trips')
        ->and(writeTool(UpdateTag::class, $this->user, ['tag_id' => $id]))->toHaveKey('error')
        ->and(writeTool(UpdateTag::class, User::factory()->create(), ['tag_id' => $id, 'name' => 'Stolen']))->toBe(['error' => 'Not found.'])
        ->and(Tag::query()->findOrFail($id)->name)->toBe('Trips');
});

it('links, unlinks and rejects transfers for the user\'s own transactions only', function () {
    $other = Account::factory()->for($this->user)->create(['is_tracked' => true]);
    $out = Transaction::factory()->for($this->user)->for($this->account)->create(['amount' => 5000, 'direction' => TransactionDirection::Debit, 'post_date' => '2026-07-05']);
    $in = Transaction::factory()->for($this->user)->for($other)->create(['amount' => 5000, 'direction' => TransactionDirection::Credit, 'post_date' => '2026-07-05']);
    $stranger = User::factory()->create();

    expect(writeTool(UpdateTransfer::class, $this->user, ['transaction_id' => $out->id, 'action' => 'link', 'partner_id' => $in->id])['changed'])->toBeTrue()
        ->and($out->fresh()->transfer_pair_id)->toBe($in->id)
        ->and(writeTool(UpdateTransfer::class, $stranger, ['transaction_id' => $out->id, 'action' => 'not_a_transfer']))->toBe(['error' => 'Not found.'])
        ->and(writeTool(UnmatchTransfer::class, $stranger, ['transaction_id' => $out->id]))->toBe(['error' => 'Not found.'])
        ->and($out->fresh()->transfer_pair_id)->toBe($in->id);

    writeTool(UnmatchTransfer::class, $this->user, ['transaction_id' => $out->id]);

    expect($out->fresh()->transfer_pair_id)->toBeNull()
        ->and(writeTool(UpdateTransfer::class, $this->user, ['transaction_id' => $out->id, 'action' => 'link']))->toHaveKey('error');
});

it('updates a budget line through the plan manager and answers not found for another user\'s', function () {
    $plan = BudgetPlan::factory()->for($this->user)->create(['period_start' => '2026-07-01', 'period_end' => '2026-07-31', 'total_amount' => 0, 'expected_income' => 0, 'initialised_at' => '2026-07-01']);
    $category = Category::factory()->for($this->user)->create();
    $line = Budget::factory()->inPlan($plan)->withCategory($category)->create(['limit_amount' => 1000, 'rollover_enabled' => false]);

    $result = writeTool(UpdateBudget::class, $this->user, ['budget_id' => $line->id, 'amount' => 25000, 'rollover_enabled' => true]);

    expect($result['data']['limit_amount'] ?? $line->fresh()->limit_amount)->toBe(25000)
        ->and($line->fresh()->rollover_enabled)->toBeTrue()
        ->and(writeTool(UpdateBudget::class, User::factory()->create(), ['budget_id' => $line->id, 'amount' => 1]))->toBe(['error' => 'Not found.'])
        ->and(writeTool(UpdateBudget::class, $this->user, ['budget_id' => $line->id]))->toHaveKey('error')
        ->and(writeTool(UpdateBudget::class, $this->user, ['budget_id' => $line->id, 'amount' => -5]))->toHaveKey('error')
        ->and($line->fresh()->limit_amount)->toBe(25000);
});

it('records an audit event for each assistant write', function () {
    $category = Category::factory()->for($this->user)->create();
    UserRuleGroup::factory()->for($this->user)->create();
    $created = writeTool(CreateTransaction::class, $this->user, ['account_id' => $this->account->id, 'amount' => 100, 'direction' => 'credit', 'post_date' => '2026-07-10', 'description' => 'Refund']);
    $id = $created['data']['id'];
    $tag = writeTool(CreateTag::class, $this->user, ['name' => 'Audit'])['data']['id'];
    $rule = writeTool(CreateRule::class, $this->user, ['name' => 'R', ...ruleDefinition($category)])['data']['id'];

    $id = writeTool(UpdateTransaction::class, $this->user, ['transaction_id' => $id, 'notes' => 'n'])['data']['id'];
    writeTool(UpdateTag::class, $this->user, ['tag_id' => $tag, 'name' => 'Audit2']);
    writeTool(UpdateRule::class, $this->user, ['rule_id' => $rule, 'name' => 'R2']);
    $preview = writeTool(PreviewRule::class, $this->user, ['rule_id' => $rule]);
    writeTool(ApplyRule::class, $this->user, ['rule_id' => $rule, 'apply_token' => $preview['apply_token']]);
    writeTool(DeleteTransaction::class, $this->user, ['transaction_id' => $id]);

    $actions = AuditEvent::query()->where('user_id', $this->user->id)->orderBy('id')->pluck('action')->all();

    expect($actions)->toBe([
        'assistant.create_transaction', 'assistant.create_tag', 'assistant.create_rule', 'assistant.update_transaction',
        'assistant.update_tag', 'assistant.update_rule', 'assistant.apply_rule', 'assistant.delete_transaction',
    ])->and(AuditEvent::query()->where('action', 'assistant.create_tag')->sole()->outcome)->toBe(AuditOutcome::Success)
        ->and(AuditEvent::query()->where('action', 'assistant.create_tag')->sole()->subject_id)->toBe((string) $tag);
});

it('offers every write tool through the registry and never writes without the user', function () {
    $names = array_keys(app(AssistantToolRegistry::class)->forUser($this->user));

    expect($names)->toContain('create_transaction', 'update_transaction', 'delete_transaction', 'list_tags', 'create_tag', 'update_tag', 'update_transfer', 'unmatch_transfer', 'update_budget', 'create_rule', 'update_rule', 'preview_rule', 'apply_rule');
});

it('applies one preview token only once', function () {
    $category = Category::factory()->for($this->user)->create();
    $rule = UserRule::factory()->for($this->user)->inactive()->create(['triggers' => ruleDefinition($category)['triggers'], 'actions' => [['type' => 'append_notes', 'value' => 'checked']]]);
    coffeeTransactions($this->user, $this->account, 2);
    $preview = writeTool(PreviewRule::class, $this->user, ['rule_id' => $rule->id]);

    $first = writeTool(ApplyRule::class, $this->user, ['rule_id' => $rule->id, 'apply_token' => $preview['apply_token']]);
    $second = writeTool(ApplyRule::class, $this->user, ['rule_id' => $rule->id, 'apply_token' => $preview['apply_token']]);

    expect($first['applied'])->toBeTrue()->and($second['applied'])->toBeFalse()
        ->and(Transaction::query()->where('notes', 'like', '%checked%')->get()->every(fn (Transaction $row): bool => mb_substr_count((string) $row->notes, 'checked') === 1))->toBeTrue();
});

it('answers with a clear error when the user has no rule group and keeps the description column limit', function () {
    $category = Category::factory()->for($this->user)->create();

    expect(writeTool(CreateRule::class, $this->user, ['name' => 'x', ...ruleDefinition($category)]))->toHaveKey('error')
        ->and(UserRule::query()->count())->toBe(0);

    UserRuleGroup::factory()->for($this->user)->create();

    expect(writeTool(CreateRule::class, $this->user, ['name' => 'x', 'description' => str_repeat('a', 256), ...ruleDefinition($category)]))->toHaveKey('error');
});

it('keeps auto-apply as it was when an edit switches a rule off', function () {
    $category = Category::factory()->for($this->user)->create();
    $rule = UserRule::factory()->for($this->user)->autoApply()->create(ruleDefinition($category));

    writeTool(UpdateRule::class, $this->user, ['rule_id' => $rule->id, 'strict_mode' => ! $rule->strict_mode]);

    expect($rule->fresh()->is_active)->toBeFalse()->and($rule->fresh()->is_auto_apply)->toBeTrue();
});

it('links only transactions the transfer page offers as partners', function () {
    $other = Account::factory()->for($this->user)->create(['is_tracked' => true]);
    $out = Transaction::factory()->for($this->user)->for($this->account)->create(['amount' => 5000, 'direction' => TransactionDirection::Debit, 'post_date' => '2026-07-05']);
    $sameWay = Transaction::factory()->for($this->user)->for($other)->create(['amount' => 5000, 'direction' => TransactionDirection::Debit, 'post_date' => '2026-07-05']);
    $wrongAmount = Transaction::factory()->for($this->user)->for($other)->create(['amount' => 9999, 'direction' => TransactionDirection::Credit, 'post_date' => '2026-07-05']);

    expect(writeTool(UpdateTransfer::class, $this->user, ['transaction_id' => $out->id, 'action' => 'link', 'partner_id' => $sameWay->id]))->toBe(['error' => 'Not found.'])
        ->and(writeTool(UpdateTransfer::class, $this->user, ['transaction_id' => $out->id, 'action' => 'link', 'partner_id' => $wrongAmount->id]))->toBe(['error' => 'Not found.'])
        ->and($out->fresh()->transfer_pair_id)->toBeNull();
});

it('refuses to unmatch or reject rows the transfer review would not offer', function () {
    $plain = Transaction::factory()->for($this->user)->for($this->account)->manual()->create();

    expect(writeTool(UnmatchTransfer::class, $this->user, ['transaction_id' => $plain->id]))->toHaveKey('error')
        ->and(writeTool(UpdateTransfer::class, $this->user, ['transaction_id' => $plain->id, 'action' => 'not_a_transfer']))->toBe(['error' => 'Not found.'])
        ->and($plain->fresh()->transfer_link_source)->toBeNull()
        ->and(AuditEvent::query()->where('action', 'like', 'assistant.%')->count())->toBe(0);
});

it('audits transfer and budget writes too', function () {
    $other = Account::factory()->for($this->user)->create(['is_tracked' => true]);
    $out = Transaction::factory()->for($this->user)->for($this->account)->create(['amount' => 5000, 'direction' => TransactionDirection::Debit, 'post_date' => '2026-07-05']);
    $in = Transaction::factory()->for($this->user)->for($other)->create(['amount' => 5000, 'direction' => TransactionDirection::Credit, 'post_date' => '2026-07-05']);
    $plan = BudgetPlan::factory()->for($this->user)->create(['period_start' => '2026-07-01', 'period_end' => '2026-07-31', 'total_amount' => 0, 'expected_income' => 0, 'initialised_at' => '2026-07-01']);
    $line = Budget::factory()->inPlan($plan)->withCategory(Category::factory()->for($this->user)->create())->create(['limit_amount' => 1000]);

    writeTool(UpdateTransfer::class, $this->user, ['transaction_id' => $out->id, 'action' => 'link', 'partner_id' => $in->id]);
    writeTool(UnmatchTransfer::class, $this->user, ['transaction_id' => $out->id]);
    writeTool(UpdateBudget::class, $this->user, ['budget_id' => $line->id, 'amount' => 2000]);

    expect(AuditEvent::query()->where('user_id', $this->user->id)->orderBy('id')->pluck('action')->all())->toBe(['assistant.update_transfer', 'assistant.unmatch_transfer', 'assistant.update_budget']);
});
