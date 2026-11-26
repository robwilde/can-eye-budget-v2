<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\AuditOutcome;
use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * A read-only, paginated view of the audit trail for instance admins. It lists who did what and when; the trail holds
 * no request bodies, and only its own columns are selected here.
 */
final class AdminAuditLog extends Component
{
    use WithPagination;

    public const int PER_PAGE = 50;

    public string $user = '';

    public string $action = '';

    public string $outcome = '';

    public string $from = '';

    public string $to = '';

    public function mount(): void
    {
        Gate::authorize('admin');
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['user', 'action', 'outcome', 'from', 'to'], true)) {
            $this->resetPage();
            $this->validate($this->rules());
        }
    }

    /** @return LengthAwarePaginator<int, AuditEvent> */
    #[Computed]
    public function events(): LengthAwarePaginator
    {
        Gate::authorize('admin');

        $filters = $this->validFilters();

        return AuditEvent::query()
            ->select(['id', 'user_id', 'action', 'subject_type', 'subject_id', 'outcome', 'request_id', 'created_at'])
            ->with('user:id,name,email')
            ->when($filters['user'] !== '', fn (Builder $query) => $query->whereIn('user_id', $this->matchingUserIds($filters['user'])))
            ->when($filters['action'] !== '', fn (Builder $query) => $query->whereRaw("action LIKE ? ESCAPE '!'", ['%'.$this->escapeLike($filters['action']).'%']))
            ->when($filters['outcome'] !== '', fn (Builder $query) => $query->where('outcome', $filters['outcome']))
            ->when($filters['from'] !== '', fn (Builder $query) => $query->whereDate('created_at', '>=', $filters['from']))
            ->when($filters['to'] !== '', fn (Builder $query) => $query->whereDate('created_at', '<=', $filters['to']))
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE);
    }

    public function render(): View
    {
        return view('livewire.admin-audit-log', ['outcomes' => AuditOutcome::cases()]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function rules(): array
    {
        return [
            'user' => ['nullable', 'string', 'max:100'],
            'action' => ['nullable', 'string', 'max:100'],
            'outcome' => ['nullable', Rule::enum(AuditOutcome::class)],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    /**
     * Each filter that passes its rule; one that does not is left out instead of breaking the page.
     *
     * @return array{user: string, action: string, outcome: string, from: string, to: string}
     */
    private function validFilters(): array
    {
        $input = ['user' => mb_trim($this->user), 'action' => mb_trim($this->action), 'outcome' => $this->outcome, 'from' => $this->from, 'to' => $this->to];
        $failed = Validator::make($input, $this->rules())->errors()->keys();

        return array_map(static fn (string $value): string => $value, array_replace($input, array_fill_keys($failed, '')));
    }

    /**
     * @return list<int>
     */
    private function matchingUserIds(string $term): array
    {
        $like = '%'.$this->escapeLike($term).'%';

        return User::query()
            ->where(fn (Builder $query) => $query->whereRaw("email LIKE ? ESCAPE '!'", [$like])->orWhereRaw("name LIKE ? ESCAPE '!'", [$like]))
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }
}
