<div class="mx-auto max-w-2xl space-y-6">
    <div class="flex items-center justify-between gap-4">
        <flux:text size="sm" class="font-bold uppercase tracking-wide text-zinc-500" data-test="connect-bank-step">
            {{ __('Step :step of 2', ['step' => $step]) }}
        </flux:text>
        <flux:link :href="route('dashboard')" wire:navigate data-test="connect-bank-skip">
            {{ __('Skip for now') }}
        </flux:link>
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
    @else
        <livewire:redbark-account-setup :redirect-to-dashboard="true" />
    @endif
</div>
