<?php

declare(strict_types=1);

use App\Jobs\GenerateInsightsJob;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

it('dispatches one generation job per user while insights are enabled', function () {
    Queue::fake();
    config(['budget.insights_enabled' => true]);
    $users = User::factory()->count(2)->create();

    $this->artisan('insights:generate')->assertSuccessful();

    Queue::assertPushed(GenerateInsightsJob::class, 2);
    Queue::assertPushed(GenerateInsightsJob::class, fn (GenerateInsightsJob $job): bool => $users->contains('id', $job->user->id));
});

it('dispatches nothing while insights are disabled', function () {
    Queue::fake();
    config(['budget.insights_enabled' => false]);
    User::factory()->create();

    $this->artisan('insights:generate')->assertSuccessful();

    Queue::assertNothingPushed();
});
