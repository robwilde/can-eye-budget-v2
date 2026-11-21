<?php

declare(strict_types=1);

namespace App\Livewire;

use App\DTOs\StatementCoverageMonth;
use App\Enums\StatementReviewStatus;
use App\Models\Account;
use App\Models\Statement;
use App\Services\Statement\StatementCoverageService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The Statements tab of an account: a per-month coverage grid for one calendar year and the
 * account's linked statements.
 *
 * @property-read list<int> $years
 */
final class StatementCoverage extends Component
{
    #[Locked]
    public Account $account;

    #[Url]
    public int $year = 0;

    public function mount(Account $account): void
    {
        abort_unless($account->user_id === auth()->id(), 404);

        $this->account = $account;

        if (! in_array($this->year, $this->years, true)) {
            $this->year = $account->user->today()->year;
        }
    }

    public function updatedYear(): void
    {
        if (! in_array($this->year, $this->years, true)) {
            $this->year = $this->account->user->today()->year;
        }
    }

    /** @return list<StatementCoverageMonth> */
    #[Computed]
    public function months(): array
    {
        return app(StatementCoverageService::class)->forAccount($this->account, $this->year);
    }

    /** @return list<int> */
    #[Computed]
    public function years(): array
    {
        [$first, $last] = app(StatementCoverageService::class)->yearRange($this->account);

        return range($last, $first);
    }

    /** @return Collection<int, Statement> */
    #[Computed]
    public function statements(): Collection
    {
        return Statement::query()
            ->where('user_id', $this->account->user_id)
            ->where('account_id', $this->account->id)
            ->where('review_status', StatementReviewStatus::Linked)
            ->orderByDesc('period_end')
            ->orderByDesc('id')
            ->get();
    }

    public function render(): View
    {
        return view('livewire.statement-coverage');
    }
}
