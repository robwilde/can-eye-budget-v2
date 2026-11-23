<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\OccurrenceStatus;
use App\Enums\RecurrenceFrequency;
use App\Enums\WeekendAdjustment;
use App\Events\PlannedTransactionCreated;
use App\Events\PlannedTransactionScheduleChanged;
use App\Jobs\GeneratePlannedOccurrencesJob;
use App\Jobs\RunTransactionAnalysisJob;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\PlannedOccurrence;
use App\Models\PlannedTransaction;
use App\Models\User;
use App\Support\Audit\AttributeJobToUser;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    useAppTimezone('Australia/Brisbane');
    $this->travelTo(CarbonImmutable::parse('2026-03-10 12:00:00', 'Australia/Brisbane'));
});

function jobPlan(User $user, array $attributes = []): PlannedTransaction
{
    return PlannedTransaction::factory()->for($user)->for(Account::factory()->for($user))->create([
        'frequency' => RecurrenceFrequency::EveryMonth,
        'start_date' => '2026-01-15',
        'until_date' => null,
        ...$attributes,
    ]);
}

it('generates occurrences only for the jobs user', function () {
    Bus::fake([GeneratePlannedOccurrencesJob::class]);
    $mine = User::factory()->create();
    $theirs = User::factory()->create();
    $own = jobPlan($mine);
    $other = jobPlan($theirs);

    app()->call(new GeneratePlannedOccurrencesJob($mine)->handle(...));

    expect($own->occurrences()->count())->toBe(3)
        ->and($other->occurrences()->count())->toBe(0);
});

it('removes stale open occurrences first when asked to rebuild', function () {
    Bus::fake([GeneratePlannedOccurrencesJob::class]);
    $user = User::factory()->create();
    $plan = jobPlan($user);
    PlannedOccurrence::factory()->for($plan, 'plannedTransaction')->create(['original_date' => '2025-12-15', 'due_date' => '2025-12-15']);

    app()->call(new GeneratePlannedOccurrencesJob($user)->handle(...));
    expect($plan->occurrences()->count())->toBe(4);

    app()->call(new GeneratePlannedOccurrencesJob($user, rebuildHistory: true)->handle(...));

    expect($plan->occurrences()->count())->toBe(3)
        ->and($plan->occurrences()->whereDate('original_date', '2025-12-15')->exists())->toBeFalse();
});

it('never marks an occurrence missed automatically', function () {
    $user = User::factory()->create();
    $plan = jobPlan($user);
    $stale = PlannedOccurrence::factory()->for($plan, 'plannedTransaction')->create(['original_date' => '2026-03-02', 'due_date' => '2026-03-02']);

    foreach (range(0, 89) as $day) {
        $this->travelTo(CarbonImmutable::parse('2026-03-10 12:00:00', 'Australia/Brisbane')->addDays($day));
        Artisan::call('app:generate-planned-occurrences');
    }

    expect(PlannedOccurrence::query()->where('status', OccurrenceStatus::Missed->value)->count())->toBe(0)
        ->and(PlannedOccurrence::query()->whereKey($stale->id)->value('status'))->toBe(OccurrenceStatus::Scheduled)
        ->and($plan->occurrences()->count())->toBe(7)
        ->and($plan->occurrences()->whereDate('original_date', '2026-08-15')->exists())->toBeTrue();
});

it('keeps one occurrence per month however often the nightly run repeats', function () {
    $user = User::factory()->create();
    $plan = jobPlan($user);

    foreach (range(0, 4) as $run) {
        Artisan::call('app:generate-planned-occurrences');
    }

    expect($plan->occurrences()->count())->toBe(3)
        ->and($plan->occurrences()->distinct()->count('original_date'))->toBe(3);
});

it('is attributed to the user without an audit event', function () {
    $user = User::factory()->create();
    $job = new GeneratePlannedOccurrencesJob($user);

    $attribution = collect($job->middleware())->first(fn (object $middleware): bool => $middleware instanceof AttributeJobToUser);
    $attribution->handle($job, fn () => null);

    expect($attribution)->toBeInstanceOf(AttributeJobToUser::class)
        ->and(AuditEvent::query()->where('action', 'job.GeneratePlannedOccurrencesJob')->exists())->toBeFalse();
});

it('does not run twice at once for the same user', function () {
    $job = new GeneratePlannedOccurrencesJob(User::factory()->create());

    expect($job->uniqueFor)->toBe(600)
        ->and(collect($job->middleware())->contains(fn (object $middleware): bool => $middleware instanceof WithoutOverlapping))->toBeTrue()
        ->and($job->uniqueId())->toBe("planned-occurrences-{$job->user->id}-fill")
        ->and(new GeneratePlannedOccurrencesJob($job->user, true)->uniqueId())->toBe("planned-occurrences-{$job->user->id}-rebuild");
});

it('queues one job per user with an active plan from the nightly command', function () {
    $withPlan = User::factory()->create();
    $withInactive = User::factory()->create();
    User::factory()->create();
    jobPlan($withPlan);
    jobPlan($withInactive, ['is_active' => false]);
    Bus::fake([GeneratePlannedOccurrencesJob::class]);

    $this->artisan('app:generate-planned-occurrences')->expectsOutputToContain('Queued occurrence generation for 1 user(s).')->assertSuccessful();

    Bus::assertDispatchedTimes(GeneratePlannedOccurrencesJob::class, 1);
    Bus::assertDispatched(GeneratePlannedOccurrencesJob::class, fn (GeneratePlannedOccurrencesJob $job): bool => $job->user->is($withPlan) && $job->rebuildHistory === false);
});

