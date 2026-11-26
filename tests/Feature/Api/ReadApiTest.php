<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\ApiKeyScope;
use App\Enums\TransferLinkSource;
use App\Models\Account;
use App\Models\ApiKey;
use App\Models\Budget;
use App\Models\BudgetPlan;
use App\Models\Category;
use App\Models\Payee;
use App\Models\PlannedTransaction;
use App\Models\RedbarkFeed;
use App\Models\RedbarkSyncLog;
use App\Models\Transaction;
use App\Models\TransactionSplit;
use App\Models\User;
use App\Models\UserRule;
use App\Models\UserRuleGroup;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function (): void {
    config(['budget.api' => true]);
});

/**
 * @return array{0: User, 1: string}
 */
function apiUser(ApiKeyScope $scope = ApiKeyScope::Read, ?Carbon\CarbonImmutable $expires = null): array
{
    $user = User::factory()->create();
    $issued = ApiKey::issue($user, 'test', $scope, $expires);

    return [$user, $issued['token']];
}

function api(string $token): Illuminate\Testing\TestResponse
{
    app('auth')->forgetGuards();

    return test()->withToken($token)->getJson('/api/v1/accounts');
}

function getApi(string $token, string $uri): Illuminate\Testing\TestResponse
{
    app('auth')->forgetGuards();

    return test()->withToken($token)->getJson('/api/v1/'.mb_ltrim($uri, '/'));
}

it('rejects a request without a token with 401', function () {
    $this->getJson('/api/v1/accounts')->assertUnauthorized()->assertJsonStructure(['message']);
    $this->get('/api/v1/accounts')->assertUnauthorized();
});

it('rejects an expired or revoked API key with 401', function () {
    [$user, $expiredToken] = apiUser(expires: now()->addDay()->toImmutable());
    ApiKey::query()->where('user_id', $user->id)->update(['expires_at' => now()->subSecond()]);

    api($expiredToken)->assertUnauthorized();

    [$other, $revokedToken] = apiUser();
    ApiKey::query()->where('user_id', $other->id)->update(['revoked_at' => now()]);

    getApi($revokedToken, 'transactions')->assertUnauthorized();
    getApi($revokedToken, 'settings')->assertUnauthorized();
});

it('rejects a malformed or unknown token', function (string $token) {
    api($token)->assertUnauthorized();
})->with(['garbage', '999999|nothing', '|', 'cib_abc']);

it('refuses a session cookie on the API', function () {
    $this->actingAs(User::factory()->create())->getJson('/api/v1/accounts')->assertUnauthorized();
});

it('hides the API when the api flag is off', function () {
    [, $token] = apiUser();
    config(['budget.api' => false]);

    getApi($token, 'accounts')->assertNotFound();
    app('auth')->forgetGuards();
    $this->withoutToken()->getJson('/api/v1/accounts')->assertNotFound();
});

it('serves a read key and a read-write key', function (ApiKeyScope $scope) {
    [, $token] = apiUser($scope);

    api($token)->assertOk()->assertJsonStructure(['data', 'links', 'meta']);
})->with([ApiKeyScope::Read, ApiKeyScope::ReadWrite]);

it('refuses a token without the read ability', function () {
    [$user, $token] = apiUser();
    ApiKey::query()->where('user_id', $user->id)->update(['abilities' => json_encode(['write'])]);

    api($token)->assertForbidden();
});

it('returns 404 for another user\'s account', function () {
    [$user, $token] = apiUser();
    $mine = Account::factory()->for($user)->create();
    $theirs = Account::factory()->create();

    getApi($token, 'accounts/'.$mine->id)->assertOk()->assertJsonPath('data.id', $mine->id);
    getApi($token, 'accounts/'.$theirs->id)->assertNotFound();
    getApi($token, 'accounts/999999999')->assertNotFound();
});

it('returns 404 for another user\'s resource on every show endpoint', function (string $path, Closure $make) {
    [, $token] = apiUser();
    $foreign = $make();

    getApi($token, $path.'/'.$foreign->id)->assertNotFound();
})->with([
    'transaction' => ['transactions', fn () => Transaction::factory()->create()],
    'category' => ['categories', fn () => Category::factory()->create()],
    'payee' => ['payees', fn () => Payee::factory()->create()],
    'rule' => ['rules', fn () => UserRule::factory()->create()],
    'budget' => ['budgets', fn () => Budget::factory()->create()],
    'planned transaction' => ['planned-transactions', fn () => PlannedTransaction::factory()->create()],
]);

