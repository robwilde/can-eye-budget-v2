<?php

declare(strict_types=1);

use App\Enums\MerchantBrandStatus;
use App\Enums\PayeeStatus;
use App\Enums\RefreshTrigger;
use App\Enums\TransactionDirection;
use App\Jobs\RunTransactionAnalysisJob;
use App\Jobs\SyncRedbarkFeedJob;
use App\Models\Category;
use App\Models\Merchant;
use App\Models\MerchantAlias;
use App\Models\MerchantBrand;
use App\Models\Payee;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserRule;
use App\Services\Merchants\MerchantKeyResolver;
use App\Services\Merchants\MerchantMerger;
use App\Services\Payees\PayeeConfirmer;
use App\Services\Payees\PayeeSuggester;
use App\Services\RedbarkClientFactory;
use App\Services\RedbarkTransactionMatcher;
use App\Services\RuleEvaluator;
use App\Services\TransactionIngestor;
use Illuminate\Support\Facades\Queue;

function merchantFor(User $user, string $name, ?string $key = null): Merchant
{
    return Merchant::factory()->for($user)->create(['name' => $name, 'merchant_key' => $key ?? mb_strtoupper($name)]);
}

it('moves every transaction to the merge target', function () {
    $user = User::factory()->create();
    [$x, $y, $z] = [merchantFor($user, 'X Shop'), merchantFor($user, 'Y Shop'), merchantFor($user, 'Z Shop')];
    Transaction::factory()->count(10)->for($user)->create(['merchant_id' => $x->id]);
    Transaction::factory()->count(5)->for($user)->create(['merchant_id' => $y->id]);

    app(MerchantMerger::class)->merge($user, [$x, $y], $z);

    expect(Transaction::query()->where('merchant_id', $z->id)->count())->toBe(15)
        ->and(Merchant::query()->whereIn('id', [$x->id, $y->id])->exists())->toBeFalse()
        ->and(MerchantAlias::query()->where('merchant_id', $z->id)->pluck('merchant_key')->sort()->values()->all())->toBe(['X SHOP', 'Y SHOP'])
        ->and(Transaction::query()->where('merchant_id', $z->id)->get()->every(fn (Transaction $row) => in_array('merchant', $row->locked_fields, true)))->toBeTrue();
});

it('resolves payee clashes during a merchant merge', function () {
    $user = User::factory()->create();
    [$source, $target] = [merchantFor($user, 'Source Shop'), merchantFor($user, 'Target Shop')];
    $sourceCategory = Category::factory()->for($user)->create();
    $targetCategory = Category::factory()->for($user)->create();
    Payee::factory()->for($user)->create(['merchant_key' => 'SOURCE SHOP', 'status' => PayeeStatus::Confirmed, 'confirmed_category_id' => $sourceCategory->id]);
    Payee::factory()->for($user)->create(['merchant_key' => 'TARGET SHOP', 'status' => PayeeStatus::Confirmed, 'confirmed_category_id' => $targetCategory->id]);
    MerchantBrand::factory()->for($user)->create(['merchant_key' => 'SOURCE SHOP']);
    MerchantBrand::factory()->for($user)->vetoed()->create(['merchant_key' => 'TARGET SHOP']);

    app(MerchantMerger::class)->merge($user, [$source], $target);

    $payees = Payee::query()->where('user_id', $user->id)->get();

    expect($payees)->toHaveCount(1)
        ->and($payees->first()->merchant_key)->toBe('TARGET SHOP')
        ->and($payees->first()->confirmed_category_id)->toBe($targetCategory->id)
        ->and(MerchantBrand::query()->where('user_id', $user->id)->pluck('merchant_key')->all())->toBe(['TARGET SHOP']);
});

it('keeps merged transactions on the target through a sync', function () {
    Queue::fake([RunTransactionAnalysisJob::class]);
    [$feed] = linkedRedbarkFeed();
    $user = $feed->user;
    [$source, $target] = [merchantFor($user, 'Woolworths'), merchantFor($user, 'Big Grocer')];
    $row = fn (string $id): array => [
        'id' => $id,
        'accountId' => 'rb_acc_1',
        'accountName' => 'Everyday Account',
        'status' => 'posted',
        'date' => '2026-08-10',
        'postDate' => '2026-08-10',
        'valueDate' => '2026-08-10',
        'description' => 'WOOLWORTHS 1234 BONDI',
        'amount' => '-42.50',
        'direction' => 'debit',
        'category' => 'Groceries',
        'merchantName' => 'Woolworths',
        'merchantCategoryCode' => '5411',
    ];
    $sync = fn () => new SyncRedbarkFeedJob($feed->fresh(), RefreshTrigger::Manual)->handle(
        app(RedbarkClientFactory::class),
        app(TransactionIngestor::class),
        app(RedbarkTransactionMatcher::class),
    );

    fakeRedbark(accounts: [redbarkUpstreamAccount()], transactions: [$row('rb_txn_1')]);
    $sync();
    $existing = Transaction::query()->where('redbark_id', 'rb_txn_1')->firstOrFail();
    $existing->forceFill(['merchant_id' => $source->id])->saveQuietly();

    app(MerchantMerger::class)->merge($user, [$source], $target);

    fakeRedbark(accounts: [redbarkUpstreamAccount()], transactions: [$row('rb_txn_1'), $row('rb_txn_2')]);
    $sync();

    expect($existing->fresh()->merchant_id)->toBe($target->id)
        ->and($existing->fresh()->merchant_key)->toBe('WOOLWORTHS')
        ->and(Transaction::query()->where('redbark_id', 'rb_txn_2')->value('merchant_id'))->toBe($target->id)
        ->and(Transaction::query()->where('redbark_id', 'rb_txn_2')->value('merchant_key'))->toBe('WOOLWORTHS');
});

