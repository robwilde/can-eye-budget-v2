@php use App\Enums\AccountGroup; use Illuminate\Support\Str; @endphp
<div class="space-y-6">
    <div class="flex items-center justify-between">
        <flux:heading size="xl">{{ __('Accounts') }}</flux:heading>
        <div class="flex items-center gap-2">
            <button type="button" wire:click="openAddUntrackedModal" class="text-sm font-bold underline decoration-dotted"
                    data-test="add-untracked-account">{{ __('Add untracked account') }}</button>
            <button type="button" wire:click="openAddModal" class="cib-yellow-pill">
                <flux:icon.plus class="size-4"/>
                {{ __('Add Account') }}
            </button>
        </div>
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
                        if (! $account->is_tracked) {
                            $metaText = trim(($metaText ? $metaText.' · ' : '').($account->reconciled_on !== null
                                ? __('Reconciled :date', ['date' => $account->reconciled_on->format('j M Y')])
                                    .' · '.__('Difference').' '.$formatMoney($account->reconcile_difference ?? 0)
                                : __('Never reconciled')));
                        }
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
                            @if ($account->status === \App\Enums\AccountStatus::Inactive)
                                <x-cib.stat-pill tone="neutral" data-test="account-disabled-{{ $account->id }}">{{ __('Disabled') }}</x-cib.stat-pill>
                            @endif
                            @unless ($account->is_tracked)
                                <x-cib.stat-pill tone="neutral" data-test="account-untracked-{{ $account->id }}">{{ __('Untracked') }}</x-cib.stat-pill>
                                <button type="button" wire:click="openReconcileModal({{ $account->id }})"
                                        class="text-sm font-bold underline decoration-dotted"
                                        data-test="account-untracked-reconcile-{{ $account->id }}">{{ __('Reconcile') }}</button>
                                <button type="button" wire:click="trackAccount({{ $account->id }})"
                                        wire:confirm="{{ __('Track this account? Its balance will then count towards your available money.') }}"
                                        class="text-sm font-bold underline decoration-dotted"
                                        data-test="account-track-{{ $account->id }}">{{ __('Track this account') }}</button>
                            @endunless
                            @if ($account->redbarkAccount !== null)
                                @if ($account->statementReconciliations->contains(fn ($reconciliation) => ! $reconciliation->isOpen()))
                                    <x-cib.stat-pill tone="income" data-test="account-reconciled-{{ $account->id }}">✓ {{ __('Reconciled') }}</x-cib.stat-pill>
                                @else
                                    <a href="{{ route('accounts.reconcile', $account) }}" wire:navigate
                                       class="text-sm font-bold underline decoration-dotted"
                                       data-test="account-reconcile-link-{{ $account->id }}">{{ __('Reconcile last month') }}</a>
                                @endif
                            @endif
                            <div class="hidden items-center gap-1 md:flex">
                                @if ($account->is_tracked && $account->isManuallyBalanced())
                                    <flux:button variant="ghost" size="sm" icon="scale" wire:click="openReconcileModal({{ $account->id }})"
                                                 :aria-label="__('Reconcile')" data-test="account-reconcile-balance-{{ $account->id }}"/>
                                @endif
                                <flux:button variant="ghost" size="sm" icon="pencil" wire:click="openEditModal({{ $account->id }})"/>
                                @if ($account->redbarkAccount !== null)
                                    <flux:button variant="ghost" size="sm" icon="link-slash" wire:click="unlinkAccount({{ $account->id }})"
                                                 wire:confirm="{{ __('Unlink this account from your bank feed? Every transaction is kept and the account becomes manual.') }}"
                                                 :aria-label="__('Unlink bank feed')" data-test="account-unlink-{{ $account->id }}"/>
                                @endif
                                @if ($account->status === \App\Enums\AccountStatus::Inactive)
                                    <flux:button variant="ghost" size="sm" icon="clock" href="{{ route('transactions', ['account' => $account->id, 'period' => 'all']) }}"
                                                 :aria-label="__('View history')" data-test="account-history-{{ $account->id }}"/>
                                    <flux:button variant="ghost" size="sm" icon="play" wire:click="enableAccount({{ $account->id }})"
                                                 :aria-label="__('Enable')" data-test="account-enable-{{ $account->id }}"/>
                                @else
                                    <flux:button variant="ghost" size="sm" icon="pause" wire:click="disableAccount({{ $account->id }})"
                                                 :aria-label="__('Disable')" data-test="account-disable-{{ $account->id }}"/>
                                @endif
                                <flux:button variant="ghost" size="sm" icon="trash" wire:click="confirmDelete({{ $account->id }})"
                                             class="text-red-500 hover:text-red-600"/>
                            </div>

                            <flux:dropdown class="md:hidden" align="end">
                                <flux:button variant="ghost" size="sm" icon="ellipsis-vertical" :aria-label="__('Account actions')"/>

                                <flux:menu>
                                    <flux:menu.item icon="pencil" wire:click="openEditModal({{ $account->id }})">
                                        {{ __('Edit') }}
                                    </flux:menu.item>
                                    @if ($account->is_tracked && $account->isManuallyBalanced())
                                        <flux:menu.item icon="scale" wire:click="openReconcileModal({{ $account->id }})">
                                            {{ __('Reconcile') }}
                                        </flux:menu.item>
                                    @endif
                                    @unless ($account->is_tracked)
                                        <flux:menu.item icon="scale" wire:click="openReconcileModal({{ $account->id }})">
                                            {{ __('Reconcile') }}
                                        </flux:menu.item>
                                        <flux:menu.item icon="arrow-path" wire:click="trackAccount({{ $account->id }})"
                                                        wire:confirm="{{ __('Track this account? Its balance will then count towards your available money.') }}">
                                            {{ __('Track this account') }}
                                        </flux:menu.item>
                                    @endunless
                                    @if ($account->redbarkAccount !== null)
                                        <flux:menu.item icon="link-slash" wire:click="unlinkAccount({{ $account->id }})"
                                                        wire:confirm="{{ __('Unlink this account from your bank feed? Every transaction is kept and the account becomes manual.') }}">
                                            {{ __('Unlink bank feed') }}
                                        </flux:menu.item>
                                    @endif
                                    @if ($account->status === \App\Enums\AccountStatus::Inactive)
                                        <flux:menu.item icon="clock" href="{{ route('transactions', ['account' => $account->id, 'period' => 'all']) }}">
                                            {{ __('View history') }}
                                        </flux:menu.item>
                                        <flux:menu.item icon="play" wire:click="enableAccount({{ $account->id }})">
                                            {{ __('Enable') }}
                                        </flux:menu.item>
                                    @else
                                        <flux:menu.item icon="pause" wire:click="disableAccount({{ $account->id }})">
                                            {{ __('Disable') }}
                                        </flux:menu.item>
                                    @endif
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
                        :readonly="$balanceReadOnly"
                        :required="! $balanceReadOnly"
                />
                @error('balance')
                    <flux:text class="mt-1 text-red-600 dark:text-red-500" data-test="account-balance-error">{{ $message }}</flux:text>
                @enderror
                @if($balanceReadOnly)
                    <flux:text class="mt-1 text-xs" data-test="account-balance-provider-note">{{ __('This balance is kept up to date by your bank feed or import, so it cannot be edited here.') }}</flux:text>
                @endif
            </div>

            @unless($isUntracked)
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="cib-label" for="account-opening-balance">{{ __('Opening Balance') }}</label>
                    <flux:input id="account-opening-balance" wire:model="openingBalance" type="number" step="0.01" placeholder="0.00" data-test="account-opening-balance"/>
                </div>
                <div>
                    <label class="cib-label" for="account-opening-date">{{ __('Opening Date') }}</label>
                    <flux:input id="account-opening-date" wire:model="openingBalanceDate" type="date" data-test="account-opening-date"/>
                </div>
            </div>
            @error('openingBalanceDate')
                <flux:text class="text-red-600 dark:text-red-500" data-test="account-opening-error">{{ $message }}</flux:text>
            @enderror
            <flux:text class="text-xs">{{ __('Optional. When set, the current balance is worked out from the opening balance and your transactions.') }}</flux:text>
            @endunless

            @unless($isUntracked)
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
            @endunless

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
                <label class="cib-label" for="account-currency">{{ __('Currency') }}</label>
                <flux:select id="account-currency" wire:model="currency" :disabled="$editingAccountId !== null" data-test="account-currency">
                    @foreach($currencies as $code)
                        <flux:select.option value="{{ $code }}">{{ $code }}</flux:select.option>
                    @endforeach
                    @if($editingAccountId !== null && ! in_array($currency, $currencies, true))
                        <flux:select.option value="{{ $currency }}">{{ $currency }}</flux:select.option>
                    @endif
                </flux:select>
                @error('currency')
                    <flux:text class="mt-1 text-red-600 dark:text-red-500" data-test="account-currency-error">{{ $message }}</flux:text>
                @enderror
            </div>

            <div>
                <label class="cib-label" for="account-type">{{ __('Account Type') }}</label>
                <flux:select id="account-type" wire:model="type" required>
                    @foreach($accountTypes as $accountType)
                        <flux:select.option value="{{ $accountType->value }}">{{ $accountType->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            @unless($isUntracked)
            <div>
                <label class="cib-label" for="account-group">{{ __('Account Group') }}</label>
                <flux:select id="account-group" wire:model="group" required>
                    @foreach($accountGroups as $accountGroup)
                        <flux:select.option value="{{ $accountGroup->value }}">{{ $accountGroup->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
            @endunless

            @unless($isUntracked)
            <div>
                <label class="cib-label" for="account-institution">{{ __('Institution') }}</label>
                <flux:input
                        id="account-institution"
                        wire:model="institution"
                        placeholder="e.g. Commonwealth Bank"
                />
            </div>
            @endunless

            <div class="flex">
                <flux:spacer/>
                <flux:button type="submit" variant="primary">
                    {{ $editingAccountId ? __('Update Account') : __('Add Account') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal wire:model="showReconcileModal" class="md:w-96">
        <form wire:submit="reconcile" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Reconcile') }} {{ $reconcilingAccountName }}</flux:heading>
                <flux:text class="mt-2">{{ __('Enter the balance shown by your provider. The difference from the recorded balance is saved.') }}</flux:text>
            </div>

            @if($reconcilePreview === null)
                <div>
                    <label class="cib-label" for="reconcile-balance">{{ __('New Balance') }}</label>
                    <flux:input id="reconcile-balance" wire:model="reconcileBalance" type="number" step="0.01" required/>
                    @error('reconcileBalance') <flux:text class="mt-1 text-red-600 dark:text-red-500">{{ $message }}</flux:text> @enderror
                </div>
                <div>
                    <label class="cib-label" for="reconcile-date">{{ __('As at') }}</label>
                    <flux:input id="reconcile-date" wire:model="reconcileDate" type="date" required/>
                    @error('reconcileDate')
                        <flux:text class="mt-1 text-red-600 dark:text-red-500" data-test="reconcile-error">{{ $message }}</flux:text>
                    @enderror
                </div>
                <div class="flex">
                    <flux:spacer/>
                    <flux:button type="submit" variant="primary" data-test="reconcile-review">{{ __('Review') }}</flux:button>
                </div>
            @else
                <dl class="space-y-2 text-sm" data-test="reconcile-preview">
                    <div class="flex justify-between"><dt>{{ __('As at') }}</dt><dd>{{ \Carbon\CarbonImmutable::parse($reconcilePreview['date'])->format('j M Y') }}</dd></div>
                    <div class="flex justify-between"><dt>{{ __('Balance before') }}</dt><dd data-test="reconcile-before">{{ $formatMoney($reconcilePreview['before']) }}</dd></div>
                    <div class="flex justify-between"><dt>{{ __('Balance after') }}</dt><dd data-test="reconcile-after">{{ $formatMoney($reconcilePreview['after']) }}</dd></div>
                    <div class="flex justify-between font-medium"><dt>{{ __('Difference') }}</dt><dd>{{ $formatMoney($reconcilePreview['after'] - $reconcilePreview['before']) }}</dd></div>
                </dl>
                <div class="flex gap-2">
                    <flux:button type="button" wire:click="$set('reconcilePreview', null)">{{ __('Back') }}</flux:button>
                    <flux:spacer/>
                    <flux:button type="button" variant="primary" wire:click="confirmReconcile" data-test="reconcile-confirm">{{ __('Confirm') }}</flux:button>
                </div>
            @endif
        </form>

        @if($reconciliations->isNotEmpty())
            <div class="mt-6 border-t border-zinc-200 pt-4 dark:border-zinc-700" data-test="reconciliation-history">
                <flux:heading size="sm">{{ __('Earlier reconciliations') }}</flux:heading>
                <ul class="mt-2 space-y-2">
                    @foreach($reconciliations as $reconciliation)
                        <li class="flex items-center justify-between gap-2 text-sm" data-test="reconciliation-{{ $reconciliation->id }}">
                            <span>{{ $reconciliation->valued_on->format('j M Y') }} · {{ $formatMoney($reconciliation->balance) }}</span>
                            <span class="flex gap-2">
                                <button type="button" wire:click="editReconciliation({{ $reconciliation->id }})" class="cib-link" data-test="reconciliation-edit-{{ $reconciliation->id }}">{{ __('Edit') }}</button>
                                <button type="button" wire:click="deleteReconciliation({{ $reconciliation->id }})" wire:confirm="{{ __('Delete this reconciliation?') }}" class="cib-link" data-test="reconciliation-delete-{{ $reconciliation->id }}">{{ __('Delete') }}</button>
                            </span>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
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
                @error('delete')
                    <flux:text class="mt-2 font-medium text-red-600 dark:text-red-500" data-test="delete-error">{{ $message }}</flux:text>
                @enderror
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
