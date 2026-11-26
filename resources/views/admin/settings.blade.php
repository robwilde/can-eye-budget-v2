<x-layouts::app :title="__('Self-hosting settings')">
    <div class="px-4 pt-6">
        @include('admin.partials.nav')
        <livewire:admin-settings />
    </div>
</x-layouts::app>
