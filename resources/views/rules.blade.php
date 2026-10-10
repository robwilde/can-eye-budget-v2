<x-layouts::app :title="__('Rules')">
    <livewire:bnpl-order-review />
    <livewire:recurring-transaction-review />
    <livewire:payee-review :onboarding="false" />
    <livewire:planned-transaction-manager />
    <livewire:user-rule-manager />
    <livewire:transfer-rule-list />
</x-layouts::app>
