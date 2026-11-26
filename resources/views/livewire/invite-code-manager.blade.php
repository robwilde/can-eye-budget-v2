<div class="mx-auto max-w-3xl space-y-6" data-test="invite-code-manager">
    <div class="space-y-2">
        <flux:heading size="xl">{{ __('Invite codes') }}</flux:heading>
        <flux:text>
            {{ __('Each code works once and expires. Registration mode: :mode.', ['mode' => str_replace('_', ' ', $mode->value)]) }}
            @unless ($mode->requiresInvite())
                {{ __('Codes are only asked for when the registration mode is invite only (BUDGET_REGISTRATION_MODE).') }}
            @endunless
        </flux:text>
    </div>

    <form wire:submit="generate" class="flex items-end gap-4">
        <flux:input wire:model="validDays" type="number" min="1" max="{{ App\Models\InviteCode::MAX_VALID_DAYS }}" :label="__('Valid for (days)')" data-test="invite-valid-days" />
        <flux:button type="submit" variant="primary" data-test="generate-invite-button">{{ __('Generate code') }}</flux:button>
    </form>
    @error('validDays') <flux:text size="sm" class="text-red-600">{{ $message }}</flux:text> @enderror

    @if ($newCode !== null)
        <flux:callout variant="success" icon="key" data-test="new-invite-code">
            <flux:callout.heading>{{ __('Copy this code now; it is not shown again.') }}</flux:callout.heading>
            <flux:callout.text><code class="break-all font-mono">{{ $newCode }}</code></flux:callout.text>
        </flux:callout>
    @endif

    <div class="space-y-2">
        @forelse ($this->codes as $invite)
            <div class="flex items-center justify-between gap-4 rounded-lg border-2 border-cib-black bg-white px-4 py-2" wire:key="invite-{{ $invite->id }}" data-test="invite-row">
                <div class="min-w-0 text-sm">
                    <span class="font-mono">…{{ $invite->code_hint }}</span>
                    <span class="ms-2 uppercase text-zinc-500">{{ $invite->status() }}</span>
                    <span class="ms-2 text-zinc-500">{{ __('expires :date', ['date' => $invite->expires_at->format('j M Y')]) }}</span>
                </div>
                @if ($invite->status() === 'unused')
                    <flux:button size="sm" variant="ghost" wire:click="revoke({{ $invite->id }})" wire:confirm="{{ __('Revoke this code?') }}" data-test="revoke-invite-button">{{ __('Revoke') }}</flux:button>
                @endif
            </div>
        @empty
            <flux:text>{{ __('No invite codes yet.') }}</flux:text>
        @endforelse
    </div>
</div>
