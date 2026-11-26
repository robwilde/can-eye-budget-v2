<x-layouts::app :title="__('Users')">
    <div class="px-4 pt-6">
        @include('admin.partials.nav')
        <livewire:admin-users />
    </div>
</x-layouts::app>
