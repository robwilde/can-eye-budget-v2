<?php

/** @noinspection PhpUnhandledExceptionInspection */

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\RuleActionType;
use App\Enums\RuleTriggerField;
use App\Enums\RuleTriggerOperator;
use App\Enums\TransactionDirection;
use App\Livewire\TransactionModal;
use App\Livewire\UserRuleManager;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserRule;
use App\Models\UserRuleGroup;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-15 09:00:00'));
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create();
    $this->groceries = Category::factory()->for($this->user)->create(['name' => 'Groceries', 'is_hidden' => false]);
    $this->dining = Category::factory()->for($this->user)->create(['name' => 'Dining', 'is_hidden' => false]);
});

function promptTxn(User $user, Account $account, string $description = 'WOOLWORTHS 1234', array $overrides = []): Transaction
{
    return Transaction::factory()->manual()->create(array_merge([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'description' => $description,
        'merchant_name' => null,
        'clean_description' => null,
        'category_id' => null,
        'amount' => 1000,
        'direction' => TransactionDirection::Debit,
        'post_date' => '2026-06-10',
        'transfer_pair_id' => null,
    ], $overrides));
}

function promptEdit(User $user, Transaction $transaction, Category $category)
{
    return Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $transaction->id)
        ->set('categoriseMatching', false)
        ->set('categoryId', $category->id)
        ->call('save')
        ->assertHasNoErrors();
}

function promptRule(User $user, Category $category, bool $active = true, string $match = 'COFFEE'): UserRule
{
    $group = UserRuleGroup::factory()->for($user)->create(['order' => 1]);

    return UserRule::factory()->for($user)->for($group, 'group')->create([
        'triggers' => [['field' => RuleTriggerField::Description->value, 'operator' => RuleTriggerOperator::Contains->value, 'value' => $match]],
        'actions' => [['type' => RuleActionType::SetCategory->value, 'value' => (string) $category->id]],
        'is_active' => $active,
        'order' => 1,
    ]);
}

it('offers a prefilled rule after a manual category change', function () {
    $transaction = promptTxn($this->user, $this->account);

    $component = promptEdit($this->user, $transaction, $this->groceries)
        ->assertSet('rulePrompt.categoryId', $this->groceries->id)
        ->assertSee('Create rule');

    $component->call('createRuleFromPrompt');
    $target = parse_url($component->effects['redirect'], PHP_URL_QUERY);
    parse_str($target, $query);

    expect($query['rule_category'])->toBe((string) $this->groceries->id)
        ->and($query['rule_match'])->not->toBe('')
        ->and(UserRuleGroup::query()->where('user_id', $this->user->id)->count())->toBe(1);

    Livewire::actingAs($this->user)
        ->withQueryParams($query)
        ->test(UserRuleManager::class)
        ->assertSet('showRuleModal', true)
        ->assertSet('triggers.0.field', RuleTriggerField::Description->value)
        ->assertSet('triggers.0.operator', RuleTriggerOperator::Contains->value)
        ->assertSet('triggers.0.value', $query['rule_match'])
        ->assertSet('actions.0.type', RuleActionType::SetCategory->value)
        ->assertSet('actions.0.value', (string) $this->groceries->id);
});

it('does not offer a rule when the category was not changed by hand', function () {
    $transaction = promptTxn($this->user, $this->account, overrides: ['category_id' => $this->groceries->id]);

    Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $transaction->id)
        ->set('notes', 'edited')
        ->call('save')
        ->assertSet('rulePrompt', null);
});

it('suppresses the rule prompt for 24 hours after hide for today', function () {
    promptEdit($this->user, promptTxn($this->user, $this->account), $this->groceries)->call('hideRulePromptForToday')->assertSet('rulePrompt', null);

    $this->travelTo(CarbonImmutable::parse('2026-06-15 11:00:00'));
    promptEdit($this->user->fresh(), promptTxn($this->user, $this->account, 'ALDI 9'), $this->groceries)->assertSet('rulePrompt', null);

    $this->travelTo(CarbonImmutable::parse('2026-06-16 10:00:00'));
    promptEdit($this->user->fresh(), promptTxn($this->user, $this->account, 'IGA 7'), $this->groceries)->assertSet('rulePrompt.categoryId', $this->groceries->id);
});

it('skips the prompt when an active rule already sets that category', function () {
    promptRule($this->user, $this->dining);
    $transaction = promptTxn($this->user, $this->account, 'COFFEE CLUB 5');

    promptEdit($this->user, $transaction, $this->dining)->assertSet('rulePrompt', null);
});

it('still prompts when only an inactive rule matches', function () {
    promptRule($this->user, $this->dining, active: false);
    $transaction = promptTxn($this->user, $this->account, 'COFFEE CLUB 5');

    promptEdit($this->user, $transaction, $this->dining)->assertSet('rulePrompt.categoryId', $this->dining->id);
});