it('schedules the nightly command without overlapping and on one server', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($event): bool => str_contains((string) $event->command, 'app:generate-planned-occurrences'));

    expect($event)->not->toBeNull()
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->onOneServer)->toBeTrue()
        ->and($event->expression)->toBe('0 3 * * *');
});

it('queues generation after the transaction that creates a plan commits', function () {
    Bus::fake([GeneratePlannedOccurrencesJob::class]);
    $user = User::factory()->create();

    $plan = jobPlan($user);

    Bus::assertDispatched(GeneratePlannedOccurrencesJob::class, fn (GeneratePlannedOccurrencesJob $job): bool => $job->user->is($user) && $job->rebuildHistory === false);
    expect($plan->exists)->toBeTrue();
});

it('queues a rebuild when a plan schedule is edited', function (string $column, mixed $value) {
    $user = User::factory()->create();
    $plan = jobPlan($user);
    Bus::fake([GeneratePlannedOccurrencesJob::class]);

    $plan->update([$column => $value]);

    Bus::assertDispatched(GeneratePlannedOccurrencesJob::class, fn (GeneratePlannedOccurrencesJob $job): bool => $job->user->is($user) && $job->rebuildHistory === true);
})->with([
    'start' => ['start_date', '2026-02-01'],
    'cadence' => ['frequency', RecurrenceFrequency::EveryWeek],
    'end date' => ['until_date', '2026-12-01'],
    'end count' => ['end_after_count', 6],
    'active' => ['is_active', false],
    'interval' => ['recurrence_interval', 2],
    'days' => ['days_of_month', [1, 15]],
    'weekend' => ['weekend_adjustment', WeekendAdjustment::After],
]);

it('does not queue anything for edits that leave the schedule alone', function () {
    $plan = jobPlan(User::factory()->create());
    Bus::fake([GeneratePlannedOccurrencesJob::class]);

    $plan->update(['description' => 'Renamed', 'amount' => 999, 'notes' => 'x']);

    Bus::assertNotDispatched(GeneratePlannedOccurrencesJob::class);
});

it('fires the schedule change event only when the schedule changes', function () {
    $plan = jobPlan(User::factory()->create());
    Event::fake([PlannedTransactionScheduleChanged::class, PlannedTransactionCreated::class]);

    $plan->update(['description' => 'Renamed']);
    Event::assertNotDispatched(PlannedTransactionScheduleChanged::class);

    $plan->update(['frequency' => RecurrenceFrequency::EveryWeek]);
    Event::assertDispatched(PlannedTransactionScheduleChanged::class);
});

it('queues generation after a transaction analysis completes', function () {
    $user = User::factory()->create();
    Bus::fake([GeneratePlannedOccurrencesJob::class]);

    new RunTransactionAnalysisJob($user)->handle(app(App\Services\TransactionAnalysisPipeline::class));

    Bus::assertDispatched(GeneratePlannedOccurrencesJob::class, fn (GeneratePlannedOccurrencesJob $job): bool => $job->user->is($user) && $job->rebuildHistory === false);
});

it('fills occurrences when a plan is created through the queue', function () {
    $user = User::factory()->create();

    $plan = jobPlan($user);

    expect($plan->occurrences()->count())->toBe(3);
});

it('waits for the transaction that creates a plan to commit before generating', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $during = null;

    $plan = DB::transaction(function () use ($user, $account, &$during): PlannedTransaction {
        $plan = PlannedTransaction::factory()->for($user)->for($account)->create(['frequency' => RecurrenceFrequency::EveryMonth, 'start_date' => '2026-01-15']);
        $during = $plan->occurrences()->count();

        return $plan;
    });

    expect($during)->toBe(0)
        ->and($plan->occurrences()->count())->toBe(3);
});

it('takes the duplicate check for a created plan only after the transaction commits', function () {
    Bus::fake([GeneratePlannedOccurrencesJob::class]);
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $during = null;

    DB::transaction(function () use ($user, $account, &$during): void {
        PlannedTransaction::factory()->for($user)->for($account)->create();
        $during = Bus::dispatched(GeneratePlannedOccurrencesJob::class)->count();
    });

    expect($during)->toBe(0);
    Bus::assertDispatchedTimes(GeneratePlannedOccurrencesJob::class, 1);
});

it('queues nothing for a plan whose transaction rolls back', function () {
    Bus::fake([GeneratePlannedOccurrencesJob::class]);
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    try {
        DB::transaction(function () use ($user, $account): void {
            PlannedTransaction::factory()->for($user)->for($account)->create();

            throw new RuntimeException('rolled back');
        });
    } catch (RuntimeException) {
    }

    Bus::assertNotDispatched(GeneratePlannedOccurrencesJob::class);
});

it('waits longer for a stale overlap lock than the lock lasts', function () {
    $job = new GeneratePlannedOccurrencesJob(User::factory()->create());

    expect($job->tries * 10)->toBeGreaterThan(120);
});