it('lists only the token owner\'s resources on every list endpoint', function (string $path, Closure $make) {
    [$user, $token] = apiUser();
    $mine = $make($user);
    $theirs = $make(User::factory()->create());

    $ids = collect(getApi($token, $path)->assertOk()->json('data'))->pluck('id');

    expect($ids->all())->toContain($mine->id)->not->toContain($theirs->id);
})->with([
    'accounts' => ['accounts', fn (User $u) => Account::factory()->for($u)->create()],
    'transactions' => ['transactions', fn (User $u) => Transaction::factory()->for($u)->create()],
    'categories' => ['categories', fn (User $u) => Category::factory()->create(['user_id' => $u->id])],
    'payees' => ['payees', fn (User $u) => Payee::factory()->for($u)->create()],
    'rules' => ['rules', fn (User $u) => UserRule::factory()->for($u)->create()],
    'budgets' => ['budgets', fn (User $u) => Budget::factory()->for($u)->create()],
    'planned' => ['planned-transactions', fn (User $u) => PlannedTransaction::factory()->for($u)->create()],
    'feeds' => ['feeds', fn (User $u) => RedbarkFeed::factory()->for($u)->create()],
]);

it('lists only the token owner\'s transactions', function () {
    [$user, $token] = apiUser();
    $mine = Transaction::factory()->for($user)->create();
    Transaction::factory()->count(3)->create();

    $data = getApi($token, 'transactions')->assertOk()->json('data');

    expect(collect($data)->pluck('id')->all())->toBe([$mine->id]);
});

it('returns transaction amounts as integer cents', function () {
    [$user, $token] = apiUser();
    $transaction = Transaction::factory()->for($user)->debit()->create(['amount' => 1234]);

    $row = getApi($token, 'transactions/'.$transaction->id)->assertOk()->json('data');

    expect($row['amount'])->toBe(1234)->and($row['direction'])->toBe('debit');
});

it('returns account balances as integer cents', function () {
    [$user, $token] = apiUser();
    $account = Account::factory()->for($user)->create(['balance' => 250_075]);

    expect(getApi($token, 'accounts/'.$account->id)->json('data.balance'))->toBe(250_075);
});

it('includes the splits of a transaction', function () {
    [$user, $token] = apiUser();
    $transaction = Transaction::factory()->for($user)->debit()->create(['amount' => 1000]);
    $category = Category::factory()->create(['user_id' => $user->id]);
    TransactionSplit::factory()->create(['transaction_id' => $transaction->id, 'category_id' => $category->id, 'amount' => 600]);

    expect(getApi($token, 'transactions/'.$transaction->id)->json('data.splits.0.amount'))->toBe(600);
});

it('filters transactions by date, account and category and paginates', function () {
    [$user, $token] = apiUser();
    $account = Account::factory()->for($user)->create();
    $otherAccount = Account::factory()->for($user)->create();
    $category = Category::factory()->create(['user_id' => $user->id]);
    $a = Transaction::factory()->for($user)->for($account)->create(['post_date' => '2026-05-10', 'category_id' => $category->id]);
    Transaction::factory()->for($user)->for($otherAccount)->create(['post_date' => '2026-05-20']);
    Transaction::factory()->for($user)->for($account)->create(['post_date' => '2026-06-10']);

    $ids = fn (string $query): array => collect(getApi($token, 'transactions?'.$query)->assertOk()->json('data'))->pluck('id')->all();

    expect($ids('from=2026-05-01&to=2026-05-31'))->toHaveCount(2)
        ->and($ids('account_id='.$account->id))->toHaveCount(2)
        ->and($ids('category_id='.$category->id))->toBe([$a->id])
        ->and(getApi($token, 'transactions?per_page=1')->json('meta.per_page'))->toBe(1);
});

