<div>
    <flux:modal wire:model="showModal" class="max-w-lg" data-testid="plan-declaration-modal">
        <div class="space-y-4">
            <flux:heading size="lg">{{ $kind === 'income' ? __('Add income') : __('Add bill') }}</flux:heading>

            <div class="max-h-[60vh] space-y-4 overflow-y-auto px-1">
                <flux:field>
                    <flux:label>{{ __('Description') }}</flux:label>
                    <flux:input wire:model="description" type="text" maxlength="255" placeholder="{{ $kind === 'income' ? __('Salary') : __('Rent') }}" data-testid="declare-description" />
                    <flux:error name="description" />
                </flux:field>

                <div class="grid grid-cols-2 gap-3">
                    <flux:field>
                        <flux:label>{{ __('Amount') }}</flux:label>
                        <flux:input wire:model="amount" type="number" step="0.01" min="0.01" placeholder="0.00" data-testid="declare-amount" />
                        <flux:error name="amount" />
                    </flux:field>

                    <flux:field>
                        <flux:label>{{ $kind === 'income' ? __('First pay date') : __('First due date') }}</flux:label>
                        <flux:input wire:model="startDate" type="date" data-testid="declare-start-date" />
                        <flux:error name="startDate" />
                    </flux:field>
                </div>

                <flux:field>
                    <flux:label>{{ __('Repeats') }}</flux:label>
                    <flux:select wire:model="frequency" data-testid="declare-frequency">
                        @foreach($frequencies as $recurrenceFrequency)
                            <flux:select.option value="{{ $recurrenceFrequency->value }}">{{ $recurrenceFrequency->label() }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:error name="frequency" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ $kind === 'income' ? __('Paid into') : __('Paid from') }}</flux:label>
                    <flux:select wire:model="accountId" data-testid="declare-account">
                        <flux:select.option value="">{{ __('Choose an account') }}</flux:select.option>
                        @foreach($accounts as $account)
                            <flux:select.option value="{{ $account->id }}">{{ $account->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:error name="accountId" />
                </flux:field>

                <x-category-combobox
                    wire:model="categoryId"
                    :categories="$categories"
                    :label="__('Category')"
                    :placeholder="__('No category')"
                />

                <flux:field>
                    <flux:label>{{ __('Merchant') }}</flux:label>
                    <flux:select wire:model="merchantId" data-testid="declare-merchant">
                        <flux:select.option value="">{{ __('No merchant') }}</flux:select.option>
                        @foreach($merchants as $merchant)
                            <flux:select.option value="{{ $merchant->id }}">{{ $merchant->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:error name="merchantId" />
                </flux:field>

                @if($kind !== 'income')
                    <flux:field>
                        <flux:label>{{ __('Payment link') }}</flux:label>
                        <flux:input wire:model.live.blur="paymentUrl" type="text" inputmode="url" maxlength="2048" autocomplete="off" placeholder="example.com/pay" data-testid="declare-payment-url" />
                        <flux:error name="paymentUrl" />
                    </flux:field>

                    <flux:checkbox wire:model="autopay" :label="__('Autopay')" :description="__('The biller takes this payment automatically.')" data-testid="declare-autopay" />
                @endif

                <flux:field>
                    <flux:label>{{ __('Notes') }}</flux:label>
                    <flux:textarea wire:model="notes" rows="2" maxlength="1000" />
                    <flux:error name="notes" />
                </flux:field>
            </div>

            <div class="flex justify-end gap-2">
                <flux:button variant="ghost" wire:click="$set('showModal', false)">{{ __('Cancel') }}</flux:button>
                <flux:button variant="primary" wire:click="save" data-testid="declare-save">{{ $kind === 'income' ? __('Add income') : __('Add bill') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
