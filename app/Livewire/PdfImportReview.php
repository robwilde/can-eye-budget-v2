<?php

declare(strict_types=1);

namespace App\Livewire;

use App\DTOs\PdfImportPlan;
use App\Enums\PdfImportStatus;
use App\Exceptions\Statement\PdfImportRefused;
use App\Livewire\Attributes\NotAudited;
use App\Models\Account;
use App\Models\PdfImport;
use App\Models\PdfImportLine;
use App\Models\User;
use App\Services\Statement\PdfImportPublisher;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Review of one PDF import: its lines, what publishing would do, and the publish, revert,
 * change-account and mark-failed actions.
 *
 * @property-read PdfImport $import
 * @property-read PdfImportPlan|null $plan
 * @property-read Collection<int, Account> $accounts
 * @property-read Collection<int, PdfImportLine> $lines
 * @property-read array<string, int> $counts
 */
#[Layout('layouts::app')]
final class PdfImportReview extends Component
{
    #[Locked]
    public int $pdfImportId;

    public string $accountId = '';

    public function mount(PdfImport $pdfImport): void
    {
        abort_unless($pdfImport->user_id === auth()->id(), 404);

        $this->pdfImportId = $pdfImport->id;
        $this->accountId = (string) ($pdfImport->account_id ?? $pdfImport->statement()->value('account_id') ?? '');
    }

    public function publish(PdfImportPublisher $publisher): void
    {
        $this->resetErrorBag();

        $account = $this->chosenAccount();

        if ($account === null) {
            $this->addError('accountId', __('Choose one of your own accounts.'));

            return;
        }

        $this->run(fn (): PdfImport => $publisher->publish($this->import, $account));
    }

    public function revert(PdfImportPublisher $publisher): void
    {
        $this->resetErrorBag();

        $this->run(fn (): PdfImport => $publisher->revert($this->import));
    }

    public function changeAccount(PdfImportPublisher $publisher): void
    {
        $this->resetErrorBag();

        $account = $this->chosenAccount();

        if ($account === null) {
            $this->addError('accountId', __('Choose one of your own accounts.'));

            return;
        }

        $this->run(fn (): PdfImport => $publisher->changeAccount($this->import, $account));
    }

    public function markFailed(PdfImportPublisher $publisher): void
    {
        $this->resetErrorBag();

        $this->run(fn (): PdfImport => $publisher->markFailed($this->import));
    }

    #[NotAudited]
    public function pollStatus(): void
    {
        unset($this->import, $this->plan, $this->lines, $this->counts);
    }

    #[Computed]
    public function import(): PdfImport
    {
        return PdfImport::query()->forUser($this->authenticatedUser())->with('statement')->findOrFail($this->pdfImportId);
    }

    #[Computed]
    public function plan(): ?PdfImportPlan
    {
        $account = $this->chosenAccount();

        if ($account === null || ! in_array($this->import->status, [PdfImportStatus::Ready, PdfImportStatus::Reverted], true)) {
            return null;
        }

        return app(PdfImportPublisher::class)->plan($this->import, $account);
    }

    /** @return Collection<int, PdfImportLine> */
    #[Computed]
    public function lines(): Collection
    {
        return $this->import->lines()->orderBy('id')->get();
    }

    /** @return array<string, int> */
    #[Computed]
    public function counts(): array
    {
        $summary = $this->import->summary ?? [];

        if ($this->import->status === PdfImportStatus::Published) {
            return [
                'found' => (int) ($summary['found'] ?? 0),
                'recorded' => (int) ($summary['already_recorded'] ?? 0),
                'imported' => (int) ($summary['imported'] ?? 0),
                'waiting' => (int) ($summary['waiting'] ?? 0),
            ];
        }

        return [
            'found' => $this->lines->count(),
            'recorded' => count($this->plan->matched ?? []),
            'imported' => count($this->plan->statementOnly ?? []),
            'waiting' => $this->lines->filter(fn (PdfImportLine $line): bool => $line->date_unreadable || $line->line_date === null)->count(),
        ];
    }

    /** @return Collection<int, Account> */
    #[Computed]
    public function accounts(): Collection
    {
        return Account::query()->where('user_id', $this->authenticatedUser()->id)->tracked()->visible()->orderBy('name')->get(['id', 'user_id', 'name']);
    }

    public function render(): View
    {
        return view('livewire.pdf-import-review');
    }

    /**
     * @param  callable(): PdfImport  $action
     */
    private function run(callable $action): void
    {
        try {
            $action();
        } catch (PdfImportRefused $exception) {
            $this->addError('import', $exception->getMessage());
        }

        unset($this->import, $this->plan, $this->lines, $this->counts);

        if ($this->import->status === PdfImportStatus::Published) {
            $this->accountId = (string) $this->import->account_id;
        }
    }

    private function chosenAccount(): ?Account
    {
        return ctype_digit($this->accountId)
            ? Account::query()->where('user_id', $this->authenticatedUser()->id)->tracked()->find((int) $this->accountId)
            : null;
    }

    private function authenticatedUser(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
