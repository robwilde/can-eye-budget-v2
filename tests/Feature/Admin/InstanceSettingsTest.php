<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Console\FeedSyncSchedule;
use App\Contracts\LlmClientContract;
use App\Enums\ApiKeyScope;
use App\Enums\AssistantMode;
use App\Enums\AuditOutcome;
use App\Enums\RegistrationMode;
use App\Livewire\AdminSettings;
use App\Models\AuditEvent;
use App\Models\InstanceSetting;
use App\Models\User;
use App\Services\Assistant\AssistantAvailability;
use App\Services\Assistant\AssistantPromptBuilder;
use App\Services\Assistant\OpenAiClient;
use App\Services\InstanceSettings;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Concerns\CallsApi;

uses(CallsApi::class);

beforeEach(function (): void {
    $this->admin = User::factory()->create(['is_admin' => true]);
});

function settingsPage(User $user): Testable
{
    return Livewire::actingAs($user)->test(AdminSettings::class);
}

function freshSettings(): InstanceSettings
{
    app(InstanceSettings::class)->flush();

    return app(InstanceSettings::class);
}

it('refuses the self-hosting settings save to a non-admin', function () {
    $component = settingsPage($this->admin)->set('registrationMode', 'open');
    $this->actingAs(User::factory()->create());

    $component->call('save')->assertForbidden();

    expect(InstanceSetting::query()->count())->toBe(0);
    $this->get(route('admin.settings'))->assertForbidden();
});

it('refuses clearing a saved key to a non-admin', function () {
    freshSettings()->save([InstanceSetting::OPENAI_API_KEY => 'sk-saved-1234']);
    $component = settingsPage($this->admin);
    $this->actingAs(User::factory()->create());

    $component->call('clearKey', 'openai')->assertForbidden();

    expect(freshSettings()->openaiApiKey())->toBe('sk-saved-1234');
});

it('sends a guest to login instead of the settings page', function () {
    $this->get(route('admin.settings'))->assertRedirect(route('login'));
});

it('rejects an invalid auto-sync time without saving anything', function () {
    freshSettings()->save([InstanceSetting::REGISTRATION_MODE => 'closed']);

    settingsPage($this->admin)
        ->set('registrationMode', 'open')
        ->set('autoSync', 'on')
        ->set('autoSyncTime', '25:00')
        ->set('openaiKey', 'sk-new-key')
        ->call('save')
        ->assertHasErrors(['autoSyncTime'])
        ->assertSee('Invalid sync time');

    expect(DB::table('instance_settings')->pluck('value', 'key')->all())->toBe(['registration_mode' => 'closed']);
});

it('rejects malformed sync times', function (string $time) {
    settingsPage($this->admin)->set('autoSync', 'on')->set('autoSyncTime', $time)->call('save')->assertHasErrors(['autoSyncTime']);

    expect(InstanceSetting::query()->count())->toBe(0);
})->with(['24:00', '12:60', '9:30', '3:5', 'noon', '03:30:00']);

it('stores provider API keys encrypted', function () {
    settingsPage($this->admin)->set('openaiKey', 'sk-plaintext-secret-1234')->set('anthropicKey', 'ak-plaintext-5678')->set('contextDevKey', 'cd-plaintext-9999')->call('save')->assertHasNoErrors();

    $raw = DB::table('instance_settings')->whereIn('key', ['openai_api_key', 'anthropic_api_key', 'context_dev_api_key'])->pluck('value', 'key')->all();

    expect($raw)->toHaveCount(3)
        ->and(implode('|', $raw))->not->toContain('plaintext')
        ->and(freshSettings()->openaiApiKey())->toBe('sk-plaintext-secret-1234')
        ->and(freshSettings()->anthropicApiKey())->toBe('ak-plaintext-5678')
        ->and(freshSettings()->contextDevApiKey())->toBe('cd-plaintext-9999');
});

it('never gives a saved key back to the browser or to serialization', function () {
    $component = settingsPage($this->admin)->set('openaiKey', 'sk-super-secret-ABCD')->call('save');

    $component->assertDontSee('sk-super-secret-ABCD')->assertSee('…ABCD')->assertSet('openaiKey', '');

    expect(json_encode(InstanceSetting::query()->where('key', 'openai_api_key')->first()->toArray()))->not->toContain('secret')
        ->and(InstanceSetting::query()->where('key', 'openai_api_key')->first()->toArray())->not->toHaveKey('value')
        ->and(json_encode($component->snapshot))->not->toContain('sk-super-secret');
});

