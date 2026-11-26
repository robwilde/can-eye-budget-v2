<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\LlmUsage;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * What the AI features cost: LlmUsage totals per operation, per model and per user over a date range, in integer cents.
 * The ledger holds counts and cost only, never prompts or replies.
 */
final class AdminLlmUsage extends Component
{
    public const int MAX_USERS = 50;

    public string $from = '';

    public string $to = '';

    public function mount(): void
    {
        Gate::authorize('admin');

        $today = CarbonImmutable::today();

        $this->to = $today->toDateString();
        $this->from = $today->subDays(29)->toDateString();
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['from', 'to'], true)) {
            $this->validate($this->rules());
        }
    }

    /** @return array{operations: list<array<string, mixed>>, models: list<array<string, mixed>>, users: list<array<string, mixed>>, total: int, calls: int} */
    #[Computed]
    public function report(): array
    {
        Gate::authorize('admin');

        $operations = $this->totals('operation');
        $models = $this->totals('model');
        $users = $this->totals('user_id', self::MAX_USERS);

        $names = User::query()->whereIn('id', array_column($users, 'key'))->pluck('email', 'id');

        return [
            'operations' => array_map(static fn (array $row): array => $row + ['label' => (string) $row['key']], $operations),
            'models' => array_map(static fn (array $row): array => $row + ['label' => (string) $row['key']], $models),
            'users' => array_map(static fn (array $row): array => $row + ['label' => (string) ($names[$row['key']] ?? '')], $users),
            'total' => (int) array_sum(array_column($operations, 'cost')),
            'calls' => (int) array_sum(array_column($operations, 'calls')),
        ];
    }

    public function render(): View
    {
        return view('livewire.admin-llm-usage');
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function rules(): array
    {
        return [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    /**
     * @return array{from: string, to: string}
     */
    private function validRange(): array
    {
        $input = ['from' => $this->from, 'to' => $this->to];
        $failed = Validator::make($input, $this->rules())->errors()->keys();

        return array_replace($input, array_fill_keys($failed, ''));
    }

    /**
     * @return list<array{key: int|string, calls: int, prompt_tokens: int, completion_tokens: int, cost: int}>
     */
    private function totals(string $column, ?int $limit = null): array
    {
        $range = $this->validRange();

        $query = LlmUsage::query()
            ->selectRaw($column.' as grouping_key, COUNT(*) as calls, SUM(prompt_tokens) as prompt_tokens, SUM(completion_tokens) as completion_tokens, SUM(cost) as cost')
            ->when($range['from'] !== '', fn (Builder $inner) => $inner->whereDate('created_at', '>=', $range['from']))
            ->when($range['to'] !== '', fn (Builder $inner) => $inner->whereDate('created_at', '<=', $range['to']))
            ->groupBy($column)
            ->orderByDesc('cost')
            ->orderBy($column);

        if ($limit !== null) {
            $query->limit($limit);
        }

        return $query->get()->map(static fn (LlmUsage $row): array => [
            'key' => $column === 'user_id' ? (int) $row->getAttribute('grouping_key') : (string) $row->getAttribute('grouping_key'),
            'calls' => (int) $row->getAttribute('calls'),
            'prompt_tokens' => (int) $row->getAttribute('prompt_tokens'),
            'completion_tokens' => (int) $row->getAttribute('completion_tokens'),
            'cost' => (int) $row->getAttribute('cost'),
        ])->all();
    }
}
