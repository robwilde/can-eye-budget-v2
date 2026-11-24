<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\OccurrenceStatus;
use App\Livewire\Attributes\NotAudited;
use App\Models\PlannedOccurrence;
use App\Models\User;
use App\Services\Recurring\OccurrencePaymentRecorder;
use App\Services\Recurring\PaymentCandidateFinder;
use App\Services\Recurring\PlannedPaymentReview;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Shows what has been paid against one occurrence and lets the user attach a transaction (all of it or a
 * custom amount), record a payment with no transaction, mark it paid or remove a payment. Candidates are the
 * user's own transactions ranked by the same score auto-matching uses. Every change goes through
 * OccurrencePaymentRecorder, so the payment rules and locks are the ones the rest of the app uses.
 */
final class OccurrencePaymentPanel extends Component
{
    public bool $showPanel = false;

    public ?int $occurrenceId = null;

    public string $search = '';

    /** @var array<int, string> */
    public array $customAmounts = [];

    public string $recordAmount = '';

    public string $recordDate = '';

    public bool $changed = false;

    #[NotAudited]
    #[On('open-occurrence-payment-panel')]
    public function open(int $occurrenceId): void
    {
        $this->findOccurrence($occurrenceId);

        $this->reset('search', 'customAmounts', 'recordAmount', 'recordDate', 'changed');
        $this->resetErrorBag();
        $this->occurrenceId = $occurrenceId;
        $this->showPanel = true;
    }

    #[NotAudited]
    public function close(): void
    {
        $this->showPanel = false;
        $this->updatedShowPanel(false);
    }

    public function updatedShowPanel(bool $shown): void
    {
        if ($shown) {
            return;
        }

        $this->occurrenceId = null;

        if ($this->changed) {
            $this->changed = false;
            $this->dispatch('occurrence-payment-changed');
        }
    }

    public function attach(int $transactionId): void
    {
        $custom = mb_trim($this->customAmounts[$transactionId] ?? '');

        if ($custom !== '' && ! $this->validAmount($custom)) {
            return;
        }

        $cents = $custom === '' ? null : $this->cents($custom);

        if ($this->change(fn (User $user, int $occurrenceId) => app(OccurrencePaymentRecorder::class)->attach($user, $transactionId, $occurrenceId, $cents))) {
            unset($this->customAmounts[$transactionId]);
        }
    }

    public function recordPayment(): void
    {
        $amount = mb_trim($this->recordAmount);

        if (! $this->validAmount($amount)) {
            return;
        }

        $date = mb_trim($this->recordDate);

        if ($this->change(fn (User $user, int $occurrenceId) => app(OccurrencePaymentRecorder::class)->record($user, $occurrenceId, $this->cents($amount), $date === '' ? null : $date))) {
            $this->reset('recordAmount', 'recordDate');
        }
    }

    public function markPaid(): void
    {
        $this->change(fn (User $user, int $occurrenceId) => app(OccurrencePaymentRecorder::class)->markPaid($user, $occurrenceId));
    }

    public function unlinkPayment(int $paymentId): void
    {
        $this->change(fn (User $user) => app(PlannedPaymentReview::class)->unlink($user, $paymentId));
    }

    public function removePayment(int $paymentId): void
    {
        $this->change(fn (User $user) => app(OccurrencePaymentRecorder::class)->removePayment($user, $paymentId));
    }

    public function render(): View
    {
        $user = $this->authenticatedUser();
        $occurrence = $this->showPanel && $this->occurrenceId !== null ? $this->findOccurrence($this->occurrenceId) : null;
        $candidates = $occurrence instanceof PlannedOccurrence && $occurrence->status === OccurrenceStatus::Scheduled
            ? app(PaymentCandidateFinder::class)->find($user, $occurrence, $this->search)
            : null;

        return view('livewire.occurrence-payment-panel', [
            'occurrence' => $occurrence,
            'payments' => $occurrence?->payments()->with(['transaction' => fn ($query) => $query->withTrashed()])->orderBy('paid_on')->orderBy('id')->get(),
            'candidates' => $candidates,
        ]);
    }

    private function change(Closure $change): bool
    {
        $this->resetErrorBag('payment');

        if ($this->occurrenceId === null) {
            return false;
        }

        $user = $this->authenticatedUser();

        try {
            $change($user, $this->occurrenceId);
        } catch (ValidationException $refused) {
            $this->addError('payment', (string) collect($refused->errors())->flatten()->first());

            return false;
        } catch (ModelNotFoundException) {
            abort(404);
        }

        $this->changed = true;

        return true;
    }

    private function validAmount(string $amount): bool
    {
        if (preg_match('/^\d+(\.\d{1,2})?$/', $amount) === 1 && $this->cents($amount) > 0) {
            return true;
        }

        $this->addError('payment', __('Use a positive amount with at most 2 decimal places.'));

        return false;
    }

    private function cents(string $amount): int
    {
        return (int) round((float) $amount * 100);
    }

    private function findOccurrence(int $occurrenceId): PlannedOccurrence
    {
        $occurrence = PlannedOccurrence::query()
            ->forUser($this->authenticatedUser())
            ->with(['plannedTransaction.account'])
            ->find($occurrenceId);

        abort_unless($occurrence instanceof PlannedOccurrence, 404);

        return $occurrence;
    }

    private function authenticatedUser(): User
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}
