<div class="space-y-6" @if ($this->isSyncing) wire:poll.3s @endif>
    <div>
        <flux:heading size="lg">{{ __('Set up your Redbark accounts') }}</flux:heading>
        <flux:subheading>
            {{ __('Choose what happens to each account Redbark found. Linking to an existing account keeps the transactions already on it.') }}
        </flux:subheading>
    </div>

    @if ($rowErrors !== [])
        <flux:callout variant="danger" icon="exclamation-triangle">
            <flux:callout.heading>{{ __('Some accounts could not be set up') }}</flux:callout.heading>
            <flux:callout.text>
                <ul class="list-disc space-y-1 ps-4">
                    @foreach ($rowErrors as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </flux:callout.text>
        </flux:callout>
    @endif

    @if ($this->isSyncing)
        <flux:callout icon="arrow-path">
            <flux:callout.heading>{{ __('Fetching your accounts from Redbark…') }}</flux:callout.heading>
            <flux:callout.text>{{ __('This can take a moment. This page will update automatically.') }}</flux:callout.text>
        </flux:callout>
    @elseif ($this->redbarkAccounts->isEmpty())
        <flux:callout icon="check-circle">
            <flux:callout.heading>{{ __('Nothing left to set up') }}</flux:callout.heading>
            <flux:callout.text>{{ __('Every Redbark account is either connected or skipped.') }}</flux:callout.text>
            <x-slot name="actions">
                <flux:button :href="route('providers.edit')" wire:navigate size="sm">
                    {{ __('Back to providers') }}
                </flux:button>
            </x-slot>
        </flux:callout>
    @else
        <form wire:submit="save" class="space-y-6">
            @foreach ($this->redbarkAccounts as $redbarkAccount)
                <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700" wire:key="redbark-setup-{{ $redbarkAccount->id }}">
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <flux:heading size="sm">{{ $redbarkAccount->name }}</flux:heading>

                        <flux:text size="sm" class="text-zinc-500">
                            {{ $redbarkAccount->account_type ?? __('account') }} ·
                            {{ $redbarkAccount->currency }} ·
                            {{ $redbarkAccount->current_balance === null
                                ? __('balance unknown')
                                : App\Casts\MoneyCast::format($redbarkAccount->current_balance) }}
                        </flux:text>
                    </div>

                    <flux:select
                        wire:model="choices.{{ $redbarkAccount->id }}"
                        class="mt-3"
                        :label="__('What should happen to this account?')"
                        data-test="redbark-choice-{{ $redbarkAccount->id }}"
                    >
                        <optgroup label="{{ __('Create a new account') }}">
                            @foreach ($accountClasses as $class)
                                <flux:select.option value="new:{{ $class->value }}">
                                    {{ __('New :type account', ['type' => $class->label()]) }}
                                </flux:select.option>
                            @endforeach
                        </optgroup>

                        @if ($this->availableAccounts->isNotEmpty())
                            <optgroup label="{{ __('Link to an existing account') }}">
                                @foreach ($this->availableAccounts as $account)
                                    <flux:select.option value="existing:{{ $account->id }}">
                                        {{ $account->name }}
                                    </flux:select.option>
                                @endforeach
                            </optgroup>
                        @endif

                        <flux:select.option value="skip">{{ __('Skip this account') }}</flux:select.option>
                    </flux:select>
                </div>
            @endforeach

            <div class="flex items-center gap-4">
                <flux:button variant="primary" type="submit" data-test="save-redbark-accounts-button">
                    {{ __('Finish setup') }}
                </flux:button>

                <flux:button :href="route('providers.edit')" wire:navigate variant="subtle">
                    {{ __('Cancel') }}
                </flux:button>
            </div>
        </form>
    @endif
</div>
