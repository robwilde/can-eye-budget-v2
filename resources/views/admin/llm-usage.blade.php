<x-layouts::app :title="__('LLM usage')">
    <div class="px-4 pt-6">
        @include('admin.partials.nav')
        <livewire:admin-llm-usage />
    </div>
</x-layouts::app>
