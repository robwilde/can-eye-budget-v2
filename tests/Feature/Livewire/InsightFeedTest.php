<?php

declare(strict_types=1);

use App\Enums\InsightPriority;
use App\Enums\InsightStatus;
use App\Enums\InsightType;
use App\Jobs\GenerateInsightsJob;
use App\Livewire\Dashboard;
use App\Livewire\InsightBadge;
use App\Livewire\InsightFeed;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\Insight;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    config(['budget.insights_enabled' => true]);
});

it('marks every active insight read when the feed opens', function () {
    $user = User::factory()->create();
    $insights = Insight::factory()->for($user)->count(3)->create();

    $this->actingAs($user)->get(route('insights'))
        ->assertOk()
        ->assertSeeHtml('data-testid="insight-new"')
        ->assertDontSeeHtml('data-testid="insights-badge"');

    expect(Insight::query()->where('user_id', $user->id)->where('status', InsightStatus::Read)->count())->toBe(3);

    Livewire::actingAs($user)->test(InsightBadge::class)->assertDontSeeHtml('data-testid="insights-badge"');

    $other = User::factory()->create();
    Insight::factory()->for($other)->create();

    Livewire::actingAs($user)->test(InsightFeed::class)
        ->assertCount('newIds', 0)
        ->assertSee($insights->first()->title)
        ->assertDontSeeHtml('data-testid="insight-new"');
});

it('shows the new marker only for insights that were unread when the feed opened', function () {
    $user = User::factory()->create();
    $unread = Insight::factory()->for($user)->create();
    Insight::factory()->for($user)->read()->create();

    $html = Livewire::actingAs($user)->test(InsightFeed::class)
        ->assertSet('newIds', [$unread->id])
        ->html();

    expect(mb_substr_count($html, 'data-testid="insight-new"'))->toBe(1);
});

it('caps the unread badge at 9+', function () {
    $user = User::factory()->create();
    Insight::factory()->for($user)->count(12)->create();

    $this->actingAs($user)->get(route('dashboard'))
        ->assertOk()
        ->assertSeeHtml('data-testid="insights-badge"')
        ->assertSeeHtml('<span class="sr-only">Insights</span>')
        ->assertDontSeeHtml('aria-label="Insights"')
        ->assertSee('9+');

    Insight::query()->where('user_id', $user->id)->limit(7)->delete();

    Livewire::actingAs($user)->test(InsightBadge::class)->assertSee('5')->assertDontSee('9+');
});

it('audits acknowledging an insight', function () {
    $user = User::factory()->create();
    $insight = Insight::factory()->for($user)->create(['title' => 'Idle cash in Everyday']);

    Livewire::actingAs($user)->test(InsightFeed::class)
        ->assertSee('Idle cash in Everyday')
        ->call('acknowledge', $insight->id)
        ->assertDontSee('Idle cash in Everyday')
        ->assertDispatched('insight-dismissed')
        ->assertSeeHtml('data-testid="insights-empty"');

    expect($insight->fresh()->status)->toBe(InsightStatus::Acknowledged)
        ->and(AuditEvent::query()->where('action', 'livewire.insight-feed.acknowledge')->sole()->user_id)->toBe($user->id);
});

it('hides the dashboard insights card when no insights remain', function () {
    $user = User::factory()->create();
    $insight = Insight::factory()->for($user)->create();

    Livewire::actingAs($user)->test(Dashboard::class)->assertSeeHtml('data-test="dashboard-insights-card"');

    $insight->update(['status' => InsightStatus::Acknowledged]);

    Livewire::actingAs($user)->test(Dashboard::class)->assertDontSeeHtml('data-test="dashboard-insights-card"');
});

it('shows only the top three insights on the dashboard in priority order', function () {
    $user = User::factory()->create();
    Insight::factory()->for($user)->create(['title' => 'Low one', 'priority' => InsightPriority::Low]);
    Insight::factory()->for($user)->create(['title' => 'Medium one', 'priority' => InsightPriority::Medium]);
    Insight::factory()->for($user)->create(['title' => 'High one', 'priority' => InsightPriority::High]);
    Insight::factory()->for($user)->create(['title' => 'Low two', 'priority' => InsightPriority::Low, 'created_at' => now()->subDay()]);

    Livewire::actingAs($user)->test(Dashboard::class)
        ->assertSeeInOrder(['High one', 'Medium one', 'Low one'])
        ->assertDontSee('Low two');
});

