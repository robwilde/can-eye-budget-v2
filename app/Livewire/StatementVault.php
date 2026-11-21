<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\StatementReviewStatus;
use App\Exceptions\Statement\StatementHasPdfImports;
use App\Livewire\Concerns\ReportsFailure;
use App\Models\Account;
use App\Models\Statement;
use App\Models\User;
use App\Services\Statement\StatementDetailsUpdater;
use App\Services\Statement\StatementUploadService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Number;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

final class StatementVault extends Component
{
    use ReportsFailure;
    use WithFileUploads;
    use WithPagination;

    private const int PAGE_SIZE = 10;

    private const int MAX_FILES_PER_UPLOAD = 20;

    /** @var list<TemporaryUploadedFile> */
    public array $uploads = [];

    public ?int $uploadAccountId = null;

    public ?string $uploadNotice = null;

    /** @var array<int, int|string|null> */
    public array $linkAccountIds = [];

    #[Locked]
    public ?int $editingStatementId = null;

    public string $institutionHint = '';

    public string $accountNameHint = '';

    public string $lastFourHint = '';

    public string $periodStart = '';

    public string $periodEnd = '';

    public string $openingBalance = '';

    public string $closingBalance = '';

    public string $currency = '';

    /**
     * Files the uploads into the inbox, or straight onto the chosen account.
     */
    public function upload(StatementUploadService $service): void
    {
        $this->clearFailure();
        $this->uploadNotice = null;

        try {
            $this->validate([
                'uploads' => ['required', 'array', 'min:1', 'max:'.self::MAX_FILES_PER_UPLOAD],
                'uploads.*' => ['file', 'max:'.intdiv(StatementUploadService::MAX_BYTES, 1024)],
                'uploadAccountId' => ['nullable', 'integer'],
            ]);
        } catch (ValidationException $exception) {
            $this->uploads = [];

            throw $exception;
        }

        $user = $this->authenticatedUser();
        $account = null;

        if ($this->uploadAccountId !== null) {
            $account = Account::query()->where('user_id', $user->id)->find($this->uploadAccountId);

            if ($account === null) {
                $this->addError('uploadAccountId', __('Choose one of your own accounts.'));

                return;
            }
        }

        $result = $service->upload($user, $this->uploads, $account);

        $this->uploads = [];

        $this->uploadNotice = $result->duplicates > 0
            ? trans_choice(':count duplicate statement was skipped.|:count duplicate statements were skipped.', $result->duplicates)
            : null;

        if ($result->errorMessage() !== null) {
            $this->fail($result->errorMessage());
        }

        $this->resetPage('inboxPage');
        $this->resetPage('linkedPage');
    }

    public function clearUploads(): void
    {
        $this->uploads = [];
        $this->resetErrorBag();
    }

    public function acceptSuggestion(int $statementId): void
    {
        $statement = $this->ownedStatement($statementId);

        if ($statement === null || $statement->suggested_account_id === null) {
            return;
        }

        $this->linkTo($statementId, $statement->suggested_account_id);
    }

    public function rejectSuggestion(int $statementId): void
    {
        $statement = $this->ownedStatement($statementId);

        if ($statement === null || $statement->account_id !== null) {
            return;
        }

        $statement->update([
            'review_status' => StatementReviewStatus::Rejected,
            'suggested_account_id' => null,
            'suggestion_confidence' => null,
        ]);
    }

    public function linkTo(int $statementId, int|string|null $accountId): void
    {
        $statement = $this->ownedStatement($statementId);

        if ($statement === null) {
            return;
        }

        if ($accountId === null || $accountId === '') {
            $this->addError('link.'.$statementId, __('Choose an account to link.'));

            return;
        }

        try {
            $this->link($statement, (int) $accountId);
        } catch (ValidationException) {
            $this->addError('link.'.$statementId, __('Choose one of your own accounts.'));
        }
    }

    public function unlink(int $statementId): void
    {
        $statement = $this->ownedStatement($statementId);

        if ($statement === null || $statement->account_id === null) {
            return;
        }

        $statement->update(['account_id' => null]);
    }

    public function delete(int $statementId): void
    {
        try {
            $this->ownedStatement($statementId)?->delete();
        } catch (StatementHasPdfImports) {
            $this->addError('statement', __('This statement has PDF imports, so it cannot be deleted.'));

            return;
        }

        if ($this->editingStatementId === $statementId) {
            $this->cancelEditing();
        }
    }

