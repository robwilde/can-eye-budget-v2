<x-layouts::app :title="__('Audit trail')">
    <div class="px-4 pt-6">
        @include('admin.partials.nav')
        <livewire:admin-audit-log />
    </div>
</x-layouts::app>
