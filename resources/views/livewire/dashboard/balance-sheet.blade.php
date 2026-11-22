@use('App\Casts\MoneyCast')

<section data-test="balance-sheet">
    <div class="flex items-center justify-between">
        <x-cib.sec-head :title="__('Balance sheet')"/>
        <flux:select wire:change="setAccountOrder($event.target.value)" class="max-w-52" data-test="account-order" aria-label="{{ __('Order accounts by') }}">
            @foreach ($orders as $order)
                <option value="{{ $order->value }}" @selected($order === $selectedOrder)>{{ __($order->label()) }}</option>
            @endforeach
        </flux:select>
    </div>
    @if (config('budget.multi_currency'))
        <p class="text-sm text-gray-500" data-test="balance-sheet-base-currency">{{ __('All amounts converted to :currency', ['currency' => auth()->user()->base_currency]) }}</p>
    @endif

    @foreach ([['assets', __('Assets'), $this->balanceSheet->assets, $this->balanceSheet->assetTotal], ['liabilities', __('Liabilities'), $this->balanceSheet->liabilities, $this->balanceSheet->liabilityTotal]] as [$side, $heading, $groups, $total])
        <div class="mt-3" data-test="balance-sheet-{{ $side }}">
            <div class="flex items-center justify-between font-bold">
                <span>{{ $heading }}</span>
                <span data-test="balance-sheet-{{ $side }}-total">{{ MoneyCast::format($total) }}</span>
            </div>
            @forelse ($groups as $group)
                <div class="mt-2" wire:key="balance-sheet-{{ $side }}-{{ $group->class->value }}">
                    <div class="flex items-center justify-between text-sm font-bold text-gray-600">
                        <span>{{ $group->class->label() }}</span>
                        <span>{{ MoneyCast::format($group->total) }}</span>
                    </div>
                    @foreach ($group->accounts as $account)
                        <div class="flex items-center justify-between py-1 text-sm" wire:key="balance-sheet-account-{{ $account->id }}" data-test="balance-sheet-account">
                            <a href="{{ route('accounts.show', $account) }}" wire:navigate class="hover:underline">{{ $account->name }}</a>
                            <span>{{ MoneyCast::format($group->balances[$account->id]) }}</span>
                        </div>
                    @endforeach
                </div>
            @empty
                <p class="text-sm text-gray-500">{{ __('None') }}</p>
            @endforelse
        </div>
    @endforeach
</section>
