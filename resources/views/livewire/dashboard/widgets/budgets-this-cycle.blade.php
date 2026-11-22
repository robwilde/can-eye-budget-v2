<section>
    <x-cib.sec-head title="Budgets this cycle"/>
    @forelse ($dashboard->budgetsThisCycle as $row)
        <x-cib.budget-row
                :name="$row['budget']->name"
                :spent="$row['spent']"
                :limit="$row['limit']"
        />
    @empty
        <p class="text-sm text-gray-500">No budgets yet.</p>
    @endforelse
</section>
