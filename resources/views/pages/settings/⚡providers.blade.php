<?php

use App\Enums\ImportSource;
use App\Enums\RedbarkFeedStatus;
use App\Enums\RefreshStatus;
use App\Enums\RefreshTrigger;
use App\Jobs\SyncRedbarkFeedJob;
use App\Models\Account;
use App\Models\RedbarkFeed;
use App\Models\RedbarkSyncLog;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Bank providers')] class extends Component {
    public string $api_key = '';

    #[Computed]
    public function feed(): ?RedbarkFeed
    {
        return RedbarkFeed::query()->where('user_id', Auth::id())->first();
    }

    #[Computed]
    public function logs(): Collection
    {
        return RedbarkSyncLog::query()
            ->where('user_id', Auth::id())
            ->latest('id')
            ->limit(10)
            ->get();
    }

    /**
     * Polling is only worth its cost while a run is actually open. A Pending row
     * older than SyncRedbarkFeedJob::UNIQUE_FOR is stranded (job died mid-run
     * without updating its log), not actually syncing.
     */
    #[Computed]
    public function isSyncing(): bool
    {
        $log = $this->logs->first();

        return $log?->status === RefreshStatus::Pending
            && $log->created_at->greaterThan(now()->subSeconds(SyncRedbarkFeedJob::UNIQUE_FOR));
    }

    public function save(): void
    {
        $validated = $this->validate([
            'api_key' => ['required', 'string', 'min:16', 'max:512'],
        ]);

        // Resetting the status is what lets a rotated key clear a RequiresUpdate state.
        $feed = RedbarkFeed::updateOrCreate(
            ['user_id' => Auth::id()],
            ['api_key' => $validated['api_key'], 'status' => RedbarkFeedStatus::Good, 'auth_failure_count' => 0],
        );

        $this->api_key = '';

        SyncRedbarkFeedJob::dispatchFor($feed, RefreshTrigger::Manual);

        // Reset after dispatching so this render already sees the open log and starts polling.
        unset($this->feed, $this->logs, $this->isSyncing);

        $this->dispatch('redbark-saved');
    }

    public function syncNow(): void
    {
        $feed = $this->feed;

        if ($feed === null) {
            return;
        }

        $inFlight = RedbarkSyncLog::query()
            ->where('user_id', Auth::id())
            ->where('status', RefreshStatus::Pending)
            ->where('created_at', '>=', now()->subSeconds(SyncRedbarkFeedJob::UNIQUE_FOR))
            ->exists();

        if ($inFlight) {
            return;
        }

        SyncRedbarkFeedJob::dispatchFor($feed, RefreshTrigger::Manual);

        unset($this->logs, $this->isSyncing);
    }

    public function disconnect(): void
    {
        $feed = $this->feed;

        if ($feed === null) {
            return;
        }

        // Hand the app accounts back before the link disappears: acceptsCsvImports()
        // only allows Csv and Manual, so leaving them as Redbark would strand them.
        $accountIds = $feed->accounts()->linked()->pluck('account_id')->all();

        Account::query()
            ->whereIn('id', $accountIds)
            ->update(['import_source' => ImportSource::Manual]);

        $feed->delete();

        unset($this->feed, $this->logs, $this->isSyncing);

        $this->modal('confirm-redbark-disconnect')->close();
        $this->dispatch('redbark-disconnected');
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading class="sr-only">{{ __('Bank providers') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Providers')" :subheading="__('Connect your Australian bank accounts via Redbark')">
        <div @if($this->isSyncing) wire:poll.3s @endif class="my-6 w-full space-y-6">
            @if ($this->feed)
                <div class="flex flex-wrap items-center gap-3">
                    <flux:badge :color="$this->feed->status === App\Enums\RedbarkFeedStatus::Good ? 'green' : 'amber'" size="sm">
                        {{ $this->feed->status->label() }}
                    </flux:badge>

                    <flux:text size="sm" class="text-zinc-500">
                        @if ($this->feed->last_synced_at)
                            {{ __('Last synced :time', ['time' => $this->feed->last_synced_at->diffForHumans()]) }}
                        @else
                            {{ __('Never synced') }}
                        @endif
                    </flux:text>

                    @if ($this->isSyncing)
                        <flux:text size="sm" class="text-zinc-500">{{ __('Sync in progress…') }}</flux:text>
                    @endif
                </div>

                @if ($this->feed->pending_account_setup)
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
            @endif

            <form wire:submit="save" class="space-y-6">
                <flux:input
                    wire:model="api_key"
                    :label="__('API key')"
                    type="password"
                    placeholder="rbk_live_…"
                    :description="__('Create a key at app.redbark.com under Settings > API Keys. Requires a Developer or Professional plan.')"
                    viewable
                    required
                />

                <div class="flex items-center gap-4">
                    <flux:button variant="primary" type="submit" data-test="save-redbark-key-button">
                        {{ $this->feed ? __('Update key') : __('Save') }}
                    </flux:button>

                    @if ($this->feed)
                        <flux:button wire:click="syncNow" variant="filled" data-test="sync-redbark-button"
                                     :disabled="$this->isSyncing">
                            {{ __('Sync now') }}
                        </flux:button>

                        <flux:modal.trigger name="confirm-redbark-disconnect">
                            <flux:button variant="subtle" data-test="disconnect-redbark-button">
                                {{ __('Disconnect') }}
                            </flux:button>
                        </flux:modal.trigger>
                    @endif

                    <x-action-message class="me-3" on="redbark-saved">
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
                            <flux:table.column>{{ __('Trigger') }}</flux:table.column>
                            <flux:table.column>{{ __('Status') }}</flux:table.column>
                            <flux:table.column>{{ __('Changes') }}</flux:table.column>
                        </flux:table.columns>

                        <flux:table.rows>
                            @foreach ($this->logs as $log)
                                <flux:table.row wire:key="redbark-log-{{ $log->id }}">
                                    <flux:table.cell>{{ $log->created_at->diffForHumans() }}</flux:table.cell>
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
                                        <flux:table.cell colspan="4">
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
        </div>

        <flux:modal name="confirm-redbark-disconnect" class="md:w-96">
            <div class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ __('Disconnect Redbark?') }}</flux:heading>
                    <flux:text class="mt-2">
                        {{ __('Your accounts and their transactions are kept, and become manual accounts again. The stored API key is deleted.') }}
                    </flux:text>
                </div>

                <div class="flex gap-2">
                    <flux:spacer />

                    <flux:modal.close>
                        <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>

                    <flux:button wire:click="disconnect" variant="danger" data-test="confirm-disconnect-redbark-button">
                        {{ __('Disconnect') }}
                    </flux:button>
                </div>
            </div>
        </flux:modal>
    </x-pages::settings.layout>
</section>
