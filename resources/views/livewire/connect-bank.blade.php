@php use App\Enums\RefreshStatus; use Illuminate\Support\Str; @endphp
<div class="space-y-6">
    @if(! $isConnected)
        <div class="rounded-xl border border-neutral-200 p-8 text-center dark:border-neutral-700">
            <flux:icon.building-library class="mx-auto size-12 text-zinc-400"/>
            <flux:heading size="lg" class="mt-4">No bank connected</flux:heading>
            <flux:text class="mt-2">Connect your bank to automatically sync your accounts and transactions.</flux:text>
            <div class="mt-6">
                <flux:button variant="primary" icon="plus" wire:click="connect" wire:loading.attr="disabled">
                    <flux:icon.loading wire:loading wire:target="connect" class="size-4"/>
                    {{ __('Connect Bank') }}
                </flux:button>
            </div>
        </div>
    @else
        <flux:card>
            <div class="flex items-center justify-between">
                <div>
                    <flux:heading size="lg">Connection Status</flux:heading>
                    <flux:text size="sm" class="mt-1">
                        @if($lastSyncedAt)
                            Last synced {{ $lastSyncedAt->diffForHumans() }}
                        @else
                            Never synced
                        @endif
                    </flux:text>
                </div>
                <flux:button wire:click="connect" wire:loading.attr="disabled">
                    <flux:icon.loading wire:loading wire:target="connect" class="size-4"/>
                    {{ __('Manage Connections') }}
                </flux:button>
            </div>
        </flux:card>

        <flux:card>
            <div class="flex items-center justify-between">
                <flux:heading size="lg">Sync Summary</flux:heading>
                <div class="flex items-center gap-3">
                    <flux:text size="sm">{{ $todayRefreshCount }} of 20 refreshes used today</flux:text>
                    <flux:button size="sm" variant="primary" wire:click="refresh" wire:loading.attr="disabled" :disabled="! $canRefresh">
                        <flux:icon.loading wire:loading wire:target="refresh" class="size-4"/>
                        {{ __('Refresh Now') }}
                    </flux:button>
                </div>
            </div>
            <div class="mt-4 flex flex-wrap items-center gap-3">
                <div class="flex items-center gap-2">
                    <flux:icon.building-library class="size-5 text-zinc-400"/>
                    <flux:text>{{ $accounts->count() }} {{ Str::plural('account', $accounts->count()) }}</flux:text>
                </div>
                <div class="flex items-center gap-2">
                    <flux:icon.receipt-percent class="size-5 text-zinc-400"/>
                    <flux:text>{{ number_format($transactionCount) }} {{ Str::plural('transaction', $transactionCount) }}</flux:text>
                </div>
            </div>
            @if($accounts->isNotEmpty())
                <div class="mt-3 flex flex-wrap gap-2">
                    @foreach($accounts as $account)
                        <flux:badge size="sm" color="zinc">{{ $account->name }}</flux:badge>
                    @endforeach
                </div>
            @endif
        </flux:card>

        <flux:card>
            <flux:heading size="lg">Refresh History</flux:heading>
            @if($refreshLogs->isEmpty())
                <flux:text class="mt-2">No refresh history yet.</flux:text>
            @else
                <div class="mt-4 divide-y divide-neutral-200 dark:divide-neutral-700">
                    @foreach($refreshLogs as $log)
                        <div wire:key="log-{{ $log->id }}" class="flex items-center justify-between py-3">
                            <div class="flex items-center gap-3">
                                <flux:text size="sm">{{ $log->created_at->diffForHumans() }}</flux:text>
                                <flux:text size="sm" class="text-zinc-500">{{ $log->trigger->label() }}</flux:text>
                                @if($log->status === RefreshStatus::Success && ($log->accounts_synced !== null || $log->transactions_synced !== null))
                                    <flux:text size="sm" class="text-zinc-500">
                                        @if($log->accounts_synced !== null)
                                            {{ $log->accounts_synced }} {{ Str::plural('account', $log->accounts_synced) }}
                                        @endif
                                        @if($log->accounts_synced !== null && $log->transactions_synced !== null) · @endif
                                        @if($log->transactions_synced !== null)
                                            {{ $log->transactions_synced }} {{ Str::plural('transaction', $log->transactions_synced) }}
                                        @endif
                                    </flux:text>
                                @endif
                            </div>
                            @if($log->status === RefreshStatus::Success)
                                <flux:badge size="sm" color="green">Success</flux:badge>
                            @elseif($log->status === RefreshStatus::Pending)
                                <flux:badge size="sm" color="yellow">Pending</flux:badge>
                            @else
                                <flux:badge size="sm" color="red">Failed</flux:badge>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </flux:card>
    @endif
</div>
