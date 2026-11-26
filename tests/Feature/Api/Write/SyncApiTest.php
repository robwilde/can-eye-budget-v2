<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Jobs\SyncRedbarkFeedJob;
use App\Models\RedbarkFeed;
use App\Models\RedbarkSyncLog;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CallsApi;

uses(CallsApi::class);

beforeEach(function (): void {
    Queue::fake();
});

it('queues a sync only for the token owner\'s feed', function () {
    $mine = User::factory()->create();
    $theirs = User::factory()->create();
    $myFeed = RedbarkFeed::factory()->for($mine)->create();
    $theirFeed = RedbarkFeed::factory()->for($theirs)->create();

    $this->apiCall('POST', 'feeds/sync', $this->apiTokenFor($mine))->assertStatus(202)->assertJsonPath('data.queued', 1);

    expect(RedbarkSyncLog::query()->where('redbark_feed_id', $myFeed->id)->count())->toBe(1)
        ->and(RedbarkSyncLog::query()->where('redbark_feed_id', $theirFeed->id)->count())->toBe(0);

    Queue::assertPushed(SyncRedbarkFeedJob::class, 1);
    Queue::assertPushed(SyncRedbarkFeedJob::class, fn (SyncRedbarkFeedJob $job): bool => $job->feed->is($myFeed));
});

it('queues nothing for a user without a feed', function () {
    $user = User::factory()->create();
    RedbarkFeed::factory()->create();

    $this->apiCall('POST', 'feeds/sync', $this->apiTokenFor($user))->assertStatus(202)->assertJsonPath('data.queued', 0);

    expect(RedbarkSyncLog::query()->count())->toBe(0);

    Queue::assertNothingPushed();
});
