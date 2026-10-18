<x-layouts::app :title="__('Rules')">
    <div class="flex flex-col gap-8">
        <livewire:bnpl-order-review />
        <livewire:recurring-transaction-review />
        <livewire:payee-review :onboarding="false" />
        <livewire:planned-transaction-manager />
        <livewire:user-rule-manager />
        <livewire:transfer-rule-list />
    </div>
</x-layouts::app>
