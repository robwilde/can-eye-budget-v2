<div class="mx-auto max-w-2xl space-y-6">
    <div class="flex items-center justify-between gap-4">
        <flux:text size="sm" class="font-bold uppercase tracking-wide text-zinc-500" data-test="connect-bank-step">
            @if ($step === \App\Livewire\ConnectBank::STEP_GMAIL)
                {{ __('Optional last step') }}
            @else
                {{ __('Step :step of :total', ['step' => $step, 'total' => \App\Livewire\ConnectBank::TOTAL_STEPS]) }}
            @endif
        </flux:text>
        @if ($step === \App\Livewire\ConnectBank::STEP_GMAIL)
            <flux:link :href="route('dashboard')" wire:click.prevent="finish" data-test="connect-bank-skip">
                {{ __('Skip for now') }}
            </flux:link>
        @else
            <flux:link :href="route('dashboard')" wire:navigate data-test="connect-bank-skip">
                {{ __('Skip for now') }}
            </flux:link>
        @endif
    </div>

    @if ($step === \App\Livewire\ConnectBank::STEP_CONNECT)
        <div class="space-y-2">
            <flux:heading size="xl">{{ __('Connect your bank') }}</flux:heading>
            <flux:text>
                {{ __('Can I Budget reads your transactions through Redbark, an Australian open banking (CDR) feed. You bring your own Redbark API key; we store it encrypted and never see your bank password.') }}
            </flux:text>
            <flux:text>
                {{ __('Your first sync covers transactions from the 1st of last month.') }}
            </flux:text>
            <flux:text>
                {{ __('New to Redbark?') }}
                <flux:link :href="route('setup-guide')" external rel="noopener" data-test="connect-bank-guide">{{ __('Read the setup guide') }}</flux:link>
                {{ __('(opens in a new tab).') }}
            </flux:text>
        </div>

        <form wire:submit="connect" class="space-y-4">
            <flux:input
                wire:model="api_key"
                type="password"
                :label="__('Redbark API key')"
                :description="__('Create a key at app.redbark.com under Settings > API Keys. Requires a Developer or Professional plan.')"
                autocomplete="off"
                data-test="connect-bank-api-key"
            />

            <flux:button type="submit" variant="primary" data-test="connect-bank-submit">
                {{ __('Connect') }}
            </flux:button>
        </form>
    @elseif ($step === \App\Livewire\ConnectBank::STEP_ACCOUNTS)
        <livewire:redbark-account-setup :in-onboarding="true" />
    @elseif ($step === \App\Livewire\ConnectBank::STEP_PAY_CYCLE)
        <livewire:confirm-pay-cycle-step />
    @else
        <div class="space-y-4" data-test="onboarding-gmail-step">
            <div class="space-y-2">
                <flux:heading size="xl">{{ __('Connect Gmail (optional)') }}</flux:heading>
                <flux:text>
                    {{ __('Link your own Gmail so Can Eye can find buy-now-pay-later schedules and receipts for your transactions. Can Eye signs in over IMAP with a Google app password and only searches your mail for matches; it never sends mail. The password is stored encrypted and only you can use it.') }}
                </flux:text>
                <flux:text>
                    {{ __('You can skip this and connect later under Settings > Providers.') }}
                </flux:text>
            </div>

            <livewire:gmail-connection />

            <flux:button wire:click="finish" variant="ghost" data-test="onboarding-gmail-skip">
                {{ __('Skip, I will do this later') }}
            </flux:button>
        </div>
    @endif
</div>
