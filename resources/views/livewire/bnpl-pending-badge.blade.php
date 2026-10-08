<div class="ms-auto">
    @if ($this->pendingCount > 0)
        <flux:badge size="sm" color="yellow" data-testid="bnpl-pending-badge">{{ $this->pendingCount }}</flux:badge>
    @endif
</div>
