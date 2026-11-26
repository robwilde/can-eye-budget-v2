<x-layouts::app :title="__('Invite codes')">
    <div class="px-4 pt-6">
        @include('admin.partials.nav')
        <livewire:invite-code-manager />
    </div>
</x-layouts::app>
