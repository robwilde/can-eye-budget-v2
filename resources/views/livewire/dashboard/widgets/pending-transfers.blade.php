@if ($dashboard->pendingTransfers > 0)
    <x-cib.card data-test="dashboard-pending-transfers-card">
        <flux:heading size="lg">{{ trans_choice(':count possible transfer to review|:count possible transfers to review', $dashboard->pendingTransfers) }}</flux:heading>
        <flux:text size="sm" class="mt-1">
            {{ __('Confirm each one once; the same transfer is then linked automatically on later imports.') }}
        </flux:text>
        <a href="{{ route('transfers.review') }}" wire:navigate
           class="mt-3 inline-block text-sm font-bold underline decoration-dotted"
           data-test="dashboard-pending-transfers-link">{{ __('Review transfers') }}</a>
    </x-cib.card>
@endif