it('still prompts when the matching active rule sets another category', function () {
    promptRule($this->user, $this->groceries);
    $transaction = promptTxn($this->user, $this->account, 'COFFEE CLUB 5');

    promptEdit($this->user, $transaction, $this->dining)->assertSet('rulePrompt.categoryId', $this->dining->id);
});

it('does not prompt after the user turned prompts off', function () {
    $this->user->updatePreferences(['rulePromptEnabled' => false]);

    promptEdit($this->user->fresh(), promptTxn($this->user, $this->account), $this->groceries)->assertSet('rulePrompt', null);
});

it('stores dont show this again as a disabled preference', function () {
    promptEdit($this->user, promptTxn($this->user, $this->account), $this->groceries)->call('disableRulePrompts')->assertSet('rulePrompt', null);

    expect($this->user->fresh()->preferences->rulePromptEnabled)->toBeFalse();
});

it('re-enabling rule suggestions clears the hide-for-today pause', function () {
    $this->user->updatePreferences(['rulePromptEnabled' => false, 'rulePromptHiddenUntil' => CarbonImmutable::now()->addDay()->toIso8601String()]);

    Livewire::actingAs($this->user->fresh())
        ->test('pages::settings.preferences')
        ->assertSet('rulePromptEnabled', false)
        ->set('rulePromptEnabled', true)
        ->call('save')
        ->assertHasNoErrors();

    expect($this->user->fresh()->preferences->rulePromptHiddenUntil)->toBeNull()
        ->and($this->user->fresh()->preferences->rulePromptEnabled)->toBeTrue();

    promptEdit($this->user->fresh(), promptTxn($this->user, $this->account, 'ALDI 9'), $this->groceries)->assertSet('rulePrompt.categoryId', $this->groceries->id);
});

it('keeps prompts enabled and the pause when preferences are saved untouched', function () {
    promptEdit($this->user, promptTxn($this->user, $this->account), $this->groceries)->call('hideRulePromptForToday');
    $until = $this->user->fresh()->preferences->rulePromptHiddenUntil;

    Livewire::actingAs($this->user->fresh())
        ->test('pages::settings.preferences')
        ->assertSet('rulePromptEnabled', true)
        ->assertSee('Suggestions are paused until')
        ->call('save')
        ->assertHasNoErrors();

    $preferences = $this->user->fresh()->preferences;

    expect($preferences->rulePromptEnabled)->toBeTrue()
        ->and($preferences->rulePromptHiddenUntil?->equalTo($until))->toBeTrue();
});

it('lets the user resume paused suggestions without changing the switch', function () {
    promptEdit($this->user, promptTxn($this->user, $this->account), $this->groceries)->call('hideRulePromptForToday');

    Livewire::actingAs($this->user->fresh())
        ->test('pages::settings.preferences')
        ->call('resumeRuleSuggestions')
        ->assertDontSee('Suggestions are paused until');

    $preferences = $this->user->fresh()->preferences;

    expect($preferences->rulePromptEnabled)->toBeTrue()
        ->and($preferences->rulePromptHiddenUntil)->toBeNull();
});

it('audits hiding rule prompts for today', function () {
    $other = User::factory()->create();

    promptEdit($this->user, promptTxn($this->user, $this->account), $this->groceries)->call('hideRulePromptForToday');

    $event = AuditEvent::query()->where('action', 'livewire.transaction-modal.hideRulePromptForToday')->sole();

    expect($event->user_id)->toBe($this->user->id)
        ->and($this->user->fresh()->preferences->rulePromptHiddenUntil)->not->toBeNull()
        ->and($other->fresh()->preferences->rulePromptHiddenUntil)->toBeNull();
});

it('ignores a prefilled rule link for another user group or a category that is not theirs', function () {
    $stranger = User::factory()->create();
    $group = UserRuleGroup::factory()->for($stranger)->create();
    $foreignCategory = Category::factory()->for($stranger)->create(['is_hidden' => false]);

    Livewire::actingAs($this->user)
        ->withQueryParams(['rule_group' => (string) $group->id, 'rule_match' => 'WOOLWORTHS', 'rule_category' => (string) $this->groceries->id])
        ->test(UserRuleManager::class)
        ->assertSet('showRuleModal', false);

    $own = UserRuleGroup::factory()->for($this->user)->create();

    Livewire::actingAs($this->user)
        ->withQueryParams(['rule_group' => (string) $own->id, 'rule_match' => 'WOOLWORTHS', 'rule_category' => (string) $foreignCategory->id])
        ->test(UserRuleManager::class)
        ->assertSet('showRuleModal', false);
});
