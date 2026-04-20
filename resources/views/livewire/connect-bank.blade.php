@php use App\Enums\RefreshStatus; use Illuminate\Support\Str; @endphp
<div class="space-y-6">
    @if(! $isConnected)
        <x-cib.empty-state
            icon="building-library"
            title="No bank connected"
            description="Connect your bank to automatically sync your accounts and transactions."
        >
            <x-slot:action>
                <flux:button variant="primary" icon="plus" wire:click="connect" wire:loading.attr="disabled">
                    <flux:icon.loading wire:loading wire:target="connect" class="size-4"/>
                    {{ __('Connect Bank') }}
                </flux:button>
            </x-slot:action>
        </x-cib.empty-state>
    @else
        <x-cib.card>
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
        </x-cib.card>

        <x-cib.card>
            <div class="flex items-center justify-between">
                <flux:heading size="lg">Sync Summary</flux:heading>
                <div class="flex items-center gap-3">
                    <x-cib.stat-pill tone="neutral">
                        {{ $todayRefreshCount }} of 20 refreshes used today
                    </x-cib.stat-pill>
                    <button
                        type="button"
                        data-testid="connect-bank-refresh-now"
                        wire:click="refresh"
                        wire:loading.attr="disabled"
                        @disabled(! $canRefresh)
                        class="inline-flex items-center gap-2 rounded-md border-2 border-cib-black bg-cib-yellow-400 px-4 py-2 text-sm font-bold text-cib-black shadow-pop transition-transform hover:-translate-y-px disabled:cursor-not-allowed disabled:opacity-50 disabled:hover:translate-y-0"
                    >
                        <flux:icon.loading wire:loading wire:target="refresh" class="size-4"/>
                        <flux:icon name="arrow-path" class="size-4"/>
                        {{ __('Refresh Now') }}
                    </button>
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
                        <x-cib.stat-pill tone="neutral">{{ $account->name }}</x-cib.stat-pill>
                    @endforeach
                </div>
            @endif
        </x-cib.card>

        <x-cib.card>
            <flux:heading size="lg">Refresh History</flux:heading>
            @if($refreshLogs->isEmpty())
                <flux:text class="mt-2">No refresh history yet.</flux:text>
            @else
                <div class="mt-4 space-y-2">
                    @foreach($refreshLogs as $log)
                        <div wire:key="log-{{ $log->id }}" class="day-card flex items-center justify-between py-3">
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
                            <x-cib.stat-pill :tone="$statusTones[$log->id]">
                                {{ $log->status->label() }}
                            </x-cib.stat-pill>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-cib.card>
    @endif
</div>
