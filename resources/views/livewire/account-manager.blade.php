@php use App\Enums\AccountGroup; use Illuminate\Support\Str; @endphp
<div class="space-y-6">
    <div class="flex items-center justify-between">
        <flux:heading size="xl">{{ __('Accounts') }}</flux:heading>
        <flux:button variant="primary" icon="plus" wire:click="openAddModal">
            {{ __('Add Account') }}
        </flux:button>
    </div>

    @forelse($grouped as $groupValue => $accounts)
        @php $groupEnum = AccountGroup::from($groupValue); @endphp
        <div>
            <div class="mb-3 flex items-center gap-2">
                <flux:heading size="lg">{{ $groupEnum->label() }}</flux:heading>
                <flux:badge size="sm" color="zinc">{{ $accounts->count() }}</flux:badge>
            </div>

            <div class="rounded-xl border border-neutral-200 dark:border-neutral-700">
                <div class="divide-y divide-neutral-200 dark:divide-neutral-700">
                    @foreach($accounts as $account)
                        <div wire:key="account-{{ $account->id }}" class="flex items-center justify-between px-4 py-3">
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-2">
                                    <flux:icon :name="$account->type->icon()" class="size-5 shrink-0 text-zinc-400"/>
                                    <flux:heading size="sm" class="truncate">{{ $account->name }}</flux:heading>
                                    <flux:badge size="sm" color="zinc">{{ $account->type->label() }}</flux:badge>
                                </div>
                                @if($account->institution)
                                    <flux:text size="sm" class="mt-0.5 pl-7">{{ $account->institution }}</flux:text>
                                @endif
                            </div>
                            <div class="flex items-center gap-4">
                                <div class="text-right">
                                    <flux:text class="tabular-nums font-medium {{ $account->balance < 0 ? 'text-red-600 dark:text-red-500' : '' }}">
                                        {{ $formatMoney($account->balance) }}
                                    </flux:text>
                                    @if($account->credit_limit !== null)
                                        <flux:text size="sm" class="tabular-nums text-zinc-500">
                                            Limit {{ $formatMoney($account->credit_limit) }}
                                        </flux:text>
                                    @endif
                                </div>
                                <div class="flex items-center gap-1">
                                    <flux:button variant="ghost" size="sm" icon="pencil" wire:click="openEditModal({{ $account->id }})"/>
                                    <flux:button variant="ghost" size="sm" icon="trash" wire:click="confirmDelete({{ $account->id }})"
                                                 class="text-red-500 hover:text-red-600"/>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    @empty
        <flux:card class="p-8 text-center">
            <flux:icon.building-library class="mx-auto size-12 text-zinc-400"/>
            <flux:heading size="lg" class="mt-4">{{ __('No accounts yet') }}</flux:heading>
            <flux:text class="mt-2">{{ __('Add your first account to start tracking your finances.') }}</flux:text>
            <div class="mt-6">
                <flux:button variant="primary" icon="plus" wire:click="openAddModal">
                    {{ __('Add Account') }}
                </flux:button>
            </div>
        </flux:card>
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

            <flux:input
                    wire:model="name"
                    :label="__('Name')"
                    placeholder="e.g. Everyday Account"
                    required
            />

            <flux:input
                    wire:model="balance"
                    :label="__('Current Balance')"
                    type="number"
                    step="0.01"
                    placeholder="0.00"
                    required
            />

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

            <flux:textarea
                    wire:model="description"
                    :label="__('Description')"
                    placeholder="{{ __('Optional description') }}"
                    rows="2"
            />

            <flux:select wire:model="type" :label="__('Account Type')" required>
                @foreach($accountTypes as $accountType)
                    <flux:select.option value="{{ $accountType->value }}">{{ $accountType->label() }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model="group" :label="__('Account Group')" required>
                @foreach($accountGroups as $accountGroup)
                    <flux:select.option value="{{ $accountGroup->value }}">{{ $accountGroup->label() }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:input
                    wire:model="institution"
                    :label="__('Institution')"
                    placeholder="e.g. Commonwealth Bank"
            />

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
</div>