it('leaves cash-flow insights off the dashboard card while the buffer is shown', function () {
    $user = User::factory()->withPayCycle()->create(['next_pay_date' => now()->addDays(7)]);
    Account::factory()->for($user)->create(['balance' => 500000]);
    Insight::factory()->for($user)->create(['title' => 'Balance low', 'type' => InsightType::CashFlowWarning]);
    Insight::factory()->for($user)->create(['title' => 'Idle one', 'type' => InsightType::IdleCash]);

    $component = Livewire::actingAs($user)->test(Dashboard::class);

    expect($component->instance()->buffer())->not->toBeNull();

    $component->assertSee('Idle one')->assertDontSee('Balance low');

    $setupUser = User::factory()->create(['next_pay_date' => null]);
    Insight::factory()->for($setupUser)->create(['title' => 'Balance low', 'type' => InsightType::CashFlowWarning]);

    Livewire::actingAs($setupUser)->test(Dashboard::class)->assertSee('Balance low');
});

it('refuses to acknowledge another user insight', function () {
    $user = User::factory()->create();
    $theirs = Insight::factory()->for(User::factory()->create())->create();

    expect(fn () => Livewire::actingAs($user)->test(InsightFeed::class)->call('acknowledge', $theirs->id))
        ->toThrow(ModelNotFoundException::class);

    expect($theirs->fresh()->status)->toBe(InsightStatus::Active);
});

it('returns not found for insights when the flag is off', function () {
    config(['budget.insights_enabled' => false]);
    $user = User::factory()->create();
    Insight::factory()->for($user)->count(2)->create();

    $this->actingAs($user)->get(route('insights'))->assertNotFound();

    $this->actingAs($user)->get(route('dashboard'))
        ->assertOk()
        ->assertDontSeeHtml('data-testid="insights-badge"')
        ->assertDontSeeHtml('data-testid="topbar-insights"');

    Livewire::actingAs($user)->test(InsightBadge::class)->assertDontSeeHtml('data-testid="insights-badge"');
});

it('keeps the check button pending until that run finishes', function () {
    Queue::fake();
    $user = User::factory()->create();

    $component = Livewire::actingAs($user)->test(InsightFeed::class)->call('checkForNew');
    $runId = $component->get('runId');

    Queue::assertPushed(GenerateInsightsJob::class, fn (GenerateInsightsJob $job): bool => $job->user->is($user) && $job->runId === $runId);

    Cache::put(GenerateInsightsJob::runKey('some-earlier-run'), 'done');

    $component->call('pollRefresh')
        ->assertSeeHtml('wire:poll.2s="pollRefresh"')
        ->assertSet('runId', $runId);

    Insight::factory()->for($user)->create(['title' => 'Fresh insight']);
    Cache::put(GenerateInsightsJob::runKey($runId), 'done');

    $component->call('pollRefresh')
        ->assertSet('runId', null)
        ->assertSet('checkingSince', null)
        ->assertDontSeeHtml('wire:poll.2s="pollRefresh"')
        ->assertSee('Fresh insight')
        ->assertSeeHtml('data-testid="insight-new"');

    expect(Insight::query()->where('user_id', $user->id)->sole()->status)->toBe(InsightStatus::Read);
});

it('finishes a check when its job failed', function () {
    Queue::fake();
    $user = User::factory()->create();

    $component = Livewire::actingAs($user)->test(InsightFeed::class)->call('checkForNew');
    Cache::put(GenerateInsightsJob::runKey($component->get('runId')), 'failed');

    $component->call('pollRefresh')->assertSet('checkingSince', null)->assertSet('timedOut', false);
});

it('offers a retry instead of finishing silently when a check takes too long', function () {
    Queue::fake();
    $user = User::factory()->create();

    $component = Livewire::actingAs($user)->test(InsightFeed::class)->call('checkForNew');

    $this->travel(3)->minutes();

    $component->call('pollRefresh')
        ->assertSet('timedOut', true)
        ->assertSeeHtml('data-testid="insights-timeout"')
        ->call('checkForNew')
        ->assertSet('timedOut', false)
        ->assertDontSeeHtml('data-testid="insights-timeout"');

    Queue::assertPushed(GenerateInsightsJob::class, 2);
});

it('blocks feed updates once the flag is turned off', function () {
    $user = User::factory()->create();
    $insight = Insight::factory()->for($user)->create();

    $component = Livewire::actingAs($user)->test(InsightFeed::class);

    config(['budget.insights_enabled' => false]);

    $component->call('acknowledge', $insight->id)->assertStatus(404);

    expect($insight->fresh()->status)->toBe(InsightStatus::Read);
});