it('never touches another users merchants or transactions', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    [$source, $target] = [merchantFor($user, 'Shop'), merchantFor($user, 'Target')];
    $foreign = merchantFor($other, 'Shop');
    $foreignRow = Transaction::factory()->for($other)->create(['merchant_id' => $foreign->id]);
    $foreignRow->forceFill(['merchant_key' => 'SHOP'])->saveQuietly();
    $foreignPayee = Payee::factory()->for($other)->create(['merchant_key' => 'SHOP']);
    $ownRow = Transaction::factory()->for($user)->create(['merchant_id' => $source->id]);
    $ownRow->forceFill(['merchant_key' => 'SHOP'])->saveQuietly();
    $ownDeleted = merchantFor($user, 'Gone');
    $foreignGone = Transaction::factory()->for($other)->create(['merchant_id' => $foreign->id]);

    app(MerchantMerger::class)->merge($user, [$source], $target);
    app(MerchantMerger::class)->delete($user, $ownDeleted);

    expect($foreignRow->fresh()->merchant_id)->toBe($foreign->id)
        ->and($foreignRow->fresh()->merchant_key)->toBe('SHOP')
        ->and($foreignRow->fresh()->locked_fields)->toBeNull()
        ->and($foreignPayee->fresh()->merchant_key)->toBe('SHOP')
        ->and($foreignGone->fresh()->merchant_id)->toBe($foreign->id)
        ->and(Merchant::query()->whereKey($foreign->id)->exists())->toBeTrue()
        ->and(MerchantAlias::query()->where('user_id', $other->id)->exists())->toBeFalse()
        ->and($ownRow->fresh()->merchant_id)->toBe($target->id);
});

it('refuses a merge that includes another users merchant', function () {
    $user = User::factory()->create();
    $target = merchantFor($user, 'Target');
    $foreign = merchantFor(User::factory()->create(), 'Foreign');

    expect(fn () => app(MerchantMerger::class)->merge($user, [$foreign], $target))->toThrow(InvalidArgumentException::class)
        ->and(Merchant::query()->whereKey($foreign->id)->exists())->toBeTrue();
});

it('rolls back a failed merchant merge', function () {
    $user = User::factory()->create();
    [$source, $target] = [merchantFor($user, 'Source Shop'), merchantFor($user, 'Target Shop')];
    $row = Transaction::factory()->for($user)->create(['merchant_id' => $source->id]);
    Payee::factory()->for($user)->create(['merchant_key' => 'SOURCE SHOP']);
    Payee::factory()->for($user)->create(['merchant_key' => 'TARGET SHOP']);
    MerchantBrand::factory()->for($user)->create(['merchant_key' => 'SOURCE SHOP', 'status' => MerchantBrandStatus::Resolved]);
    MerchantBrand::saving(fn () => throw new RuntimeException('boom'));

    try {
        expect(fn () => app(MerchantMerger::class)->merge($user, [$source], $target))->toThrow(RuntimeException::class);
    } finally {
        MerchantBrand::flushEventListeners();
    }

    expect(MerchantAlias::query()->count())->toBe(0)
        ->and(Payee::query()->orderBy('id')->pluck('merchant_key')->all())->toBe(['SOURCE SHOP', 'TARGET SHOP'])
        ->and(MerchantBrand::query()->value('merchant_key'))->toBe('SOURCE SHOP')
        ->and($row->fresh()->merchant_id)->toBe($source->id)
        ->and(Merchant::query()->whereKey($source->id)->exists())->toBeTrue();
});