it('keeps a saved key when the field is left blank and removes it on request', function () {
    freshSettings()->save([InstanceSetting::OPENAI_API_KEY => 'sk-keep-me-0001']);

    settingsPage($this->admin)->set('llmModel', 'gpt-test')->call('save');

    expect(freshSettings()->openaiApiKey())->toBe('sk-keep-me-0001')->and(freshSettings()->llmModel())->toBe('gpt-test');

    settingsPage($this->admin)->call('clearKey', 'openai')->call('clearKey', 'nonsense');

    config(['services.openai.key' => null]);

    expect(freshSettings()->openaiApiKey())->toBeNull()->and(InstanceSetting::query()->where('key', 'openai_api_key')->exists())->toBeFalse();
});

it('falls back to the environment config when a setting is unset', function () {
    config([
        'services.redbark.include_pending' => true,
        'services.ai.provider' => 'anthropic',
        'services.ai.model' => 'env-model',
        'services.ai.base_url' => 'https://llm.example.test/v1',
        'services.ai.max_tokens' => 777,
        'services.openai.key' => 'env-openai',
        'services.anthropic.key' => 'env-anthropic',
        'services.context_dev.api_key' => 'env-context',
        'budget.registration_mode' => 'invite_only',
        'budget.ai_assistant' => true,
    ]);
    $settings = freshSettings();

    expect($settings->includePending())->toBeTrue()
        ->and($settings->llmProvider())->toBe('anthropic')
        ->and($settings->llmModel())->toBe('env-model')
        ->and($settings->llmEndpoint())->toBe('https://llm.example.test/v1')
        ->and($settings->llmTokenBudget())->toBe(777)
        ->and($settings->openaiApiKey())->toBe('env-openai')
        ->and($settings->llmApiKey())->toBe('env-anthropic')
        ->and($settings->contextDevApiKey())->toBe('env-context')
        ->and($settings->registrationMode())->toBe(RegistrationMode::InviteOnly)
        ->and($settings->assistantMode())->toBe(AssistantMode::BuiltIn)
        ->and($settings->autoSync())->toBeNull()
        ->and($settings->systemPrompt())->toBeNull()
        ->and($settings->secretHint(InstanceSetting::OPENAI_API_KEY))->toBe(['source' => 'environment', 'hint' => '…enai']);
});

it('lets a saved setting win over the environment and a cleared one return to it', function () {
    config(['services.redbark.include_pending' => true, 'services.ai.model' => 'env-model']);

    freshSettings()->save([InstanceSetting::INCLUDE_PENDING => '0', InstanceSetting::LLM_MODEL => 'saved-model']);

    expect(freshSettings()->includePending())->toBeFalse()->and(freshSettings()->llmModel())->toBe('saved-model');

    freshSettings()->save([InstanceSetting::INCLUDE_PENDING => null, InstanceSetting::LLM_MODEL => '']);

    expect(freshSettings()->includePending())->toBeTrue()->and(freshSettings()->llmModel())->toBe('env-model');
});

it('hides the in-app chat in MCP-only mode while MCP still lists tools', function () {
    config(['budget.api' => true, 'budget.mcp' => true, 'budget.ai_assistant' => true, 'services.openai.key' => 'sk', 'services.ai.provider' => 'openai']);
    $user = User::factory()->create();
    $token = $this->apiTokenFor($user, ApiKeyScope::Read);

    $this->actingAs($user)->get(route('assistant'))->assertOk();

    freshSettings()->save([InstanceSetting::ASSISTANT_MODE => 'mcp_only']);

    $this->actingAs($user)->get(route('assistant'))->assertNotFound();
    $this->actingAs($user)->get(route('dashboard'))->assertDontSee(route('assistant'), false);
    expect(app(AssistantAvailability::class)->available())->toBeFalse();

    app('auth')->forgetGuards();
    $names = array_column($this->withToken($token)->postJson('/mcp', ['jsonrpc' => '2.0', 'method' => 'tools/list', 'id' => 1], ['Accept' => 'application/json, text/event-stream'])->assertOk()->json('result.tools'), 'name');

    expect($names)->toContain('list_accounts', 'search_transactions');
});

