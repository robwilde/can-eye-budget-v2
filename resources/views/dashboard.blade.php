<x-layouts::app :title="__('Dashboard')">
    <div class="flex h-full w-full flex-1 flex-col gap-4 rounded-xl">
        <livewire:account-overview lazy />
        <div class="grid auto-rows-min gap-4 md:grid-cols-2">
            <livewire:spending-by-category lazy />
            <livewire:spending-over-time lazy />
        </div>
    </div>
</x-layouts::app>