it('clears the merchant on delete and records the unlink time on unlink', function () {
    $user = User::factory()->create();
    [$deleted, $unlinked] = [merchantFor($user, 'Deleted'), merchantFor($user, 'Unlinked')];
    $deletedRows = Transaction::factory()->count(3)->for($user)->create(['merchant_id' => $deleted->id]);
    $unlinkedRows = Transaction::factory()->count(2)->for($user)->create(['merchant_id' => $unlinked->id]);
    $this->travelTo('2026-11-11 10:00:00');

    app(MerchantMerger::class)->delete($user, $deleted);
    app(MerchantMerger::class)->unlink($user, $unlinked);

    expect(Merchant::query()->whereKey($deleted->id)->exists())->toBeFalse()
        ->and(Transaction::query()->whereIn('id', $deletedRows->pluck('id'))->whereNotNull('merchant_id')->count())->toBe(0)
        ->and(Transaction::query()->whereIn('id', $unlinkedRows->pluck('id'))->whereNull('merchant_id')->count())->toBe(2)
        ->and($unlinkedRows->first()->fresh()->locked_fields)->toContain('merchant')
        ->and($unlinked->fresh()->unlinked_at->toDateTimeString())->toBe('2026-11-11 10:00:00');
});

it('reuses the existing merchant with the same name', function () {
    $user = User::factory()->create();
    $existing = merchantFor($user, 'Woolworths');

    expect(Merchant::findOrCreateNamed($user->id, 'woolworths')->id)->toBe($existing->id)
        ->and(Merchant::findOrCreateNamed(User::factory()->create()->id, 'Woolworths')->id)->not->toBe($existing->id)
        ->and(Merchant::query()->count())->toBe(2);
});

it('keeps rules on a source merchant matching after a merge', function () {
    $user = User::factory()->create();
    [$source, $target] = [merchantFor($user, 'Source Shop'), merchantFor($user, 'Target Shop')];
    $linked = Transaction::factory()->for($user)->create(['merchant_name' => 'Source Shop', 'merchant_id' => $source->id]);
    $rule = UserRule::factory()->for($user)->create([
        'triggers' => [['field' => 'merchant_key', 'operator' => 'equals', 'value' => 'SOURCE SHOP']],
    ]);
    $evaluator = app(RuleEvaluator::class);

    app(MerchantMerger::class)->merge($user, [$source], $target);

    $linked->refresh();
    $linked->update(['description' => 'Edited after the merge']);
    $version = $linked->fresh()->createChild(['notes' => 'a new version']);

    expect($rule->fresh()->triggers[0]['value'])->toBe('SOURCE SHOP')
        ->and($linked->fresh()->merchant_key)->toBe('SOURCE SHOP')
        ->and($linked->fresh()->merchant_id)->toBe($target->id)
        ->and($version->merchant_key)->toBe('SOURCE SHOP')
        ->and($version->merchant_id)->toBe($target->id)
        ->and($evaluator->matches($linked->fresh(), $rule))->toBeTrue()
        ->and($evaluator->matches($version, $rule))->toBeTrue();
});

it('finds the target payee and brand for a merged transaction', function () {
    $user = User::factory()->create();
    [$source, $target] = [merchantFor($user, 'Source Shop'), merchantFor($user, 'Target Shop')];
    $row = Transaction::factory()->for($user)->create(['merchant_name' => 'Source Shop', 'merchant_id' => $source->id, 'category_id' => null, 'direction' => TransactionDirection::Debit]);
    $category = Category::factory()->for($user)->create(['is_hidden' => false]);
    $payee = Payee::factory()->for($user)->create(['merchant_key' => 'TARGET SHOP', 'merchant_name' => 'Target Shop']);
    MerchantBrand::factory()->for($user)->create(['merchant_key' => 'TARGET SHOP', 'title' => 'Target Brand']);
    MerchantBrand::factory()->for($user)->create(['merchant_key' => 'SOURCE SHOP', 'title' => 'Source Brand', 'status' => MerchantBrandStatus::Unresolved]);

    app(MerchantMerger::class)->merge($user, [$source], $target);

    $key = app(MerchantKeyResolver::class)->keyFor($user->id, $row->fresh());

    app(PayeeConfirmer::class)->confirm($user, $payee, $category->id);

    expect($row->fresh()->merchant_key)->toBe('SOURCE SHOP')
        ->and($key)->toBe('TARGET SHOP')
        ->and(MerchantBrand::query()->where('user_id', $user->id)->where('merchant_key', $key)->value('title'))->toBe('Target Brand')
        ->and($row->fresh()->category_id)->toBe($category->id);
});

it('does not ask about a merged transaction whose target payee is known', function () {
    $user = User::factory()->create();
    [$source, $target] = [merchantFor($user, 'Source Shop'), merchantFor($user, 'Target Shop')];
    $row = Transaction::factory()->for($user)->create(['merchant_name' => 'Source Shop', 'merchant_id' => $source->id, 'category_id' => null, 'direction' => TransactionDirection::Debit, 'transfer_pair_id' => null]);
    Category::factory()->for($user)->create(['is_hidden' => false]);
    Payee::factory()->for($user)->create(['merchant_key' => 'TARGET SHOP', 'merchant_name' => 'Target Shop']);
    $suggester = app(PayeeSuggester::class);

    expect($suggester->hasCandidates($user))->toBeTrue();

    app(MerchantMerger::class)->merge($user, [$source], $target);

    expect($suggester->hasCandidates($user))->toBeFalse()
        ->and($row->fresh()->merchant_id)->toBe($target->id);
});
