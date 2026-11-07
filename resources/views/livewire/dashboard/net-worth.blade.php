@use('App\Casts\MoneyCast')

<section class="net-worth space-y-4" data-test="net-worth">
    <x-cib.sec-head :title="__('Net worth')"/>

    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <div @class([
                'text-4xl font-black',
                'text-cib-green-600' => $this->favourable,
                'text-red-600' => ! $this->favourable,
            ]) data-test="net-worth-figure">{{ MoneyCast::format($this->current) }}</div>
            <p class="text-sm text-gray-500">{{ __('Assets minus debts') }}</p>
        </div>
        <flux:select wire:model.live="days" class="max-w-40" data-test="net-worth-period" aria-label="{{ __('Period') }}">
            @foreach ($periods as $period)
                <option value="{{ $period }}">{{ __('Last :days days', ['days' => $period]) }}</option>
            @endforeach
        </flux:select>
    </div>

    <x-cib.balance-chart :points="$this->series" :favourable="$this->favourable" :label="__('Net worth')" data-test="net-worth-chart"/>

    <div data-test="balance-sheet">
        <div class="flex items-center justify-between">
            <x-cib.sec-head :title="__('Balance sheet')"/>
            <flux:select wire:change="setAccountOrder($event.target.value)" class="max-w-52" data-test="account-order" aria-label="{{ __('Order accounts by') }}">
                @foreach ($orders as $order)
                    <option value="{{ $order->value }}" @selected($order === $selectedOrder)>{{ __($order->label()) }}</option>
                @endforeach
            </flux:select>
        </div>

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
                                <span>{{ MoneyCast::format($account->balance) }}</span>
                            </div>
                        @endforeach
                    </div>
                @empty
                    <p class="text-sm text-gray-500">{{ __('None') }}</p>
                @endforelse
            </div>
        @endforeach
    </div>
</section>
