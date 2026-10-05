<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Models\User;
use App\Support\Audit\AttributeJobToUser;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Sentry\ClientBuilder;
use Sentry\Event;
use Sentry\Laravel\Features\QueueIntegration;
use Sentry\SentrySdk;
use Sentry\State\Scope;

final class AuditWorkerFailingJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $userId) {}

    /** @return list<object> */
    public function middleware(): array
    {
        return [new AttributeJobToUser($this->userId, audit: false)];
    }

    public function handle(): void
    {
        throw new RuntimeException('worker boom');
    }
}

final class AuditWorkerProbeJob implements ShouldQueue
{
    use Queueable;

    public static ?string $seenUser = 'unset';

    public function handle(): void
    {
        self::$seenUser = auditSentryEvent()->getUser()?->getId();
    }
}

/** @return list<Event> */
function runFailingThenProbeJobOnWorker(int $userId): array
{
    $captured = [];

    SentrySdk::getCurrentHub()->bindClient(ClientBuilder::create([
        'dsn' => 'https://abc123@o0.ingest.us.sentry.io/999',
        'before_send' => function (Event $event) use (&$captured): ?Event {
            $captured[] = $event;

            return null;
        },
    ])->getClient());
    SentrySdk::getCurrentHub()->configureScope(fn (Scope $scope) => $scope->clear());

    new QueueIntegration(app())->boot();

    config(['queue.default' => 'database']);
    AuditWorkerProbeJob::$seenUser = 'unset';

    dispatch(new AuditWorkerFailingJob($userId));
    dispatch(new AuditWorkerProbeJob);

    test()->artisan('queue:work', ['--once' => true, '--tries' => 1, '--stop-when-empty' => true]);
    test()->artisan('queue:work', ['--once' => true, '--tries' => 1, '--stop-when-empty' => true]);

    return $captured;
}

test('a job that fails on the queue worker is reported to Sentry with its user and the next job runs without one', function () {
    $user = User::factory()->create();

    $events = runFailingThenProbeJobOnWorker($user->id);

    $failure = collect($events)->first(fn (Event $event) => collect($event->getExceptions())->contains(
        fn ($exception) => $exception->getValue() === 'worker boom',
    ));

    expect($failure)->not->toBeNull()
        ->and($failure->getUser()?->getId())->toBe((string) $user->id)
        ->and(AuditWorkerProbeJob::$seenUser)->toBeNull();
});
