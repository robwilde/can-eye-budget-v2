<?php

use App\Actions\Redbark\SaveRedbarkApiKey;
use App\Actions\Redbark\SaveSimpleFinAccessUrl;
use App\Enums\ImportSource;
use App\Enums\RedbarkTransport;
use App\Enums\RefreshStatus;
use App\Enums\RefreshTrigger;
use App\Exceptions\Redbark\RedbarkException;
use App\Jobs\SyncRedbarkFeedJob;
use App\Models\Account;
use App\Models\RedbarkFeed;
use App\Models\RedbarkSyncLog;
use App\Services\SimpleFinClaimer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Bank providers')] class extends Component {
    public string $api_key = '';

    public string $setup_token = '';

    /** @return Collection<int, RedbarkFeed> */
    #[Computed]
    public function feeds(): Collection
    {
        return RedbarkFeed::query()->where('user_id', Auth::id())->orderBy('id')->get();
    }

    #[Computed]
    public function logs(): Collection
    {
        return RedbarkSyncLog::query()
            ->where('user_id', Auth::id())
            ->with('feed')
            ->latest('id')
            ->limit(10)
            ->get();
    }

    /**
     * Polling is only worth its cost while a run is actually open. A Pending row
     * older than SyncRedbarkFeedJob::UNIQUE_FOR is stranded (job died mid-run
     * without updating its log), not actually syncing.
     *
     * @return list<int> the ids of the feeds with an open run
     */
    #[Computed]
    public function syncingFeedIds(): array
    {
        return RedbarkSyncLog::query()
            ->where('user_id', Auth::id())
            ->where('status', RefreshStatus::Pending)
            ->where('created_at', '>=', now()->subSeconds(SyncRedbarkFeedJob::UNIQUE_FOR))
            ->pluck('redbark_feed_id')
            ->unique()
            ->values()
            ->all();
    }

    #[Computed]
    public function isSyncing(): bool
    {
        return $this->syncingFeedIds !== [];
    }

    public function save(SaveRedbarkApiKey $saveApiKey): void
    {
        $validated = $this->validate([
            'api_key' => ['required', 'string', 'min:16', 'max:512'],
        ]);

        $feed = $saveApiKey->handle(Auth::user(), $validated['api_key']);

        $this->api_key = '';

        SyncRedbarkFeedJob::dispatchFor($feed, RefreshTrigger::Manual);

        // Reset after dispatching so this render already sees the open log and starts polling.
        $this->forgetFeeds();

        $this->dispatch('redbark-saved');
    }

    public function connectSimpleFin(SaveSimpleFinAccessUrl $saveAccessUrl, SimpleFinClaimer $claimer): void
    {
        $validated = $this->validate([
            'setup_token' => ['required', 'string', 'max:2048'],
        ]);

        $this->setup_token = '';

        try {
            $accessUrl = $claimer->claim($validated['setup_token']);
        } catch (RedbarkException $e) {
            $this->addError('setup_token', $e->errorType === 'invalid_token'
                ? __('Redbark did not accept this setup token. Check you copied all of it, or create a new token under Settings > SimpleFIN in Redbark. A token only works once.')
                : __('We could not reach Redbark just now. Please try again in a moment with a new setup token.'));

            return;
        }

        $feed = $saveAccessUrl->handle(Auth::user(), $accessUrl);

        SyncRedbarkFeedJob::dispatchFor($feed, RefreshTrigger::Manual);

        $this->forgetFeeds();

        $this->dispatch('simplefin-saved');
    }

    public function syncNow(int $feedId): void
    {
        $feed = $this->feeds->firstWhere('id', $feedId);

        if ($feed === null || in_array($feed->id, $this->syncingFeedIds, true)) {
            return;
        }

        SyncRedbarkFeedJob::dispatchFor($feed, RefreshTrigger::Manual);

        unset($this->logs, $this->syncingFeedIds, $this->isSyncing);
    }

    public function disconnect(int $feedId): void
    {
        $feed = $this->feeds->firstWhere('id', $feedId);

        if ($feed === null) {
            return;
        }

        // Hand the app accounts back before the link disappears: acceptsCsvImports()
        // only allows Csv and Manual, so leaving them as Redbark would strand them.
        $accountIds = $feed->accounts()->linked()->pluck('account_id')->all();

        Account::query()
            ->where('user_id', Auth::id())
            ->whereIn('id', $accountIds)
            ->update(['import_source' => ImportSource::Manual]);

        $feed->delete();

        $this->forgetFeeds();

        $this->modal('confirm-redbark-disconnect-'.$feedId)->close();
        $this->dispatch('redbark-disconnected');
    }

    private function forgetFeeds(): void
    {
        unset($this->feeds, $this->logs, $this->syncingFeedIds, $this->isSyncing);
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading class="sr-only">{{ __('Bank providers') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Providers')" :subheading="__('Connect your Australian bank accounts via Redbark')">
        <div @if($this->isSyncing) wire:poll.3s @endif class="my-6 w-full space-y-6">
            @foreach ($this->feeds as $feed)
                <div class="space-y-3" wire:key="redbark-feed-{{ $feed->id }}" data-test="redbark-feed-{{ $feed->transport->value }}">
                    <div class="flex flex-wrap items-center gap-3">
                        <flux:heading size="sm">
                            {{ $feed->transport === App\Enums\RedbarkTransport::Rest ? __('Redbark API key') : __('SimpleFIN') }}
                        </flux:heading>

                        <flux:badge :color="$feed->status === App\Enums\RedbarkFeedStatus::Good ? 'green' : 'amber'" size="sm">
                            {{ $feed->status->label() }}
                        </flux:badge>

                        <flux:text size="sm" class="text-zinc-500">
                            @if ($feed->last_synced_at)
                                {{ __('Last synced :time', ['time' => $feed->last_synced_at->diffForHumans()]) }}
                            @else
                                {{ __('Never synced') }}
                            @endif
                        </flux:text>

                        @if (in_array($feed->id, $this->syncingFeedIds, true))
                            <flux:text size="sm" class="text-zinc-500">{{ __('Sync in progress…') }}</flux:text>
                        @endif
                    </div>

                    @if ($feed->pending_account_setup)
                        <flux:callout icon="building-library">
                            <flux:callout.heading>{{ __('Accounts are waiting to be set up') }}</flux:callout.heading>
                            <flux:callout.text>
                                {{ __('Redbark found accounts that are not connected to a Can Eye account yet.') }}
                            </flux:callout.text>
                            <x-slot name="actions">
                                <flux:button :href="route('redbark.setup')" wire:navigate size="sm" variant="primary"
                                             data-test="redbark-setup-button">
                                    {{ __('Set up accounts') }}
                                </flux:button>
                            </x-slot>
                        </flux:callout>
                    @endif

                    <div class="flex items-center gap-4">
                        <flux:button wire:click="syncNow({{ $feed->id }})" variant="filled" data-test="sync-redbark-button"
                                     :disabled="in_array($feed->id, $this->syncingFeedIds, true)">
                            {{ __('Sync now') }}
                        </flux:button>

                        <flux:modal.trigger name="confirm-redbark-disconnect-{{ $feed->id }}">
                            <flux:button variant="subtle" data-test="disconnect-redbark-button">
                                {{ __('Disconnect') }}
                            </flux:button>
                        </flux:modal.trigger>
                    </div>

                    <flux:modal name="confirm-redbark-disconnect-{{ $feed->id }}" class="md:w-96">
                        <div class="space-y-6">
                            <div>
                                <flux:heading size="lg">{{ __('Disconnect this connection?') }}</flux:heading>
                                <flux:text class="mt-2">
                                    {{ __('Your accounts and their transactions are kept, and become manual accounts again. The stored credential is deleted.') }}
                                </flux:text>
                            </div>

                            <div class="flex gap-2">
                                <flux:spacer />

                                <flux:modal.close>
                                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                                </flux:modal.close>

                                <flux:button wire:click="disconnect({{ $feed->id }})" variant="danger" data-test="confirm-disconnect-redbark-button">
                                    {{ __('Disconnect') }}
                                </flux:button>
                            </div>
                        </div>
                    </flux:modal>
                </div>
            @endforeach

            @php($restFeed = $this->feeds->firstWhere('transport', App\Enums\RedbarkTransport::Rest))
            @php($simpleFinFeed = $this->feeds->firstWhere('transport', App\Enums\RedbarkTransport::SimpleFin))

            <form wire:submit="save" class="space-y-6">
                <flux:input
                    wire:model="api_key"
                    :label="__('API key')"
                    type="password"
                    placeholder="rbk_live_…"
                    :description="__('Create a key at app.redbark.com under Settings > API & MCP. The free trial is enough; after it you need a Developer or Professional plan.')"
                    viewable
                    required
                />

                <div class="flex items-center gap-4">
                    <flux:button variant="primary" type="submit" data-test="save-redbark-key-button">
                        {{ $restFeed ? __('Update key') : __('Save') }}
                    </flux:button>

                    <x-action-message class="me-3" on="redbark-saved">
                        {{ __('Saved.') }}
                    </x-action-message>
                </div>
            </form>

            <form wire:submit="connectSimpleFin" class="space-y-6">
                <flux:input
                    wire:model="setup_token"
                    :label="__('SimpleFIN setup token')"
                    type="password"
                    :description="__('Create a token under Settings > SimpleFIN in Redbark. A token only works once.')"
                    viewable
                    required
                    data-test="simplefin-token-input"
                />

                <div class="flex items-center gap-4">
                    <flux:button variant="filled" type="submit" data-test="connect-simplefin-button">
                        {{ $simpleFinFeed ? __('Replace SimpleFIN token') : __('Connect SimpleFIN') }}
                    </flux:button>

                    <x-action-message class="me-3" on="simplefin-saved">
                        {{ __('Saved.') }}
                    </x-action-message>
                </div>
            </form>

            @if ($this->logs->isNotEmpty())
                <div class="space-y-2">
                    <flux:heading size="sm">{{ __('Recent syncs') }}</flux:heading>

                    <flux:table>
                        <flux:table.columns>
                            <flux:table.column>{{ __('When') }}</flux:table.column>
                            <flux:table.column>{{ __('Connection') }}</flux:table.column>
                            <flux:table.column>{{ __('Trigger') }}</flux:table.column>
                            <flux:table.column>{{ __('Status') }}</flux:table.column>
                            <flux:table.column>{{ __('Changes') }}</flux:table.column>
                        </flux:table.columns>

                        <flux:table.rows>
                            @foreach ($this->logs as $log)
                                <flux:table.row wire:key="redbark-log-{{ $log->id }}">
                                    <flux:table.cell>{{ $log->created_at->diffForHumans() }}</flux:table.cell>
                                    <flux:table.cell>{{ $log->feed?->transport === App\Enums\RedbarkTransport::SimpleFin ? __('SimpleFIN') : __('Redbark API key') }}</flux:table.cell>
                                    <flux:table.cell>{{ $log->trigger->label() }}</flux:table.cell>
                                    <flux:table.cell>
                                        <flux:badge size="sm" :color="match ($log->status) {
                                            App\Enums\RefreshStatus::Success => 'green',
                                            App\Enums\RefreshStatus::Failed => 'red',
                                            default => 'zinc',
                                        }">
                                            {{ $log->status->label() }}
                                        </flux:badge>
                                    </flux:table.cell>
                                    <flux:table.cell>
                                        {{ __(':created new, :updated updated, :balances balances', [
                                            'created' => $log->transactions_created ?? 0,
                                            'updated' => $log->transactions_updated ?? 0,
                                            'balances' => $log->balances_updated ?? 0,
                                        ]) }}
                                    </flux:table.cell>
                                </flux:table.row>

                                @if (! empty($log->errors))
                                    <flux:table.row wire:key="redbark-log-errors-{{ $log->id }}">
                                        <flux:table.cell colspan="5">
                                            {{-- Native disclosure: flux:accordion is a Pro component. --}}
                                            <details class="text-sm">
                                                <summary class="cursor-pointer text-red-600 dark:text-red-400">
                                                    {{ __(':count problem(s) during this sync', ['count' => count($log->errors)]) }}
                                                </summary>
                                                <ul class="mt-2 list-disc space-y-1 ps-4">
                                                    @foreach ($log->errors as $error)
                                                        <li>
                                                            <span class="font-medium">{{ $error['context'] ?? 'unknown' }}</span>:
                                                            {{ $error['message'] ?? '' }}
                                                        </li>
                                                    @endforeach
                                                </ul>
                                            </details>
                                        </flux:table.cell>
                                    </flux:table.row>
                                @endif
                            @endforeach
                        </flux:table.rows>
                    </flux:table>
                </div>
            @endif

            <flux:separator />

            <livewire:gmail-connection />

            <flux:separator />

            <livewire:analysis-suggestions />
        </div>

    </x-pages::settings.layout>
</section>
