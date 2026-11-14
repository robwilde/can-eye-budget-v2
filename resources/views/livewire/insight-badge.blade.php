<span>
    @if ($this->unreadCount > 0)
        <flux:badge size="sm" color="red" data-testid="insights-badge" aria-label="{{ trans_choice(':count unread insight|:count unread insights', $this->unreadCount) }}">{{ $this->unreadCount > \App\Livewire\InsightBadge::CAP ? \App\Livewire\InsightBadge::CAP.'+' : $this->unreadCount }}</flux:badge>
    @endif
</span>
