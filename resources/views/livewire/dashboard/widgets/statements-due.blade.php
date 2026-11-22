@if ($dashboard->statementsDue->isNotEmpty())
    <x-cib.card data-test="dashboard-statements-due-card">
        <flux:heading size="lg">{{ __('Check last month\'s statements') }}</flux:heading>
        <flux:text size="sm" class="mt-1">
            {{ __('Upload each bank statement to confirm the feed delivered every transaction, then close the month.') }}
        </flux:text>
        <ul class="mt-3 space-y-1">
            @foreach ($dashboard->statementsDue as $dueAccount)
                <li wire:key="statement-due-{{ $dueAccount->id }}">
                    <a href="{{ route('accounts.reconcile', $dueAccount) }}" wire:navigate
                       class="text-sm font-bold underline decoration-dotted"
                       data-test="dashboard-statement-due-{{ $dueAccount->id }}">{{ $dueAccount->name }}</a>
                </li>
            @endforeach
        </ul>
    </x-cib.card>
@endif
