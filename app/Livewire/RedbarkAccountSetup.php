<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\AccountClass;
use App\Enums\AccountGroup;
use App\Enums\AccountStatus;
use App\Enums\ImportSource;
use App\Enums\RefreshStatus;
use App\Enums\RefreshTrigger;
use App\Jobs\SyncRedbarkFeedJob;
use App\Models\Account;
use App\Models\RedbarkAccount;
use App\Models\RedbarkFeed;
use App\Models\RedbarkSyncLog;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * One-time wizard: every upstream Redbark account is either created as a new app
 * account, linked to an existing one, or skipped for good.
 *
 * Linking to an existing account is the whole point of that option — it keeps the
 * history already on that account instead of starting a parallel one beside it.
 */
final class RedbarkAccountSetup extends Component
{
    /** @var array<int, string> keyed by RedbarkAccount id */
    public array $choices = [];

    /** @var list<string> */
    public array $rowErrors = [];

    public function mount(): void
    {
        $feed = $this->feed();

        if ($feed === null) {
            $this->redirect(route('providers.edit'), navigate: true);

            return;
        }

        // Straight after saving a key the queued sync may not have run yet, which would
        // leave the wizard empty. Queue it and let the view poll for the result instead
        // of blocking the request on a backfill that can outlive the request lifetime.
        if ($feed->last_synced_at === null) {
            SyncRedbarkFeedJob::dispatchFor($feed, RefreshTrigger::Manual);
        }

        foreach ($this->redbarkAccounts() as $redbarkAccount) {
            $this->choices[$redbarkAccount->id] ??= 'skip';
        }
    }

    /** @return Collection<int, RedbarkAccount> */
    #[Computed]
    public function redbarkAccounts(): Collection
    {
        $feed = $this->feed();

        if ($feed === null) {
            return new Collection;
        }

        return $feed->accounts()->needsSetup()->orderBy('name')->get();
    }

    /** @return Collection<int, Account> */
    #[Computed]
    public function availableAccounts(): Collection
    {
        return Account::query()
            ->where('user_id', Auth::id())
            ->whereDoesntHave('redbarkAccount')
            ->orderBy('name')
            ->get();
    }

    /**
     * Mirrors the providers panel: only worth polling while a run is actually open. A
     * Pending row older than SyncRedbarkFeedJob::UNIQUE_FOR is stranded, not syncing.
     */
    #[Computed]
    public function isSyncing(): bool
    {
        $feed = $this->feed();

        if ($feed === null || $feed->last_synced_at !== null) {
            return false;
        }

        $log = RedbarkSyncLog::query()
            ->where('redbark_feed_id', $feed->id)
            ->latest('id')
            ->first();

        return $log?->status === RefreshStatus::Pending
            && $log->created_at->greaterThan(now()->subSeconds(SyncRedbarkFeedJob::UNIQUE_FOR));
    }

    public function save(): void
    {
        $feed = $this->feed();

        if ($feed === null) {
            $this->redirect(route('providers.edit'), navigate: true);

            return;
        }

        // Drop choices for rows this feed already resolved (linked or skipped) since the
        // last save; resubmitting must not re-apply a stale 'new:'/'existing:' choice.
        // Ids that are not one of this feed's rows at all (e.g. another user's account)
        // are left in place so the ownership check below still rejects them.
        $resolvedIds = $feed->accounts()
            ->whereNotIn('id', $this->redbarkAccounts()->pluck('id'))
            ->pluck('id')
            ->all();
        $this->choices = array_diff_key($this->choices, array_flip($resolvedIds));

        $this->rowErrors = [];
        $linked = 0;

        foreach ($this->choices as $redbarkAccountId => $choice) {
            $redbarkAccount = RedbarkAccount::query()->with('feed')->find($redbarkAccountId);

            if ($redbarkAccount === null) {
                continue;
            }

            abort_unless($redbarkAccount->feed->user_id === Auth::id(), 403);

            try {
                // Each row commits on its own so one bad choice cannot cost the user the
                // rest of the batch; the failures are reported back on the wizard.
                DB::transaction(function () use ($redbarkAccount, $choice, &$linked): void {
                    $linked += $this->applyChoice($redbarkAccount, $choice);
                });
            } catch (HttpException $e) {
                throw $e;
            } catch (Throwable $e) {
                $this->rowErrors[] = "{$redbarkAccount->name}: {$e->getMessage()}";
            }
        }

        $feed->update([
            'pending_account_setup' => $feed->accounts()->needsSetup()->exists(),
        ]);

        // @phpstan-ignore property.notFound
        unset($this->redbarkAccounts, $this->availableAccounts);

        if ($linked > 0) {
            SyncRedbarkFeedJob::dispatchFor($feed, RefreshTrigger::Manual);
        }

        if ($this->rowErrors !== []) {
            return;
        }

        session()->flash('status', __('Redbark accounts set up.'));

        $this->redirect(route('accounts'), navigate: true);
    }

    public function render(): View
    {
        return view('livewire.redbark-account-setup', [
            'accountClasses' => $this->offeredAccountClasses(),
        ]);
    }

    private function feed(): ?RedbarkFeed
    {
        return RedbarkFeed::query()->where('user_id', Auth::id())->first();
    }

    /** @return int 1 when the row ended up linked to an app account */
    private function applyChoice(RedbarkAccount $redbarkAccount, string $choice): int
    {
        if ($choice === 'skip') {
            $redbarkAccount->update(['ignored' => true]);

            return 0;
        }

        if (str_starts_with($choice, 'existing:')) {
            $account = Account::query()->findOrFail((int) mb_substr($choice, mb_strlen('existing:')));

            abort_unless($account->user_id === Auth::id(), 403);

            if ($account->redbarkAccount()->exists()) {
                throw new RuntimeException(__('That account is already connected to Redbark.'));
            }

            $this->link($redbarkAccount, $account);

            return 1;
        }

        $class = str_starts_with($choice, 'new:')
            ? AccountClass::tryFrom(mb_substr($choice, mb_strlen('new:')))
            : null;

        if ($class === null || ! in_array($class, $this->offeredAccountClasses(), true)) {
            throw new RuntimeException(__('Unrecognised choice for this account.'));
        }

        $this->link($redbarkAccount, Account::create([
            'user_id' => Auth::id(),
            'name' => $redbarkAccount->name,
            'type' => $class,
            'institution' => $redbarkAccount->institution_name ?? '',
            'currency' => $redbarkAccount->currency,
            'balance' => $redbarkAccount->current_balance ?? 0,
            'balance_source' => $redbarkAccount->current_balance === null ? null : ImportSource::Redbark,
            'balance_updated_at' => $redbarkAccount->current_balance === null ? null : now(),
            'account_last4' => $redbarkAccount->account_number === null
                ? null
                : mb_substr($redbarkAccount->account_number, -4),
            'import_source' => ImportSource::Redbark,
            'group' => AccountGroup::DayToDay,
            'status' => AccountStatus::Active,
        ]));

        return 1;
    }

    private function link(RedbarkAccount $redbarkAccount, Account $account): void
    {
        $redbarkAccount->update(['account_id' => $account->id, 'ignored' => false]);
        $account->update(['import_source' => ImportSource::Redbark]);
    }

    /** @return list<AccountClass> the classes offered for a 'new:' choice */
    private function offeredAccountClasses(): array
    {
        return [
            AccountClass::Transaction,
            AccountClass::Savings,
            AccountClass::CreditCard,
            AccountClass::Loan,
            AccountClass::Mortgage,
        ];
    }
}