it('refuses invalid filters and another user\'s account or category as a filter', function (string $query) {
    [, $token] = apiUser();
    $foreign = Account::factory()->create();
    $foreignCategory = Category::factory()->create();

    $query = str_replace(['{account}', '{category}'], [(string) $foreign->id, (string) $foreignCategory->id], $query);

    getApi($token, 'transactions?'.$query)->assertUnprocessable();
})->with(['bad date' => ['from=yesterday'], 'reversed' => ['from=2026-06-01&to=2026-05-01'], 'foreign account' => ['account_id={account}'], 'foreign category' => ['category_id={category}'], 'huge page' => ['per_page=1000'], 'zero page' => ['per_page=0']]);

it('lists rules with their group', function () {
    [$user, $token] = apiUser();
    $group = UserRuleGroup::factory()->for($user)->create(['name' => 'Auto']);
    UserRule::factory()->for($user)->create(['user_rule_group_id' => $group->id]);

    expect(getApi($token, 'rules')->json('data.0.group.name'))->toBe('Auto');
});

it('lists budgets and planned transactions as integer cents', function () {
    [$user, $token] = apiUser();
    $plan = BudgetPlan::factory()->for($user)->create();
    Budget::factory()->inPlan($plan)->create(['limit_amount' => 45_000]);
    PlannedTransaction::factory()->for($user)->create(['amount' => 9_900]);

    expect(getApi($token, 'budgets')->json('data.0.limit_amount'))->toBe(45_000)
        ->and(getApi($token, 'planned-transactions')->json('data.0.amount'))->toBe(9_900);
});

it('lists rejected transfers for the owner only', function () {
    [$user, $token] = apiUser();
    $mine = Transaction::factory()->for($user)->create(['transfer_link_source' => TransferLinkSource::Unlinked]);
    Transaction::factory()->create(['transfer_link_source' => TransferLinkSource::Unlinked]);
    Transaction::factory()->for($user)->create();

    expect(collect(getApi($token, 'transfers/rejected')->assertOk()->json('data'))->pluck('id')->all())->toBe([$mine->id]);
    getApi($token, 'transfers')->assertOk();
    getApi($token, 'transfers/unmatched')->assertOk();
});

it('returns the user\'s settings', function () {
    [$user, $token] = apiUser();
    $user->update(['base_currency' => 'NZD', 'timezone' => 'Pacific/Auckland', 'month_start_day' => 12]);
    $user->updatePreferences(['theme' => 'dark']);

    getApi($token, 'settings')->assertOk()
        ->assertJsonPath('data.base_currency', 'NZD')
        ->assertJsonPath('data.timezone', 'Pacific/Auckland')
        ->assertJsonPath('data.month_start_day', 12)
        ->assertJsonPath('data.preferences.theme', 'dark');
});

it('never exposes feed credentials', function () {
    [$user, $token] = apiUser();
    $feed = RedbarkFeed::factory()->for($user)->create();
    RedbarkSyncLog::factory()->for($feed, 'feed')->create();

    $body = getApi($token, 'feeds')->assertOk()->getContent();
    $logs = getApi($token, 'feeds/sync-logs')->assertOk()->json('data');

    expect($body)->not->toContain((string) $feed->api_key)->not->toContain('api_key')->not->toContain('access_url')
        ->and($logs)->toHaveCount(1);
});

it('returns JSON errors for unknown API paths', function () {
    [, $token] = apiUser();

    getApi($token, 'nope')->assertNotFound()->assertJsonStructure(['message']);
});

it('never exposes the key hash, passwords or internal secrets in any response', function () {
    [$user, $token] = apiUser();
    Account::factory()->for($user)->create();
    Transaction::factory()->for($user)->create();

    foreach (['accounts', 'transactions', 'settings', 'feeds', 'rules'] as $path) {
        $body = getApi($token, $path)->getContent();

        expect($body)->not->toContain('password')->not->toContain('two_factor')->not->toContain('remember_token');
    }
});

it('throttles per key and per address', function () {
    [, $token] = apiUser();
    config(['budget.api_key_rate_limit' => 2, 'budget.api_ip_rate_limit' => 1000]);
    RateLimiter::clear('api-key');

    getApi($token, 'accounts')->assertOk();
    getApi($token, 'accounts')->assertOk();
    getApi($token, 'accounts')->assertStatus(429);
});

