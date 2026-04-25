@php use App\Enums\AccountGroup; use Illuminate\Support\Str; @endphp
<div class="space-y-6">
    <div class="flex items-center justify-between">
        <flux:heading size="xl">{{ __('Accounts') }}</flux:heading>
        <button type="button" wire:click="openAddModal" class="cib-yellow-pill">
            <flux:icon.plus class="size-4"/>
            {{ __('Add Account') }}
        </button>
    </div>

    @forelse($grouped as $groupValue => $accounts)
        @php $groupEnum = AccountGroup::from($groupValue); @endphp
        <section class="agenda-group">
            <x-cib.sec-head :title="$groupEnum->label()">
                <x-cib.stat-pill tone="neutral" :value="(string) $accounts->count()"/>
            </x-cib.sec-head>
            <div class="day-card">
                @foreach($accounts as $account)
                    @php
                        $tone = $account->balance < 0 ? 'out' : 'inc';
                        $metaParts = array_filter([
                            $account->type->label(),
                            $account->institution,
                        ]);
                        $metaText = implode(' · ', $metaParts);
                        if ($account->credit_limit !== null) {
                            $metaText = trim(($metaText ? $metaText.' · ' : '').'Limit '.$formatMoney($account->credit_limit));
                        }
                    @endphp
                    <x-cib.tx-row
                            wire:key="account-{{ $account->id }}"
                            :name="$account->name"
                            :amount="$account->balance"
                            :tone="$tone"
                            :icon="$account->type->icon()"
                    >
                        @if($metaText)
                            <x-slot:meta>{{ $metaText }}</x-slot:meta>
                        @endif
                        <x-slot:actions>
                            <div class="hidden items-center gap-1 md:flex">
                                <flux:button variant="ghost" size="sm" icon="pencil" wire:click="openEditModal({{ $account->id }})"/>
                                <flux:button variant="ghost" size="sm" icon="trash" wire:click="confirmDelete({{ $account->id }})"
                                             class="text-red-500 hover:text-red-600"/>
                            </div>

                            <flux:dropdown class="md:hidden" align="end">
                                <flux:button variant="ghost" size="sm" icon="ellipsis-vertical" :aria-label="__('Account actions')"/>

                                <flux:menu>
                                    <flux:menu.item icon="pencil" wire:click="openEditModal({{ $account->id }})">
                                        {{ __('Edit') }}
                                    </flux:menu.item>
                                    <flux:menu.item icon="trash" variant="danger" wire:click="confirmDelete({{ $account->id }})">
                                        {{ __('Delete') }}
                                    </flux:menu.item>
                                </flux:menu>
                            </flux:dropdown>
                        </x-slot:actions>
                    </x-cib.tx-row>
                @endforeach
            </div>
        </section>
    @empty
        <x-cib.empty-state
                icon="building-library"
                :title="__('No accounts yet')"
                :description="__('Add your first account to start tracking your finances.')"
        >
            <x-slot:action>
                <button type="button" wire:click="openAddModal" class="cib-yellow-pill">
                    <flux:icon.plus class="size-4"/>
                    {{ __('Add Account') }}
                </button>
            </x-slot:action>
        </x-cib.empty-state>
    @endforelse

    <flux:modal wire:model="showFormModal" class="md:w-lg">
        <form wire:submit="save" class="space-y-6">
            <div>
                <flux:heading size="lg">
                    {{ $editingAccountId ? __('Edit Account') : __('Add Account') }}
                </flux:heading>
                <flux:text class="mt-2">
                    {{ $editingAccountId ? __('Update your account details.') : __('Add a new account to track.') }}
                </flux:text>
            </div>

            <div>
                <label class="cib-label" for="account-name">{{ __('Name') }}</label>
                <flux:input
                        id="account-name"
                        wire:model="name"
                        placeholder="e.g. Everyday Account"
                        required
                />
            </div>

            <div>
                <label class="cib-label" for="account-balance">{{ __('Current Balance') }}</label>
                <flux:input
                        id="account-balance"
                        wire:model="balance"
                        type="number"
                        step="0.01"
                        placeholder="0.00"
                        required
                />
            </div>

            <flux:field variant="inline">
                <flux:checkbox wire:model.live="hasCreditLimit"/>
                <flux:label>{{ __('Credit Limit') }}</flux:label>
                <flux:description>{{ __('Check if this is a credit account (e.g. credit card, line of credit)') }}</flux:description>
            </flux:field>

            @if($hasCreditLimit)
                <flux:input
                        wire:model="credit_limit"
                        :label="__('Credit Limit Amount')"
                        type="number"
                        step="0.01"
                        min="0"
                        placeholder="0.00"
                        required
                />
            @endif

            <div>
                <label class="cib-label" for="account-description">{{ __('Description') }}</label>
                <flux:textarea
                        id="account-description"
                        wire:model="description"
                        placeholder="{{ __('Optional description') }}"
                        rows="2"
                />
            </div>

            <div>
                <label class="cib-label" for="account-type">{{ __('Account Type') }}</label>
                <flux:select id="account-type" wire:model="type" required>
                    @foreach($accountTypes as $accountType)
                        <flux:select.option value="{{ $accountType->value }}">{{ $accountType->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            <div>
                <label class="cib-label" for="account-group">{{ __('Account Group') }}</label>
                <flux:select id="account-group" wire:model="group" required>
                    @foreach($accountGroups as $accountGroup)
                        <flux:select.option value="{{ $accountGroup->value }}">{{ $accountGroup->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            <div>
                <label class="cib-label" for="account-institution">{{ __('Institution') }}</label>
                <flux:input
                        id="account-institution"
                        wire:model="institution"
                        placeholder="e.g. Commonwealth Bank"
                />
            </div>

            <div class="flex">
                <flux:spacer/>
                <flux:button type="submit" variant="primary">
                    {{ $editingAccountId ? __('Update Account') : __('Add Account') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal wire:model="showDeleteModal" class="md:w-96">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Delete Account') }}</flux:heading>
                <flux:text class="mt-2">
                    {{ __('Are you sure you want to delete') }} <strong>{{ $deletingAccountName }}</strong>?
                </flux:text>
                @if($deletingTransactionCount > 0)
                    <flux:text class="mt-2 font-medium text-red-600 dark:text-red-500">
                        {{ __('This will also delete :count :transactions associated with this account.', [
                            'count' => $deletingTransactionCount,
                            'transactions' => Str::plural('transaction', $deletingTransactionCount),
                        ]) }}
                    </flux:text>
                @endif
            </div>
            <div class="flex gap-2">
                <flux:spacer/>
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" wire:click="delete">
                    {{ __('Delete Account') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>

    <x-cib.card>
        <flux:modal.trigger name="category-editor">
            <flux:button variant="ghost" icon="tag" class="w-full">
                {{ __('Manage Categories') }}
            </flux:button>
        </flux:modal.trigger>
    </x-cib.card>
</div>
