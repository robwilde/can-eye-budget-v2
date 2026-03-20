@php use App\Enums\AccountClass; @endphp
<div class="space-y-6">
    @if($grouped->isEmpty())
        <div class="rounded-xl border border-neutral-200 p-8 text-center dark:border-neutral-700">
            <flux:icon.building-library class="mx-auto size-12 text-zinc-400" />
            <flux:heading size="lg" class="mt-4">No linked accounts</flux:heading>
            <flux:text class="mt-2">Connect your bank to see your accounts and track your net worth.</flux:text>
            <div class="mt-6">
                <flux:button variant="primary" icon="plus" href="{{ route('connect-bank') }}">Connect Bank</flux:button>
            </div>
        </div>
    @else
        <div class="rounded-xl border border-neutral-200 p-6 dark:border-neutral-700">
            <div class="grid gap-4 sm:grid-cols-3">
                <div>
                    <flux:text>Net Worth</flux:text>
                    <flux:heading size="xl" class="mt-1 tabular-nums">{{ $formatMoney($netWorth) }}</flux:heading>
                </div>
                <div>
                    <flux:text>Assets</flux:text>
                    <flux:heading size="lg" class="mt-1 tabular-nums text-green-600 dark:text-green-500">{{ $formatMoney($totalAssets) }}</flux:heading>
                </div>
                <div>
                    <flux:text>Liabilities</flux:text>
                    <flux:heading size="lg" class="mt-1 tabular-nums text-red-600 dark:text-red-500">{{ $formatMoney(abs($totalLiabilities)) }}</flux:heading>
                </div>
            </div>
        </div>

        @foreach($grouped as $typeValue => $accounts)
            @php $type = AccountClass::from($typeValue) @endphp
            <div class="rounded-xl border border-neutral-200 dark:border-neutral-700">
                <div class="flex items-center justify-between p-4">
                    <div class="flex items-center gap-2">
                        <flux:icon :name="$type->icon()" variant="mini" class="text-zinc-500" />
                        <flux:heading>{{ $type->label() }}</flux:heading>
                    </div>
                    <flux:badge color="zinc">{{ $formatMoney($accounts->sum('balance')) }}</flux:badge>
                </div>

                <flux:separator />

                <div class="divide-y divide-neutral-200 dark:divide-neutral-700">
                    @foreach($accounts as $account)
                        <div wire:key="account-{{ $account->id }}" class="flex items-center justify-between px-4 py-3">
                            <div>
                                <flux:heading size="sm">{{ $account->name }}</flux:heading>
                                <flux:text size="sm">{{ $account->institution }}</flux:text>
                            </div>
                            <div class="text-right">
                                <flux:text class="tabular-nums font-medium">{{ $formatMoney($account->balance) }}</flux:text>
                                <flux:text size="sm">{{ $account->updated_at->diffForHumans() }}</flux:text>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach

        <div class="flex justify-center">
            <flux:button variant="primary" icon="plus" href="{{ route('connect-bank') }}">Connect Bank</flux:button>
        </div>
    @endif
</div>
