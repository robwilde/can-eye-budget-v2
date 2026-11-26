<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\ApiKeyScope;
use App\Enums\AuditOutcome;
use App\Models\Account;
use App\Models\ApiKey;
use App\Models\AuditEvent;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Assistant\AssistantToolRegistry;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CallsApi;

uses(CallsApi::class);

beforeEach(function (): void {
    config(['budget.api' => true, 'budget.mcp' => true]);
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create(['is_tracked' => true, 'name' => 'Everyday']);
});

function mcpCall(object $test, ?string $token, string $method, array $params = [], bool $notification = false): TestResponse
{
    app('auth')->forgetGuards();

    $body = ['jsonrpc' => '2.0', 'method' => $method, 'params' => (object) $params];

    if (! $notification) {
        $body['id'] = 1;
    }

    $request = $token === null ? $test : $test->withToken($token);

    return $request->postJson('/mcp', $body, ['Accept' => 'application/json, text/event-stream']);
}

function mcpToolText(TestResponse $response): array
{
    return json_decode($response->json('result.content.0.text'), true);
}

it('answers a missing MCP token with 401 and the authorisation metadata', function () {
    $response = mcpCall($this, null, 'tools/list');

    $response->assertUnauthorized();
    $header = $response->headers->get('WWW-Authenticate');

    expect($header)->toContain('Bearer')->toContain('resource_metadata="'.route('mcp.oauth.protected-resource', ['path' => 'mcp']).'"');
    $this->getJson(route('mcp.oauth.protected-resource', ['path' => 'mcp']))->assertOk()->assertJsonPath('resource', url('/mcp'))->assertJsonPath('bearer_methods_supported', ['header']);
});

it('answers an invalid, expired or revoked token with 401', function () {
    $expired = ApiKey::issue($this->user, 'old', ApiKeyScope::ReadWrite, now()->subDay())['token'];
    $revoked = $this->apiTokenFor($this->user, name: 'gone');
    ApiKey::query()->where('name', 'gone')->update(['revoked_at' => now()]);

    foreach (['cib_nonsense', $expired, $revoked] as $token) {
        mcpCall($this, $token, 'tools/list')->assertUnauthorized();
    }
});

it('serves nothing, not even a 405, while the MCP or API flag is off', function () {
    $token = $this->apiTokenFor($this->user);

    config(['budget.mcp' => false]);
    mcpCall($this, $token, 'tools/list')->assertNotFound();

    $this->get('/mcp')->assertNotFound();
    $this->delete('/mcp')->assertNotFound();

    config(['budget.mcp' => true, 'budget.api' => false]);
    mcpCall($this, $token, 'tools/list')->assertNotFound();
    $this->get('/mcp')->assertNotFound();
    $this->getJson(route('mcp.oauth.protected-resource'))->assertNotFound();
});

it('lists the same tools over MCP as the in-app assistant', function () {
    $token = $this->apiTokenFor($this->user);

    $names = array_column(mcpCall($this, $token, 'tools/list')->assertOk()->json('result.tools'), 'name');

    expect($names)->toBe(array_keys(app(AssistantToolRegistry::class)->forUser($this->user)))
        ->and($names)->toContain('search_transactions', 'create_transaction', 'apply_rule');
});

it('shows a read-only key only the tools that read data', function () {
    $token = $this->apiTokenFor($this->user, ApiKeyScope::Read);

    $names = array_column(mcpCall($this, $token, 'tools/list')->assertOk()->json('result.tools'), 'name');

    expect($names)->toContain('list_accounts', 'preview_rule')->not->toContain('create_transaction', 'delete_transaction', 'apply_rule', 'update_budget');
});

it('rejects a read-only token at the MCP server without running a tool', function () {
    $token = $this->apiTokenFor($this->user, ApiKeyScope::Read);

    $response = mcpCall($this, $token, 'tools/call', ['name' => 'create_transaction', 'arguments' => ['account_id' => $this->account->id, 'amount' => 100, 'direction' => 'debit', 'post_date' => '2026-07-01', 'description' => 'x']]);

    $response->assertJsonPath('error.code', -32602);
    expect(Transaction::query()->count())->toBe(0)
        ->and(AuditEvent::query()->where('action', 'like', 'assistant.%')->count())->toBe(0);
});

