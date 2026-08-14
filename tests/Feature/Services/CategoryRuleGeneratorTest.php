<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\TransactionDirection;
use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserRule;
use App\Services\CategoryRuleGenerator;

test('it creates an auto-apply set_category rule and categorises existing matches', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $category = Category::factory()->create(['is_hidden' => false]);

    $source = Transaction::factory()->for($user)->for($account)->manual()->create([
        'merchant_name' => 'Netflix',
        'description' => 'NETFLIX.COM 1234',
        'direction' => TransactionDirection::Debit,
        'category_id' => null,
    ]);
    $sibling = Transaction::factory()->for($user)->for($account)->manual()->create([
        'merchant_name' => 'Netflix',
        'description' => 'NETFLIX.COM 9999',
        'direction' => TransactionDirection::Debit,
        'category_id' => null,
    ]);
    $unrelated = Transaction::factory()->for($user)->for($account)->manual()->create([
        'merchant_name' => 'Spotify',
        'description' => 'SPOTIFY',
        'direction' => TransactionDirection::Debit,
        'category_id' => null,
    ]);

    $rule = app(CategoryRuleGenerator::class)->generateAndApply($source, $category->id);

    expect($rule->is_auto_apply)->toBeTrue()
        ->and($rule->is_active)->toBeTrue()
        ->and($rule->user_id)->toBe($user->id)
        ->and($rule->actions)->toBe([['type' => 'set_category', 'value' => (string) $category->id]])
        ->and($rule->triggers[0]['field'])->toBe('merchant_name')
        ->and($rule->triggers[0]['operator'])->toBe('equals')
        ->and($rule->triggers[0]['value'])->toBe('Netflix');

    expect($source->fresh()->category_id)->toBe($category->id)
        ->and($sibling->fresh()->category_id)->toBe($category->id)
        ->and($unrelated->fresh()->category_id)->toBeNull();
});

test('it falls back to a description token trigger when there is no merchant name', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $category = Category::factory()->create(['is_hidden' => false]);

    $source = Transaction::factory()->for($user)->for($account)->manual()->create([
        'merchant_name' => null,
        'clean_description' => null,
        'description' => 'WOOLWORTHS 1234 SYDNEY',
        'direction' => TransactionDirection::Debit,
        'category_id' => null,
    ]);
    $sibling = Transaction::factory()->for($user)->for($account)->manual()->create([
        'merchant_name' => null,
        'clean_description' => null,
        'description' => 'WOOLWORTHS 5678 MELBOURNE',
        'direction' => TransactionDirection::Debit,
        'category_id' => null,
    ]);

    $rule = app(CategoryRuleGenerator::class)->generateAndApply($source, $category->id);

    expect($rule->triggers[0]['field'])->toBe('description')
        ->and($rule->triggers[0]['operator'])->toBe('contains')
        ->and(mb_stripos($source->description, $rule->triggers[0]['value']))->not->toBeFalse();

    expect($source->fresh()->category_id)->toBe($category->id)
        ->and($sibling->fresh()->category_id)->toBe($category->id);
});

test('it skips a leading payment-network token and keys the rule on the merchant', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $category = Category::factory()->create(['is_hidden' => false]);

    $source = Transaction::factory()->for($user)->for($account)->manual()->create([
        'merchant_name' => null,
        'clean_description' => null,
        'description' => '28.99 VISA -NETFLIX.COM Melbourne AU 619459 #2892',
        'direction' => TransactionDirection::Debit,
        'category_id' => null,
    ]);
    $sibling = Transaction::factory()->for($user)->for($account)->manual()->create([
        'merchant_name' => null,
        'clean_description' => null,
        'description' => '28.99 VISA -NETFLIX.COM Sydney AU 778812 #4410',
        'direction' => TransactionDirection::Debit,
        'category_id' => null,
    ]);
    $unrelatedVisa = Transaction::factory()->for($user)->for($account)->manual()->create([
        'merchant_name' => null,
        'clean_description' => null,
        'description' => '12.50 VISA -WOOLWORTHS Brisbane AU 100200 #3050',
        'direction' => TransactionDirection::Debit,
        'category_id' => null,
    ]);

    $rule = app(CategoryRuleGenerator::class)->generateAndApply($source, $category->id);

    expect($rule->triggers[0]['field'])->toBe('description')
        ->and($rule->triggers[0]['operator'])->toBe('contains')
        ->and($rule->triggers[0]['value'])->toBe('NETFLIX.COM');

    expect($source->fresh()->category_id)->toBe($category->id)
        ->and($sibling->fresh()->category_id)->toBe($category->id)
        ->and($unrelatedVisa->fresh()->category_id)->toBeNull();
});