    public function startEditing(int $statementId): void
    {
        $statement = $this->ownedStatement($statementId);

        if ($statement === null) {
            return;
        }

        $this->resetErrorBag();
        $this->editingStatementId = $statement->id;
        $this->institutionHint = $statement->institution_hint ?? '';
        $this->accountNameHint = $statement->account_name_hint ?? '';
        $this->lastFourHint = $statement->last_four_hint ?? '';
        $this->periodStart = $statement->period_start?->toDateString() ?? '';
        $this->periodEnd = $statement->period_end?->toDateString() ?? '';
        $this->openingBalance = $this->centsToInput($statement->opening_balance);
        $this->closingBalance = $this->centsToInput($statement->closing_balance);
        $this->currency = $statement->currency ?? '';
    }

    public function cancelEditing(): void
    {
        $this->resetErrorBag();
        $this->editingStatementId = null;
    }

    public function saveDetails(StatementDetailsUpdater $updater): void
    {
        $statement = $this->editingStatementId === null ? null : $this->ownedStatement($this->editingStatementId);

        if ($statement === null) {
            $this->cancelEditing();

            return;
        }

        $this->validate([
            'openingBalance' => ['nullable', 'numeric'],
            'closingBalance' => ['nullable', 'numeric'],
        ]);

        try {
            $updater->update($statement, [
                'institution_hint' => $this->blankToNull($this->institutionHint),
                'account_name_hint' => $this->blankToNull($this->accountNameHint),
                'last_four_hint' => $this->blankToNull($this->lastFourHint),
                'period_start' => $this->blankToNull($this->periodStart),
                'period_end' => $this->blankToNull($this->periodEnd),
                'opening_balance' => $this->inputToCents($this->openingBalance),
                'closing_balance' => $this->inputToCents($this->closingBalance),
                'currency' => $this->blankToNull($this->currency),
            ]);
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->validator->errors());

            return;
        }

        $this->editingStatementId = null;
    }

    /** @return LengthAwarePaginator<int, Statement> */
    #[Computed]
    public function inbox(): LengthAwarePaginator
    {
        return $this->paginateWithin(Statement::query()
            ->where('user_id', $this->authenticatedUser()->id)
            ->whereIn('review_status', [StatementReviewStatus::Unmatched, StatementReviewStatus::Rejected])
            ->with('suggestedAccount:id,name')
            ->orderByDesc('id'), 'inboxPage');
    }

    /** @return LengthAwarePaginator<int, Statement> */
    #[Computed]
    public function linked(): LengthAwarePaginator
    {
        return $this->paginateWithin(Statement::query()
            ->where('user_id', $this->authenticatedUser()->id)
            ->where('review_status', StatementReviewStatus::Linked)
            ->with('account:id,name')
            ->orderByDesc('id'), 'linkedPage');
    }

    #[Computed]
    public function storageUsed(): string
    {
        return Number::fileSize((int) Statement::query()->where('user_id', $this->authenticatedUser()->id)->sum('byte_size'), 0, 1);
    }

    /** @return Collection<int, Account> */
    #[Computed]
    public function accounts(): Collection
    {
        return Account::query()
            ->where('user_id', $this->authenticatedUser()->id)
            ->visible()
            ->orderBy('name')
            ->get(['id', 'user_id', 'name']);
    }

    public function render(): View
    {
        return view('livewire.statement-vault');
    }

    private function link(Statement $statement, int $accountId): void
    {
        app(StatementDetailsUpdater::class)->update($statement, ['account_id' => $accountId]);
    }

    private function ownedStatement(int $statementId): ?Statement
    {
        $statement = Statement::query()->where('user_id', $this->authenticatedUser()->id)->find($statementId);

        if ($statement === null) {
            $this->addError('statement', __('That statement is no longer available.'));
        }

        return $statement;
    }

    /**
     * Paginates, and steps back to the last page when the current one has emptied (for
     * example after the only row on it was deleted), so the lists never look empty while
     * earlier pages still hold rows.
     *
     * @param  Builder<Statement>  $query
     * @return LengthAwarePaginator<int, Statement>
     */
    private function paginateWithin(Builder $query, string $pageName): LengthAwarePaginator
    {
        $page = $query->paginate(self::PAGE_SIZE, pageName: $pageName);

        if ($page->isEmpty() && $page->currentPage() > 1) {
            $this->setPage($page->lastPage(), $pageName);

            return $query->paginate(self::PAGE_SIZE, pageName: $pageName, page: $page->lastPage());
        }

        return $page;
    }

    private function blankToNull(string $value): ?string
    {
        $value = mb_trim($value);

        return $value === '' ? null : $value;
    }

    private function inputToCents(string $value): ?int
    {
        $value = mb_trim($value);

        return $value === '' ? null : (int) round((float) $value * 100);
    }

    private function centsToInput(?int $cents): string
    {
        return $cents === null ? '' : number_format($cents / 100, 2, '.', '');
    }

    private function authenticatedUser(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
