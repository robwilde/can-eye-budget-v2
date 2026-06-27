@php
    use App\Casts\MoneyCast;
    use Illuminate\Support\Str;
@endphp

<flux:card>
    <div class="space-y-5">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <flux:heading size="lg">Planned transactions</flux:heading>
                <flux:text size="sm" class="mt-1 text-zinc-500">Manage the recurring plans that match future imports for this account.</flux:text>
            </div>

            <flux:field class="min-w-56">
                <flux:label>Account</flux:label>
                <flux:select wire:model.live="accountId" size="sm">
                    @if($accounts->isEmpty())
                        <flux:select.option value="">No accounts</flux:select.option>
                    @endif

                    @foreach($accounts as $account)
                        <flux:select.option value="{{ $account->id }}">{{ $account->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            </flux:field>
        </div>

        @if($plans->isNotEmpty())
            <div class="overflow-x-auto rounded-lg border border-neutral-200">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-neutral-200 text-left text-zinc-500">
                            <th class="px-4 py-3 font-medium">Description</th>
                            <th class="px-4 py-3 text-right font-medium">Amount</th>
                            <th class="px-4 py-3 font-medium">Direction</th>
                            <th class="px-4 py-3 font-medium">Frequency</th>
                            <th class="px-4 py-3 font-medium">Category</th>
                            <th class="px-4 py-3 font-medium">Matches</th>
                            <th class="px-4 py-3 font-medium">Next occurrence</th>
                            <th class="px-4 py-3 text-right font-medium">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-200">
                        @foreach($plans as $plan)
                            <tr wire:key="planned-transaction-{{ $plan->id }}">
                                <td class="px-4 py-3 align-top">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <flux:text class="font-medium text-zinc-900">{{ $plan->description }}</flux:text>
                                        @unless($plan->is_active)
                                            <flux:badge size="sm" color="yellow">Inactive</flux:badge>
                                        @endunless
                                        @if($plan->is_pay_cycle_income)
                                            <flux:badge size="sm" color="green">Income</flux:badge>
                                        @endif
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-right align-top">
                                    <flux:text class="tabular-nums font-medium text-zinc-900">{{ MoneyCast::format($plan->amount) }}</flux:text>
                                </td>
                                <td class="px-4 py-3 align-top">
                                    <flux:text>{{ Str::headline($plan->direction->value) }}</flux:text>
                                </td>
                                <td class="px-4 py-3 align-top">
                                    <flux:text>{{ $plan->frequency->label() }}</flux:text>
                                </td>
                                <td class="px-4 py-3 align-top">
                                    <flux:text>{{ $plan->category?->fullPath() ?? 'No category' }}</flux:text>
                                </td>
                                <td class="px-4 py-3 align-top">
                                    <flux:badge size="sm" color="purple">{{ $plan->matched_count }} {{ Str::plural('match', (int) $plan->matched_count) }}</flux:badge>
                                </td>
                                <td class="px-4 py-3 align-top">
                                    <flux:text>{{ $plan->next_occurrence?->format('Y-m-d') ?? '—' }}</flux:text>
                                </td>
                                <td class="px-4 py-3 align-top">
                                    <div class="flex justify-end gap-2">
                                        @if($plan->is_pay_cycle_income)
                                            <flux:text size="sm" class="text-zinc-500">Managed from pay cycle</flux:text>
                                        @else
                                            <flux:button variant="ghost" size="sm" wire:click="openEdit({{ $plan->id }})">
                                                <flux:icon.pencil class="size-4" />
                                            </flux:button>
                                            <flux:button variant="ghost" size="sm" wire:click="toggleActive({{ $plan->id }})">
                                                {{ $plan->is_active ? 'Deactivate' : 'Activate' }}
                                            </flux:button>
                                            <flux:button variant="ghost" size="sm" wire:click="confirmDelete({{ $plan->id }})">
                                                <flux:icon.trash class="size-4" />
                                            </flux:button>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <div class="flex flex-col items-center justify-center rounded-lg border border-dashed border-neutral-200 py-10 text-center">
                <flux:icon.calendar-days class="mb-3 size-10 text-zinc-400" />
                <flux:heading size="sm">No planned transactions for this account yet.</flux:heading>
            </div>
        @endif
    </div>

    <flux:modal wire:model="showEditModal" class="max-w-lg">
        <div class="space-y-4">
            <flux:heading size="lg">Edit planned transaction</flux:heading>

            <flux:field>
                <flux:label>Amount</flux:label>
                <flux:input wire:model="amount" type="number" step="0.01" min="0.01" placeholder="0.00" />
                <flux:error name="amount" />
            </flux:field>

            <flux:field>
                <flux:label>Frequency</flux:label>
                <flux:select wire:model="frequency">
                    <flux:select.option value="">Select frequency</flux:select.option>
                    @foreach($frequencies as $recurrenceFrequency)
                        <flux:select.option value="{{ $recurrenceFrequency->value }}">{{ $recurrenceFrequency->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:error name="frequency" />
            </flux:field>

            <flux:field>
                <flux:label>Direction</flux:label>
                <flux:select wire:model="direction">
                    <flux:select.option value="">Select direction</flux:select.option>
                    @foreach($directions as $transactionDirection)
                        <flux:select.option value="{{ $transactionDirection->value }}">{{ Str::headline($transactionDirection->value) }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:error name="direction" />
            </flux:field>

            <x-category-combobox
                wire:model="categoryId"
                :categories="$categories"
                label="Category"
                placeholder="No category"
            />

            <flux:field>
                <flux:label>Until date</flux:label>
                <flux:input wire:model="untilDate" type="date" />
                <flux:error name="untilDate" />
            </flux:field>

            <flux:field>
                <flux:checkbox wire:model="isActive" label="Active" />
            </flux:field>

            <div class="flex justify-end gap-2">
                <flux:button variant="ghost" wire:click="$set('showEditModal', false)">Cancel</flux:button>
                <flux:button variant="primary" wire:click="save">Update</flux:button>
            </div>
        </div>
    </flux:modal>

    <flux:modal wire:model="showDeleteModal" class="max-w-sm">
        <div class="space-y-4">
            <flux:heading size="lg">Delete planned transaction</flux:heading>
            <flux:text>Are you sure you want to delete <strong>{{ $deletingDescription }}</strong>?</flux:text>

            <div class="flex justify-end gap-2">
                <flux:button variant="ghost" wire:click="$set('showDeleteModal', false)">Cancel</flux:button>
                <flux:button variant="danger" wire:click="delete">Delete</flux:button>
            </div>
        </div>
    </flux:modal>
</flux:card>
