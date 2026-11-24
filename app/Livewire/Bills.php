<?php

declare(strict_types=1);

namespace App\Livewire;

use App\DTOs\BillNotice;
use App\DTOs\BillRow;
use App\DTOs\BillsMonth;
use App\DTOs\BillsOverview;
use App\Enums\OccurrenceState;
use App\Enums\PlannedTransactionType;
use App\Enums\SuggestionStatus;
use App\Enums\SuggestionType;
use App\Models\AnalysisSuggestion;
use App\Models\PlannedPayment;
use App\Models\PlannedTransaction;
use App\Models\User;
use App\Services\Bills\BillNoticeBuilder;
use App\Services\Bills\BillsCalendarService;
use App\Services\Bills\BillsOverviewService;
use App\Services\Projection\PaycheckPlanner;
use App\Services\Recurring\PlannedPaymentReview;
use App\Services\Recurring\RecurringGate;
use App\Services\Recurring\RecurringScanner;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The Bills workspace: an overview of what is past due, due this month, later and paused, the headline figures,
 * and an all-bills list with search, filters and sorting. It hosts the add-bill and add-income form and the
 * occurrence payment panel. Everything is read through BillsOverviewService for the signed-in user.
 *
 * @property-read BillsOverview $overview
 * @property-read BillsMonth $calendar
 * @property-read list<BillNotice> $notices
 * @property-read list<array{startsOn: CarbonImmutable, sources: list<string>, rows: list<BillRow>, obligation: int}> $payMarkers
 * @property-read Collection<int, BillRow> $allBills
 * @property-read array<int, int> $reviewOccurrences
 * @property-read bool $hasBills
 * @property-read int $firstVisitCount
 */
final class Bills extends Component
{
    public const string TAB_OVERVIEW = 'overview';

    public const string TAB_ALL = 'all';

    public const string TAB_CALENDAR = 'calendar';

    #[Url(except: self::TAB_OVERVIEW)]
    public string $tab = self::TAB_OVERVIEW;

    #[Url(except: '')]
    public string $month = '';

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $payment = '';

    #[Url(except: '')]
    public string $lifecycle = '';

    #[Url(except: '')]
    public string $type = '';

    #[Url(except: 'name')]
    public string $sort = 'name';

    #[Url(except: false)]
    public bool $descending = false;

    public string $notice = '';

    #[Locked]
    public ?int $openPlanId = null;

    /** @var array<string, string> */
    protected $listeners = [
        'occurrence-payment-changed' => '$refresh',
        'plan-declared' => '$refresh',
    ];

    public function mount(): void
    {
        $requested = (int) request()->query('plan');

        $this->openPlanId = $requested > 0 ? $requested : null;
    }

    public function showTab(string $tab): void
    {
        $this->tab = match ($tab) {
            self::TAB_ALL => self::TAB_ALL,
            self::TAB_CALENDAR => self::TAB_CALENDAR,
            default => self::TAB_OVERVIEW,
        };
    }

    public function previousMonth(): void
    {
        $this->month = $this->calendarService()->resolveMonth($this->user(), $this->month)->subMonthNoOverflow()->format('Y-m');
    }

    public function nextMonth(): void
    {
        $this->month = $this->calendarService()->resolveMonth($this->user(), $this->month)->addMonthNoOverflow()->format('Y-m');
    }

    public function goToToday(): void
    {
        $this->month = '';
    }

    public function sortBy(string $sort): void
    {
        $sort = $sort === 'amount' ? 'amount' : 'name';

        $this->descending = $this->sort === $sort ? ! $this->descending : false;
        $this->sort = $sort;
    }

    public function findMyBills(RecurringScanner $scanner): void
    {
        $user = $this->user();

        if (! RecurringGate::enabled($user)) {
            Flux::toast(text: __('Recurring is switched off. Switch it on in Settings → Recurring.'), variant: 'warning');

            return;
        }

        $count = $scanner->run($user);

        $this->notice = $count === null
            ? __('A scan is already running. Try again in a moment.')
            : trans_choice('Found :count new recurring suggestion. Review it on the Rules page.|Found :count new recurring suggestions. Review them on the Rules page.', $count, ['count' => $count]);

        if ($count !== null) {
            Flux::toast(text: $this->notice, variant: 'success');
        }
    }