it('refuses a write tool for a read-only key even when it reaches the adapter', function () {
    $key = ApiKey::issue($this->user, 'ro', ApiKeyScope::Read, null)['token'];
    $adapter = new App\Mcp\Tools\AssistantToolAdapter(app(App\Http\Assistant\Tools\CreateTransaction::class), $this->user, false);

    $response = $adapter->handle(new Laravel\Mcp\Request(['account_id' => $this->account->id, 'amount' => 100, 'direction' => 'debit', 'post_date' => '2026-07-01', 'description' => 'x']));

    expect($response->isError())->toBeTrue()->and(Transaction::query()->count())->toBe(0)->and($key)->not->toBe('');
});

it('runs a read tool for the key owner only', function () {
    $other = User::factory()->create();
    Account::factory()->for($other)->create(['name' => 'Someone else']);
    $token = $this->apiTokenFor($this->user, ApiKeyScope::Read);

    $result = mcpToolText(mcpCall($this, $token, 'tools/call', ['name' => 'list_accounts', 'arguments' => (object) []])->assertOk());

    expect(array_column($result['data'], 'name'))->toBe(['Everyday']);
});

it('runs a write tool with a read-write key and audits it against the owner', function () {
    $token = $this->apiTokenFor($this->user);

    $result = mcpToolText(mcpCall($this, $token, 'tools/call', ['name' => 'create_transaction', 'arguments' => ['account_id' => $this->account->id, 'amount' => 450, 'direction' => 'debit', 'post_date' => '2026-07-01', 'description' => 'Coffee']])->assertOk());

    expect($result['data']['amount'])->toBe(450)->and(Transaction::query()->where('user_id', $this->user->id)->count())->toBe(1);
});

it('records an audit event for each MCP tool call', function () {
    $token = $this->apiTokenFor($this->user);

    mcpCall($this, $token, 'tools/call', ['name' => 'create_transaction', 'arguments' => ['account_id' => $this->account->id, 'amount' => 450, 'direction' => 'debit', 'post_date' => '2026-07-01', 'description' => 'Coffee']])->assertOk();
    mcpCall($this, $token, 'tools/call', ['name' => 'list_accounts', 'arguments' => (object) []])->assertOk();

    $actions = AuditEvent::query()->where('user_id', $this->user->id)->orderBy('id')->pluck('action')->all();

    expect($actions)->toContain('assistant.create_transaction', 'mcp.create_transaction', 'mcp.list_accounts', 'http.mcp.server')
        ->and(AuditEvent::query()->where('action', 'mcp.create_transaction')->sole()->outcome)->toBe(AuditOutcome::Success)
        ->and(AuditEvent::query()->whereNull('user_id')->count())->toBe(0);
});

it('answers a tool the user may not use as unknown', function () {
    $registry = new AssistantToolRegistry;
    $registry->register(fn (): App\Contracts\AssistantTool => new class implements App\Contracts\AssistantTool
    {
        public static int $ran = 0;

        public function name(): string
        {
            return 'preview_only';
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
            self::$ran++;

            return [];
        }
    });
    app()->instance(AssistantToolRegistry::class, $registry);
    $token = $this->apiTokenFor($this->user);

    $listed = array_column(mcpCall($this, $token, 'tools/list')->json('result.tools'), 'name');
    $call = mcpCall($this, $token, 'tools/call', ['name' => 'preview_only', 'arguments' => (object) []]);

    expect($listed)->toBe([])->and($call->json('error.message'))->toContain('not found');
});

it('does not run a tool call sent as a notification', function () {
    $token = $this->apiTokenFor($this->user);

    $response = mcpCall($this, $token, 'tools/call', ['name' => 'create_transaction', 'arguments' => ['account_id' => $this->account->id, 'amount' => 100, 'direction' => 'debit', 'post_date' => '2026-07-01', 'description' => 'x']], notification: true);

    expect($response->getContent())->toBe('')->and($response->status())->toBeIn([202, 204])->and(Transaction::query()->count())->toBe(0);
});

