<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\AuditOutcome;
use App\Enums\OccurrenceStatus;
use App\Enums\RecurrenceFrequency;
use App\Enums\TransactionDirection;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\PlannedOccurrence;
use App\Models\PlannedTransaction;
use App\Models\User;
use App\Services\Bills\CalendarFeedBuilder;
use App\Services\Bills\CalendarFeedTokens;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(function () {
    useAppTimezone('UTC');
    config(['budget.calendar_feed' => true]);
    $this->travelTo(CarbonImmutable::parse('2026-03-11 12:00:00', 'UTC'));
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create();
    $this->token = app(CalendarFeedTokens::class)->issue($this->user);
});

function feedOccurrence(object $test, string $name, string $date, array $occurrence = [], array $plan = [], ?User $user = null): PlannedOccurrence
{
    $user ??= $test->user;
    $account = $user->is($test->user) ? $test->account : Account::factory()->for($user)->create();
    $planned = PlannedTransaction::factory()->for($user)->for($account)->create([
        'description' => $name, 'amount' => 12345, 'direction' => TransactionDirection::Debit, 'frequency' => RecurrenceFrequency::EveryMonth,
        'start_date' => '2030-01-01', 'until_date' => null, 'merchant_key' => null, ...$plan,
    ]);

    return PlannedOccurrence::factory()->for($planned, 'plannedTransaction')->create(['original_date' => $date, 'due_date' => $date, ...$occurrence]);
}

function feedGet(object $test, ?string $token = null)
{
    return $test->get('/bills/feed/'.($token ?? $test->token).'.ics');
}

it('publishes open occurrences at their snoozed date', function () {
    feedOccurrence($this, 'Gym', '2026-03-12', ['snoozed_until' => '2026-03-13']);

    $response = feedGet($this)->assertOk();
    $body = $response->getContent();

    expect($response->headers->get('Content-Type'))->toContain('text/calendar')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and(mb_substr_count($body, 'BEGIN:VEVENT'))->toBe(1)
        ->and($body)->toContain('DTSTART;VALUE=DATE:20260313')->toContain('SUMMARY:Gym $123.45')
        ->and($body)->not->toContain('20260312')->not->toContain($this->account->name)->not->toContain('DESCRIPTION');
});

it('revokes the old feed url when the token is reset', function () {
    $old = $this->token;
    $new = app(CalendarFeedTokens::class)->issue($this->user);

    feedGet($this, $old)->assertNotFound();
    feedGet($this, $new)->assertOk();
});

it('returns not found when recurring is off', function () {
    $this->user->updatePreferences(['recurringEnabled' => false]);

    feedGet($this)->assertNotFound();
});

it('returns not found when the feed flag is off', function () {
    config(['budget.calendar_feed' => false]);

    feedGet($this)->assertNotFound();
});

it('returns not found for an unknown token', function (string $token) {
    feedGet($this, $token)->assertNotFound();
})->with(['random' => [str_repeat('a', 40)], 'short' => ['abc'], 'long' => [str_repeat('b', 41)], 'symbols' => ['aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa!']]);

it('never publishes another users bills in the feed', function () {
    $other = User::factory()->create();
    feedOccurrence($this, 'Mine', '2026-03-14');
    feedOccurrence($this, 'Theirs', '2026-03-14', [], [], $other);

    feedGet($this)->assertOk()->assertSee('Mine')->assertDontSee('Theirs');
});

it('leaves paid occurrences out of the feed', function () {
    feedOccurrence($this, 'Paid bill', '2026-03-14', ['status' => OccurrenceStatus::Paid, 'closed_at' => now()]);
    feedOccurrence($this, 'Skipped bill', '2026-03-15', ['status' => OccurrenceStatus::Skipped, 'closed_at' => now()]);
    feedOccurrence($this, 'Open bill', '2026-03-16');

    feedGet($this)->assertOk()->assertSee('Open bill')->assertDontSee('Paid bill')->assertDontSee('Skipped bill');
});

