<div>
    <flux:button wire:click="connect" wire:loading.attr="disabled">
        <flux:icon.loading wire:loading wire:target="connect" class="size-4" />
        {{ $action === 'manage' ? __('Manage Connections') : __('Connect Bank') }}
    </flux:button>
</div>