test('it reuses a single auto-categorisation group across generated rules', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $category = Category::factory()->create(['is_hidden' => false]);

    $netflix = Transaction::factory()->for($user)->for($account)->manual()->create([
        'merchant_name' => 'Netflix',
        'direction' => TransactionDirection::Debit,
    ]);
    $spotify = Transaction::factory()->for($user)->for($account)->manual()->create([
        'merchant_name' => 'Spotify',
        'direction' => TransactionDirection::Debit,
    ]);

    $first = app(CategoryRuleGenerator::class)->generateAndApply($netflix, $category->id);
    $second = app(CategoryRuleGenerator::class)->generateAndApply($spotify, $category->id);

    expect($second->user_rule_group_id)->toBe($first->user_rule_group_id)
        ->and(UserRule::query()->where('user_id', $user->id)->count())->toBe(2);
});

test('an explicit match value builds a description-contains rule and categorises only those matches', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $category = Category::factory()->create(['is_hidden' => false]);

    $wooliesCashout = Transaction::factory()->for($user)->for($account)->manual()->create([
        'merchant_name' => null,
        'clean_description' => null,
        'description' => '110.53 VISA -Including Cash OutWOOLWORTHS/111 BOUNDARY SWESTEND',
        'direction' => TransactionDirection::Debit,
        'category_id' => null,
    ]);
    $wooliesPurchase = Transaction::factory()->for($user)->for($account)->manual()->create([
        'merchant_name' => null,
        'clean_description' => null,
        'description' => '54.20 VISA -WOOLWORTHS/111 BOUNDARY SWESTEND',
        'direction' => TransactionDirection::Debit,
        'category_id' => null,
    ]);
    $netflix = Transaction::factory()->for($user)->for($account)->manual()->create([
        'merchant_name' => null,
        'clean_description' => null,
        'description' => '28.99 VISA -NETFLIX.COM Melbourne AU',
        'direction' => TransactionDirection::Debit,
        'category_id' => null,
    ]);

    $rule = app(CategoryRuleGenerator::class)
        ->generateAndApply($wooliesCashout, $category->id, 'WOOLWORTHS/111 BOUNDARY');

    expect($rule->triggers[0])->toBe([
        'field' => 'description',
        'operator' => 'contains',
        'value' => 'WOOLWORTHS/111 BOUNDARY',
    ]);

    expect($wooliesCashout->fresh()->category_id)->toBe($category->id)
        ->and($wooliesPurchase->fresh()->category_id)->toBe($category->id)
        ->and($netflix->fresh()->category_id)->toBeNull();
});

test('suggestMatchValue prefers the merchant name, else the longest description token', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $generator = app(CategoryRuleGenerator::class);

    $withMerchant = Transaction::factory()->for($user)->for($account)->manual()->create([
        'merchant_name' => 'Netflix',
        'clean_description' => null,
        'description' => '28.99 VISA -NETFLIX.COM Melbourne AU 619459 #2892',
    ]);
    $csvOnly = Transaction::factory()->for($user)->for($account)->manual()->create([
        'merchant_name' => null,
        'clean_description' => null,
        'description' => '28.99 VISA -NETFLIX.COM Melbourne AU 619459 #2892',
    ]);

    expect($generator->suggestMatchValue($withMerchant))->toBe('Netflix')
        ->and($generator->suggestMatchValue($csvOnly))->toBe('NETFLIX.COM');
});

test('it suggests the distinctive payee token for an external transfer description', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    $source = Transaction::factory()->for($user)->for($account)->manual()->create([
        'merchant_name' => null,
        'clean_description' => null,
        'description' => 'Ext Tfr  - NET#1234567890 to 123456 Landlord Property MAST ABC - 1 Sample Street',
        'direction' => TransactionDirection::Debit,
    ]);

    expect(app(CategoryRuleGenerator::class)->suggestMatchValue($source))->toBe('LANDLORD');
});