it('limits the feed to debit plans in the next ninety days', function () {
    feedOccurrence($this, 'Salary', '2026-03-14', [], ['direction' => TransactionDirection::Credit]);
    feedOccurrence($this, 'Paused', '2026-03-14', [], ['status' => App\Enums\PlannedTransactionStatus::Inactive]);
    feedOccurrence($this, 'Past', '2026-03-01');
    feedOccurrence($this, 'Too far', '2026-07-01');
    feedOccurrence($this, 'Edge', '2026-06-09');

    feedGet($this)->assertOk()->assertSee('Edge')->assertDontSee('Salary')->assertDontSee('Paused')->assertDontSee('Past')->assertDontSee('Too far');
});

it('stores only a hash of the token', function () {
    $stored = $this->user->fresh()->calendar_feed_token_hash;

    expect($stored)->toBe(hash('sha256', $this->token))->and($stored)->not->toContain($this->token)->and($this->user->fresh()->toArray())->not->toHaveKey('calendar_feed_token_hash');
});

it('escapes and folds event text', function () {
    feedOccurrence($this, 'Rent; "flat", 12\\n '.str_repeat('é', 60), '2026-03-14');

    $body = app(CalendarFeedBuilder::class)->build($this->user);

    foreach (explode("\r\n", $body) as $line) {
        expect(mb_strlen($line, '8bit'))->toBeLessThanOrEqual(75);
    }

    expect(str_replace("\r\n ", '', $body))->toContain('SUMMARY:Rent\\; "flat"\\, 12\\\\n ')->and($body)->toEndWith("END:VCALENDAR\r\n");
});

it('rate limits the feed', function () {
    foreach (range(1, 30) as $ignored) {
        feedGet($this, str_repeat('z', 40))->assertNotFound();
    }

    feedGet($this, str_repeat('z', 40))->assertStatus(429);
});

it('audits resetting the calendar feed token', function () {
    $old = $this->token;

    $component = Livewire::actingAs($this->user)->test('pages::settings.recurring')->call('resetCalendarFeed');
    $url = $component->get('feedUrl');

    $event = AuditEvent::query()->where('action', 'livewire.pages::settings.recurring.resetCalendarFeed')->sole();

    expect($event->user_id)->toBe($this->user->id)->and($event->outcome)->toBe(AuditOutcome::Success)
        ->and(AuditEvent::query()->get()->toJson())->not->toContain(basename($url, '.ics'))
        ->and($url)->toContain('/bills/feed/');

    feedGet($this, $old)->assertNotFound();
    $this->get($url)->assertOk();
});

it('refuses the reset while the feed flag or recurring is off', function () {
    config(['budget.calendar_feed' => false]);

    Livewire::actingAs($this->user)->test('pages::settings.recurring')->call('resetCalendarFeed')->assertNotFound();
});

it('shows the link once and then only that one exists', function () {
    $page = Livewire::actingAs($this->user)->test('pages::settings.recurring')->assertSeeHtml('data-testid="calendar-feed-active"')->assertDontSeeHtml('data-testid="calendar-feed-url"');

    $page->call('resetCalendarFeed')->assertSeeHtml('data-testid="calendar-feed-url"');
});

it('hides the feed settings while the flag is off', function () {
    config(['budget.calendar_feed' => false]);

    Livewire::actingAs($this->user)->test('pages::settings.recurring')->assertDontSeeHtml('data-testid="calendar-feed"');
});

it('redacts the feed token from a Sentry event', function () {
    $event = Sentry\Event::createEvent();
    $event->setRequest(['url' => 'https://example.test/bills/feed/'.$this->token.'.ics', 'query_string' => 'a=1', 'method' => 'GET']);

    $redacted = App\Support\Sentry\FeedTokenRedactor::redact($event)->getRequest();

    expect($redacted['url'])->toBe('https://example.test/bills/feed/[redacted].ics')->and($redacted['method'])->toBe('GET');
});

it('ignores the feed route in Sentry tracing', function () {
    expect(config('sentry.ignore_transactions'))->toContain('/bills/feed/{token}.ics');
});