it('keeps MCP available when the assistant mode was never saved, even with the chat flag off', function () {
    config(['budget.api' => true, 'budget.mcp' => true, 'budget.ai_assistant' => false]);
    $token = $this->apiTokenFor(User::factory()->create(), ApiKeyScope::Read);

    $this->withToken($token)->postJson('/mcp', ['jsonrpc' => '2.0', 'method' => 'tools/list', 'id' => 1], ['Accept' => 'application/json, text/event-stream'])->assertOk();
});

it('switches the chat and MCP off in off mode', function () {
    config(['budget.api' => true, 'budget.mcp' => true, 'budget.ai_assistant' => true]);
    $user = User::factory()->create();
    $token = $this->apiTokenFor($user);
    freshSettings()->save([InstanceSetting::ASSISTANT_MODE => 'off']);

    $this->actingAs($user)->get(route('assistant'))->assertNotFound();
    app('auth')->forgetGuards();
    $this->withToken($token)->postJson('/mcp', ['jsonrpc' => '2.0', 'method' => 'tools/list', 'id' => 1], ['Accept' => 'application/json, text/event-stream'])->assertNotFound();
});

it('offers the chat in built-in mode even when the environment flag is off', function () {
    config(['budget.ai_assistant' => false, 'services.openai.key' => 'sk', 'services.ai.provider' => 'openai']);

    expect(freshSettings()->assistantMode())->toBe(AssistantMode::McpOnly)
        ->and(app(AssistantAvailability::class)->available())->toBeFalse();

    freshSettings()->save([InstanceSetting::ASSISTANT_MODE => 'built_in']);

    expect(app(AssistantAvailability::class)->available())->toBeTrue();
});

it('schedules the feed sync at the saved daily time', function () {
    freshSettings()->save([InstanceSetting::AUTO_SYNC => '1', InstanceSetting::AUTO_SYNC_TIME => '03:30']);
    $schedule = new Schedule;

    FeedSyncSchedule::register($schedule, freshSettings());

    $events = collect($schedule->events())->filter(fn ($event): bool => str_contains((string) $event->command, FeedSyncSchedule::COMMAND));

    expect($events)->toHaveCount(1)->and($events->first()->expression)->toBe('30 3 * * *')->and($events->first()->withoutOverlapping)->toBeTrue();
});

it('keeps the six-hourly feed sync until an admin chooses and skips it when auto-sync is off', function () {
    $default = new Schedule;
    FeedSyncSchedule::register($default, freshSettings());

    expect(collect($default->events())->first()->expression)->toBe('0 */6 * * *');

    freshSettings()->save([InstanceSetting::AUTO_SYNC => '0']);
    $off = new Schedule;
    FeedSyncSchedule::register($off, freshSettings());

    expect($off->events())->toBe([]);

    freshSettings()->save([InstanceSetting::AUTO_SYNC => '1']);
    $noTime = new Schedule;
    FeedSyncSchedule::register($noTime, freshSettings());

    expect(collect($noTime->events())->first()->expression)->toBe('0 3 * * *');
});

it('saves the auto-sync choice through the page and clears the time when it is not once a day', function () {
    settingsPage($this->admin)->set('autoSync', 'on')->set('autoSyncTime', '04:15')->call('save')->assertHasNoErrors();

    expect(freshSettings()->autoSync())->toBeTrue()->and(freshSettings()->autoSyncTime())->toBe('04:15');

    settingsPage($this->admin)->assertSet('autoSync', 'on')->assertSet('autoSyncTime', '04:15')->set('autoSync', 'default')->call('save');

    expect(freshSettings()->autoSync())->toBeNull()->and(InstanceSetting::query()->where('key', 'auto_sync_time')->exists())->toBeFalse();
});

it('records an audit event when self-hosting settings are saved', function () {
    settingsPage($this->admin)->set('registrationMode', 'invite_only')->set('openaiKey', 'sk-audit-secret-0000')->call('save')->assertHasNoErrors();

    $event = AuditEvent::query()->where('action', 'livewire.admin-settings.save')->sole();

    expect($event->user_id)->toBe($this->admin->id)->and($event->outcome)->toBe(AuditOutcome::Success)
        ->and(json_encode(AuditEvent::query()->get()->toArray()))->not->toContain('audit-secret');
});