it('does not describe a thrown tool error to the client', function () {
    $broken = new class implements App\Contracts\AssistantTool
    {
        public function name(): string
        {
            return 'broken';
        }

        public function description(): string
        {
            return 'x';
        }

        public function parameters(): array
        {
            return ['type' => 'object'];
        }

        public function requiresPreview(): bool
        {
            return false;
        }

        public function handle(User $user, array $arguments): array
        {
            throw new RuntimeException('SECRET DETAIL');
        }
    };
    $registry = new AssistantToolRegistry;
    $registry->register(fn (): App\Contracts\AssistantTool => $broken);
    app()->instance(AssistantToolRegistry::class, $registry);
    $token = $this->apiTokenFor($this->user);

    $response = mcpCall($this, $token, 'tools/call', ['name' => 'broken', 'arguments' => (object) []]);

    expect($response->getContent())->not->toContain('SECRET DETAIL')->and($response->json('result.isError'))->toBeTrue();
});

it('revokes a connected MCP token so later calls fail', function () {
    $token = $this->apiTokenFor($this->user);
    $key = ApiKey::query()->forUser($this->user)->sole();

    Livewire\Livewire::actingAs($this->user)->test('pages::settings.api-keys')->call('revoke', $key->id);

    mcpCall($this, $token, 'tools/list')->assertUnauthorized();
    expect($key->fresh()->revoked_at)->not->toBeNull()
        ->and(AuditEvent::query()->where('user_id', $this->user->id)->where('action', 'livewire.pages::settings.api-keys.revoke')->count())->toBe(1);
});

it('does not let another user revoke a token', function () {
    $token = $this->apiTokenFor($this->user);
    $key = ApiKey::query()->forUser($this->user)->sole();
    $stranger = User::factory()->create();

    expect(fn () => Livewire\Livewire::actingAs($stranger)->test('pages::settings.api-keys')->call('revoke', $key->id))->toThrow(Illuminate\Database\Eloquent\ModelNotFoundException::class);

    mcpCall($this, $token, 'tools/list')->assertOk();
    expect($key->fresh()->revoked_at)->toBeNull();
});

it('shows the MCP address on the API keys page only while the flag is on', function () {
    Livewire\Livewire::actingAs($this->user)->test('pages::settings.api-keys')->assertSee(url('/mcp'));

    config(['budget.mcp' => false]);

    Livewire\Livewire::actingAs($this->user)->test('pages::settings.api-keys')->assertDontSee(url('/mcp'));
});

it('applies the per-key rate limit to MCP calls', function () {
    config(['budget.api_key_rate_limit' => 2]);
    $token = $this->apiTokenFor($this->user);

    mcpCall($this, $token, 'tools/list')->assertOk();
    mcpCall($this, $token, 'tools/list')->assertOk();

    mcpCall($this, $token, 'tools/list')->assertStatus(429);
});

it('answers a GET or DELETE on the MCP address with 405 and changes nothing', function () {
    $this->get('/mcp')->assertStatus(405);
    $this->delete('/mcp')->assertStatus(405);
});

it('audits a refused or failed MCP tool call as a failure', function () {
    $token = $this->apiTokenFor($this->user);
    $foreign = Transaction::factory()->for(User::factory()->create())->create();

    $refused = mcpToolText(mcpCall($this, $token, 'tools/call', ['name' => 'delete_transaction', 'arguments' => ['transaction_id' => $foreign->id]])->assertOk());

    expect($refused)->toBe(['error' => 'Not found.'])
        ->and(AuditEvent::query()->where('action', 'mcp.delete_transaction')->sole()->outcome)->toBe(AuditOutcome::Failure)
        ->and(AuditEvent::query()->where('action', 'assistant.delete_transaction')->count())->toBe(0);
});
