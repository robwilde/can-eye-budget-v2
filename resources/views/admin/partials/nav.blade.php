@php
    $links = [
        'admin.users' => __('Users'),
        'admin.audit' => __('Audit trail'),
        'admin.llm-usage' => __('LLM usage'),
        'admin.invite-codes' => __('Invite codes'),
        'admin.settings' => __('Self-hosting'),
    ];
@endphp

<nav class="mx-auto mb-6 flex max-w-6xl flex-wrap gap-2" aria-label="{{ __('Admin') }}" data-test="admin-nav">
    @foreach ($links as $name => $label)
        <flux:button size="sm" :variant="request()->routeIs($name) ? 'primary' : 'ghost'" :href="route($name)" wire:navigate>{{ $label }}</flux:button>
    @endforeach
</nav>