it('applies the saved registration mode to new sign-ups', function () {
    config(['budget.registration_mode' => 'open']);
    freshSettings()->save([InstanceSetting::REGISTRATION_MODE => 'closed']);

    expect(RegistrationMode::current())->toBe(RegistrationMode::Closed)
        ->and(fn () => app(App\Actions\Fortify\CreateNewUser::class)->create(['name' => 'N', 'email' => 'n@example.test', 'password' => 'Str0ng-passphrase!', 'password_confirmation' => 'Str0ng-passphrase!']))->toThrow(Illuminate\Validation\ValidationException::class);

    freshSettings()->save([InstanceSetting::REGISTRATION_MODE => 'open']);

    expect(RegistrationMode::current())->toBe(RegistrationMode::Open);
});

it('builds the LLM client from the saved provider, model, endpoint, key and token budget', function () {
    freshSettings()->save([
        InstanceSetting::LLM_PROVIDER => 'openai',
        InstanceSetting::LLM_MODEL => 'saved-model',
        InstanceSetting::LLM_ENDPOINT => 'https://llm.example.test/v1',
        InstanceSetting::LLM_TOKEN_BUDGET => '512',
        InstanceSetting::OPENAI_API_KEY => 'sk-from-settings',
    ]);
    Http::fake(['llm.example.test/*' => Http::response(['model' => 'saved-model', 'choices' => [['message' => ['content' => 'hi']]], 'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1]])]);

    $client = app(LlmClientContract::class);
    $client->complete('system', [['role' => 'user', 'content' => 'hello']]);

    expect($client)->toBeInstanceOf(OpenAiClient::class);
    Http::assertSent(fn ($request): bool => $request->url() === 'https://llm.example.test/v1/chat/completions'
        && $request->hasHeader('Authorization', 'Bearer sk-from-settings')
        && $request['model'] === 'saved-model'
        && $request['max_completion_tokens'] === 512);
});

it('uses the saved system prompt instead of the built-in instructions', function () {
    $user = User::factory()->create();

    expect(app(AssistantPromptBuilder::class)->build($user))->toContain('careful budgeting assistant');

    freshSettings()->save([InstanceSetting::SYSTEM_PROMPT => 'Speak like a pirate.']);

    $prompt = app(AssistantPromptBuilder::class)->build($user);

    expect($prompt)->toContain('Speak like a pirate.')->not->toContain('careful budgeting assistant')->toContain('Today is');
});

it('validates the endpoint, token budget and enumerated choices', function (string $field, string $value) {
    settingsPage($this->admin)->set($field, $value)->call('save')->assertHasErrors([$field]);

    expect(InstanceSetting::query()->count())->toBe(0);
})->with([
    ['llmEndpoint', 'not a url'],
    ['llmEndpoint', 'ftp://example.test'],
    ['llmTokenBudget', '10'],
    ['llmTokenBudget', 'many'],
    ['llmProvider', 'bard'],
    ['assistantMode', 'sometimes'],
    ['registrationMode', 'whenever'],
    ['autoSync', 'maybe'],
]);

it('shows the self-hosting page to an admin only', function () {
    $this->actingAs($this->admin)->get(route('admin.settings'))->assertOk()->assertSee('Self-hosting settings');
    $this->actingAs(User::factory()->create())->get(route('admin.settings'))->assertForbidden();
});

it('reads no saved settings while the table is missing', function () {
    Illuminate\Support\Facades\Schema::drop('instance_settings');
    config(['services.ai.model' => 'env-model']);

    expect(freshSettings()->llmModel())->toBe('env-model')->and(freshSettings()->autoSync())->toBeNull();
});

it('keeps unknown secret decryption failures from breaking reads', function () {
    DB::table('instance_settings')->insert(['key' => 'openai_api_key', 'value' => 'not-a-ciphertext', 'created_at' => now(), 'updated_at' => now()]);
    config(['services.openai.key' => 'env-fallback']);

    expect(freshSettings()->openaiApiKey())->toBe('env-fallback');
});

it('does not copy the environment into the database when an admin saves one field', function () {
    config(['budget.api' => true, 'budget.mcp' => true, 'budget.ai_assistant' => false, 'services.ai.model' => 'env-model', 'services.ai.max_tokens' => 999, 'budget.registration_mode' => 'open']);

    settingsPage($this->admin)->assertSet('registrationMode', '')->assertSet('assistantMode', '')->assertSet('llmModel', '')->set('systemPrompt', 'Be brief.')->call('save')->assertHasNoErrors();

    expect(DB::table('instance_settings')->pluck('value', 'key')->all())->toBe(['system_prompt' => 'Be brief.']);

    $token = $this->apiTokenFor(User::factory()->create(), ApiKeyScope::Read);
    $this->withToken($token)->postJson('/mcp', ['jsonrpc' => '2.0', 'method' => 'tools/list', 'id' => 1], ['Accept' => 'application/json, text/event-stream'])->assertOk();
});

it('shows saved choices on the page and lets an admin return a field to the environment', function () {
    settingsPage($this->admin)->set('llmModel', 'saved')->set('registrationMode', 'closed')->set('includePending', '1')->call('save');

    $page = settingsPage($this->admin)->assertSet('llmModel', 'saved')->assertSet('registrationMode', 'closed')->assertSet('includePending', '1');

    $page->set('llmModel', '')->set('registrationMode', '')->set('includePending', '')->call('save');

    expect(DB::table('instance_settings')->pluck('value', 'key')->all())->toBe([]);
});

it('sends a token budget to OpenAI only when an admin saved one', function () {
    config(['services.openai.key' => 'sk', 'services.ai.provider' => 'openai', 'services.ai.max_tokens' => 2048]);
    Http::fake(['api.openai.com/*' => Http::response(['model' => 'm', 'choices' => [['message' => ['content' => 'hi']]], 'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1]])]);

    app(LlmClientContract::class)->complete('s', [['role' => 'user', 'content' => 'x']]);

    Http::assertSent(fn ($request): bool => ! array_key_exists('max_completion_tokens', $request->data()));
    expect(freshSettings()->llmTokenBudget())->toBe(2048)->and(freshSettings()->savedTokenBudget())->toBeNull();
});

it('reports a saved key it cannot decrypt instead of silently hiding it', function () {
    DB::table('instance_settings')->insert(['key' => 'openai_api_key', 'value' => 'not-a-ciphertext', 'created_at' => now(), 'updated_at' => now()]);
    Illuminate\Support\Facades\Log::spy();

    $page = settingsPage($this->admin)->assertSee('cannot be read')->assertSeeHtml('data-test="clear-openai-key"');

    Illuminate\Support\Facades\Log::shouldHaveReceived('warning')->with('Instance setting could not be decrypted', ['key' => 'openai_api_key']);

    $page->call('clearKey', 'openai');

    expect(DB::table('instance_settings')->count())->toBe(0);
});

it('retries a first-time save once when another save won the unique key', function () {
    $settings = freshSettings();
    $inserted = false;
    InstanceSetting::creating(function (InstanceSetting $setting) use (&$inserted): void {
        if (! $inserted && $setting->key === 'llm_model') {
            $inserted = true;
            DB::table('instance_settings')->insert(['key' => 'llm_model', 'value' => 'rival', 'created_at' => now(), 'updated_at' => now()]);
        }
    });

    $settings->save([InstanceSetting::LLM_MODEL => 'mine']);

    expect(freshSettings()->llmModel())->toBe('mine');
});

it('hides sign-up links and the register page when settings close registration', function () {
    config(['budget.registration_mode' => 'open']);
    $this->get(route('login'))->assertSee('Sign up');

    freshSettings()->save([InstanceSetting::REGISTRATION_MODE => 'closed']);

    $this->get(route('login'))->assertDontSee('Sign up');
    $this->get(route('register'))->assertNotFound();
});

it('hides the MCP address on the API keys page while the assistant is off', function () {
    config(['budget.api' => true, 'budget.mcp' => true]);
    $user = User::factory()->create();

    Livewire::actingAs($user)->test('pages::settings.api-keys')->assertSee(url('/mcp'));

    freshSettings()->save([InstanceSetting::ASSISTANT_MODE => 'off']);

    Livewire::actingAs($user)->test('pages::settings.api-keys')->assertDontSee(url('/mcp'));
});