it('throttles token guessing by address before authentication', function () {
    config(['budget.api_ip_rate_limit' => 2]);

    api('bad-1')->assertUnauthorized();
    api('bad-2')->assertUnauthorized();
    api('bad-3')->assertStatus(429);
});

it('does not let one key\'s limit affect another key', function () {
    [, $first] = apiUser();
    [, $second] = apiUser();
    config(['budget.api_key_rate_limit' => 1, 'budget.api_ip_rate_limit' => 1000]);

    getApi($first, 'accounts')->assertOk();
    getApi($first, 'accounts')->assertStatus(429);
    getApi($second, 'accounts')->assertOk();
});

it('updates last_used_at when a key is used', function () {
    [$user, $token] = apiUser();

    expect(ApiKey::query()->where('user_id', $user->id)->sole()->last_used_at)->toBeNull();

    getApi($token, 'accounts')->assertOk();

    expect(ApiKey::query()->where('user_id', $user->id)->sole()->last_used_at)->not->toBeNull();
});

it('keeps per_page in the pagination links of every list endpoint', function (string $path) {
    [$user, $token] = apiUser();
    Account::factory()->for($user)->count(3)->create();
    Category::factory()->count(3)->create(['user_id' => $user->id]);
    Transaction::factory()->for($user)->count(3)->create();
    Payee::factory()->for($user)->count(3)->create();
    UserRule::factory()->for($user)->count(3)->create();
    Budget::factory()->for($user)->count(3)->create();
    PlannedTransaction::factory()->for($user)->count(3)->create();
    RedbarkFeed::factory()->for($user)->create();
    RedbarkFeed::factory()->for($user)->create(['transport' => App\Enums\RedbarkTransport::SimpleFin]);

    $next = getApi($token, $path.'?per_page=1')->assertOk()->json('links.next');

    expect($next)->toContain('per_page=1');
})->with(['accounts', 'transactions', 'categories', 'payees', 'rules', 'budgets', 'planned-transactions', 'feeds']);

it('includes the whole end day when filtering transactions by to', function () {
    [$user, $token] = apiUser();
    $onBoundary = Transaction::factory()->for($user)->create(['post_date' => '2026-05-31']);
    Transaction::factory()->for($user)->create(['post_date' => '2026-06-01']);

    $ids = collect(getApi($token, 'transactions?from=2026-05-31&to=2026-05-31')->assertOk()->json('data'))->pluck('id')->all();

    expect($ids)->toBe([$onBoundary->id]);
});

it('matches split lines when filtering transactions by category', function () {
    [$user, $token] = apiUser();
    $category = Category::factory()->create(['user_id' => $user->id]);
    $other = Category::factory()->create(['user_id' => $user->id]);
    $split = Transaction::factory()->for($user)->debit()->create(['amount' => 1000, 'category_id' => $other->id]);
    TransactionSplit::factory()->create(['transaction_id' => $split->id, 'category_id' => $category->id, 'amount' => 400]);
    Transaction::factory()->for($user)->create(['category_id' => $other->id]);

    $ids = collect(getApi($token, 'transactions?category_id='.$category->id)->assertOk()->json('data'))->pluck('id')->all();

    expect($ids)->toBe([$split->id]);
});

it('returns only the current version of a transaction and of a rejected transfer', function () {
    [$user, $token] = apiUser();
    $old = Transaction::factory()->for($user)->create(['transfer_link_source' => TransferLinkSource::Unlinked]);
    $new = Transaction::factory()->for($user)->create(['transfer_link_source' => TransferLinkSource::Unlinked, 'parent_transaction_id' => $old->id]);

    getApi($token, 'transactions/'.$old->id)->assertNotFound();
    getApi($token, 'transactions/'.$new->id)->assertOk();

    expect(collect(getApi($token, 'transfers/rejected')->json('data'))->pluck('id')->all())->toBe([$new->id]);
});

it('answers 404 for an id too large for an integer', function () {
    [, $token] = apiUser();

    getApi($token, 'accounts/99999999999999999999')->assertNotFound();
    getApi($token, 'accounts/0')->assertNotFound();
});