    #[Computed]
    public function calendar(): BillsMonth
    {
        return $this->calendarService()->month($this->user(), $this->month);
    }

    /**
     * The pay periods of the current month with the bills due in each, only when income splits the month.
     *
     * @return list<array{startsOn: CarbonImmutable, sources: list<string>, rows: list<BillRow>, obligation: int}>
     */
    #[Computed]
    public function payMarkers(): array
    {
        $markers = app(PaycheckPlanner::class)->monthMarkers($this->user());

        if ($markers === []) {
            return [];
        }

        $rows = $this->overview->thisMonth;

        return array_map(static function (array $marker, int $index) use ($markers, $rows): array {
            $next = $markers[$index + 1]['startsOn'] ?? null;
            $inPeriod = array_values(array_filter($rows, static fn (BillRow $row): bool => $row->dueOn->greaterThanOrEqualTo($marker['startsOn']) && ($next === null || $row->dueOn->lessThan($next))));

            return [...$marker, 'rows' => $inPeriod, 'obligation' => (int) array_sum(array_map(static fn (BillRow $row): int => $row->expected, $inPeriod))];
        }, $markers, array_keys($markers));
    }

    /** @return list<BillNotice> */
    #[Computed]
    public function notices(): array
    {
        return app(BillNoticeBuilder::class)->build($this->user());
    }

    #[Computed]
    public function overview(): BillsOverview
    {
        return app(BillsOverviewService::class)->overview($this->user());
    }

    /** @return Collection<int, BillRow> */
    #[Computed]
    public function allBills(): Collection
    {
        return app(BillsOverviewService::class)->allBills(
            $this->user(),
            $this->search,
            OccurrenceState::tryFrom($this->payment),
            in_array($this->lifecycle, ['paused', 'ended'], true) ? $this->lifecycle : null,
            PlannedTransactionType::tryFrom($this->type),
            $this->sort === 'amount' ? 'amount' : 'name',
            $this->descending,
        );
    }

    /**
     * The occurrences that have a payment match waiting for review, mapped to the account the match is on. Only
     * matches the Rules page can still confirm count, so Review never leads nowhere.
     *
     * @return array<int, int>
     */
    #[Computed]
    public function reviewOccurrences(): array
    {
        return app(PlannedPaymentReview::class)->actionable($this->user())
            ->mapWithKeys(static fn (array $row): array => [$row['occurrence']->id => (int) ($row['suggestion']->payload['account_id'] ?? 0)])
            ->all();
    }

    /** Whether the user has any bill at all, in any section or filter. */
    #[Computed]
    public function hasBills(): bool
    {
        return PlannedTransaction::query()->where('user_id', $this->user()->id)->payable()->exists();
    }

    /**
     * Pending detected bills to look at on a first visit. It drops to zero once the user has declared a plan,
     * dismissed a suggestion or recorded a payment: they have found their way around.
     */
    #[Computed]
    public function firstVisitCount(): int
    {
        $user = $this->user();

        $engaged = PlannedTransaction::query()->where('user_id', $user->id)->where('origin', 'declared')->exists()
            || AnalysisSuggestion::query()->where('user_id', $user->id)->ofType(SuggestionType::RecurringTransaction)->where('status', SuggestionStatus::Rejected)->exists()
            || PlannedPayment::query()->forUser($user)->exists();

        return $engaged ? 0 : AnalysisSuggestion::query()->where('user_id', $user->id)->ofType(SuggestionType::RecurringTransaction)->pending()->count();
    }

    public function render(): View
    {
        return view('livewire.bills');
    }

    private function calendarService(): BillsCalendarService
    {
        return app(BillsCalendarService::class);
    }

    private function user(): User
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}
