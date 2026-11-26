<?php

use App\Enums\ApiKeyScope;
use App\Models\ApiKey;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('API keys')] class extends Component {
    public string $name = '';

    public string $scope = '';

    public string $expiresInDays = '90';

    #[Locked]
    public ?string $newToken = null;

    public function mount(): void
    {
        abort_unless(config('budget.api') === true, 404);
    }

    /** @return Collection<int, ApiKey> */
    #[Computed]
    public function keys(): Collection
    {
        return ApiKey::query()
            ->forUser(Auth::id())
            ->orderByRaw('case when revoked_at is null and (expires_at is null or expires_at > ?) then 0 else 1 end', [CarbonImmutable::now()])
            ->latest('id')
            ->limit(50)
            ->get();
    }

    /**
     * Creates a key and shows its token once; only the hash is stored.
     */
    public function create(): void
    {
        abort_unless(config('budget.api') === true, 404);

        /** @var User $user */
        $user = Auth::user();
        $this->name = mb_trim($this->name);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:60', function (string $attribute, mixed $value, \Closure $fail) use ($user): void {
                if (ApiKey::query()->forUser($user)->active()->where('name', $value)->exists()) {
                    $fail(__('You already have an active key with this name.'));
                }

                if (ApiKey::query()->forUser($user)->active()->count() >= ApiKey::MAX_ACTIVE_KEYS) {
                    $fail(__('You have reached the limit of :max active keys. Revoke one first.', ['max' => ApiKey::MAX_ACTIVE_KEYS]));
                }
            }],
            'scope' => ['required', Rule::enum(ApiKeyScope::class)],
            'expiresInDays' => ['required', Rule::in(['30', '90', '365', 'never'])],
        ]);

        $expiresAt = $validated['expiresInDays'] === 'never' ? null : CarbonImmutable::now()->addDays((int) $validated['expiresInDays']);

        $this->newToken = ApiKey::issue($user, $validated['name'], ApiKeyScope::from($validated['scope']), $expiresAt)['token'];
        $this->name = '';
        $this->scope = '';

        unset($this->keys);
    }

    public function revoke(int $apiKeyId): void
    {
        abort_unless(config('budget.api') === true, 404);

        $key = ApiKey::query()->forUser(Auth::id())->whereKey($apiKeyId)->firstOrFail();

        if ($key->revoked_at === null) {
            $key->forceFill(['revoked_at' => now()])->save();
        }

        $this->newToken = null;

        unset($this->keys);
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading class="sr-only">{{ __('API keys') }}</flux:heading>

    <x-pages::settings.layout :heading="__('API keys')" :subheading="__('Personal keys for scripts and other tools. Each key can read, or read and write, your own data.')">
        <form wire:submit="create" class="my-6 w-full space-y-6">
            <flux:input wire:model="name" :label="__('Name')" :placeholder="__('Laptop script')" maxlength="60" data-test="api-key-name" />

            <flux:select wire:model="scope" :label="__('Access')" data-test="api-key-scope">
                <flux:select.option value="">{{ __('Choose access') }}</flux:select.option>
                @foreach(App\Enums\ApiKeyScope::cases() as $option)
                    <flux:select.option value="{{ $option->value }}">{{ __($option->label()) }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model="expiresInDays" :label="__('Expires')" data-test="api-key-expiry">
                <flux:select.option value="30">{{ __('In 30 days') }}</flux:select.option>
                <flux:select.option value="90">{{ __('In 90 days') }}</flux:select.option>
                <flux:select.option value="365">{{ __('In a year') }}</flux:select.option>
                <flux:select.option value="never">{{ __('Never') }}</flux:select.option>
            </flux:select>

            <flux:button type="submit" variant="primary" data-test="create-api-key-button">{{ __('Create key') }}</flux:button>
        </form>

        @if ($newToken !== null)
            <flux:callout variant="success" icon="key" class="mb-6" data-test="new-api-key">
                <flux:callout.heading>{{ __('Copy this key now; it is not shown again.') }}</flux:callout.heading>
                <flux:callout.text><code class="break-all font-mono">{{ $newToken }}</code></flux:callout.text>
            </flux:callout>
        @endif

        @if (config('budget.mcp') === true)
            <flux:callout icon="command-line" class="mb-6" data-test="mcp-section">
                <flux:callout.heading>{{ __('MCP server') }}</flux:callout.heading>
                <flux:callout.text>
                    {{ __('Connect an MCP client to this address and send one of the keys below as a bearer token. A read-only key only sees the tools that read your data. Revoking a key ends its connections.') }}
                    <code class="mt-2 block break-all font-mono" data-test="mcp-address">{{ url('/mcp') }}</code>
                </flux:callout.text>
            </flux:callout>
        @endif

        <div class="space-y-2">
            @forelse ($this->keys as $key)
                <div class="flex items-center justify-between gap-4 rounded-lg border-2 border-cib-black bg-white px-4 py-2" wire:key="api-key-{{ $key->id }}" data-test="api-key-row">
                    <div class="min-w-0 text-sm">
                        <span class="font-bold">{{ $key->name }}</span>
                        <span class="ms-2 uppercase text-zinc-500">{{ $key->status() }}</span>
                        <span class="ms-2 text-zinc-500">{{ __($key->scope->label()) }}</span>
                        <span class="ms-2 text-zinc-500">{{ $key->last_used_at ? __('used :when', ['when' => $key->last_used_at->diffForHumans()]) : __('never used') }}</span>
                    </div>
                    @if ($key->status() === 'active')
                        <flux:button size="sm" variant="ghost" wire:click="revoke({{ $key->id }})" wire:confirm="{{ __('Revoke this key?') }}" data-test="revoke-api-key-button">{{ __('Revoke') }}</flux:button>
                    @endif
                </div>
            @empty
                <flux:text>{{ __('No API keys yet.') }}</flux:text>
            @endforelse
        </div>
    </x-pages::settings.layout>
</section>
