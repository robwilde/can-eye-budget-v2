<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\OccurrenceStatus;
use App\Enums\TransactionDirection;
use App\Jobs\GeneratePlannedOccurrencesJob;
use App\Livewire\OccurrencePaymentPanel;
use App\Livewire\PlannedTransactionManager;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\PlannedMatchRejection;
use App\Models\PlannedOccurrence;
use App\Models\PlannedPayment;
use App\Models\PlannedTransaction;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;

beforeEach(function () {
    Bus::fake([GeneratePlannedOccurrencesJob::class]);
    useAppTimezone('UTC');
    $this->travelTo(CarbonImmutable::parse('2026-03-20 12:00:00', 'UTC'));
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create();
    $this->user->update(['primary_account_id' => $this->account->id]);
    $this->plan = PlannedTransaction::factory()->for($this->user)->for($this->account)->create([
        'description' => 'Acme Power', 'amount' => 10000, 'direction' => TransactionDirection::Debit, 'merchant_key' => null,
        'frequency' => App\Enums\RecurrenceFrequency::EveryMonth, 'start_date' => '2026-01-15', 'until_date' => null,
    ]);
    $this->occurrence = PlannedOccurrence::factory()->for($this->plan, 'plannedTransaction')->create(['original_date' => '2026-03-15', 'due_date' => '2026-03-15']);
});

function panelTx(object $test, string $description, int $cents, string $date, array $attributes = []): Transaction
{
    return Transaction::factory()->for($test->user)->for($test->account)->create([
        'description' => $description, 'amount' => -$cents, 'direction' => TransactionDirection::Debit, 'post_date' => $date, 'planned_transaction_id' => null, ...$attributes,
    ]);
}

function panelOpen(object $test, ?PlannedOccurrence $occurrence = null)
{
    return Livewire::actingAs($test->user)->test(OccurrencePaymentPanel::class)->call('open', ($occurrence ?? $test->occurrence)->id);
}

function panelIds(array $candidates, string $section): array
{
    return $candidates[$section]->map(fn ($candidate): int => $candidate->transaction->id)->all();
}

it('lists only the users own transactions as payment candidates', function () {
    $mine = panelTx($this, 'ACME POWER', 10000, '2026-03-15');
    $other = User::factory()->create();
    $theirs = Transaction::factory()->for($other)->for($this->account)->create(['description' => 'ACME POWER', 'amount' => -10000, 'direction' => TransactionDirection::Debit, 'post_date' => '2026-03-15']);
    $elsewhere = Transaction::factory()->for($other)->for(Account::factory()->for($other))->create(['description' => 'ACME POWER', 'amount' => -10000, 'direction' => TransactionDirection::Debit, 'post_date' => '2026-03-15']);

    panelOpen($this)->assertViewHas('candidates', function (array $candidates) use ($mine, $theirs, $elsewhere): bool {
        $ids = [...panelIds($candidates, 'ranked'), ...panelIds($candidates, 'fallback')];

        return in_array($mine->id, $ids, true) && ! in_array($theirs->id, $ids, true) && ! in_array($elsewhere->id, $ids, true);
    });
});

it('leaves rejected transactions out of the candidates', function () {
    $rejected = panelTx($this, 'ACME POWER', 10000, '2026-03-15');
    $kept = panelTx($this, 'ACME POWER', 10000, '2026-03-14');
    PlannedMatchRejection::query()->create(['user_id' => $this->user->id, 'planned_transaction_id' => $this->plan->id, 'transaction_id' => $rejected->id]);

    panelOpen($this)->assertViewHas('candidates', fn (array $candidates): bool => panelIds($candidates, 'ranked') === [$kept->id] && panelIds($candidates, 'fallback') === []);
});

it('caps ranked candidates at six and the fallback at fifteen', function () {
    foreach (range(1, 10) as $i) {
        panelTx($this, 'ACME POWER', 10000, '2026-03-15');
    }

    foreach (range(1, 30) as $i) {
        panelTx($this, "OTHER SHOP {$i}", 9000 + $i * 10, '2026-03-14');
    }

    panelOpen($this)->assertViewHas('candidates', fn (array $candidates): bool => count($candidates['ranked']) === 6 && count($candidates['fallback']) === 15);
});

it('ranks by score with the signals and the best first', function () {
    $exact = panelTx($this, 'ACME POWER', 10000, '2026-03-15');
    $near = panelTx($this, 'ACME POWER BILL', 10400, '2026-03-18');

    panelOpen($this)
        ->assertViewHas('candidates', fn (array $candidates): bool => panelIds($candidates, 'ranked') === [$exact->id, $near->id])
        ->assertSeeHtmlInOrder(['data-testid="candidate-'.$exact->id.'"', 'data-testid="candidate-signals"', 'Name', 'Amount', 'Date', 'Account', 'data-testid="candidate-'.$near->id.'"']);
});

it('finds transactions outside the date window by search', function () {
    $old = panelTx($this, 'ACME POWER ANCIENT', 10000, '2025-12-15');

    panelOpen($this)
        ->assertViewHas('candidates', fn (array $candidates): bool => ! in_array($old->id, [...panelIds($candidates, 'ranked'), ...panelIds($candidates, 'fallback')], true))
        ->set('search', 'ancient')
        ->assertViewHas('candidates', fn (array $candidates): bool => in_array($old->id, [...panelIds($candidates, 'ranked'), ...panelIds($candidates, 'fallback')], true));
});

it('leaves transfers out of candidates for a non-transfer plan', function () {
    $savings = Account::factory()->for($this->user)->create();
    $leg = panelTx($this, 'ACME POWER', 10000, '2026-03-15');
    $mirror = Transaction::factory()->for($this->user)->for($savings)->create(['amount' => 10000, 'direction' => TransactionDirection::Credit, 'description' => 'ACME POWER', 'post_date' => '2026-03-15', 'transfer_pair_id' => $leg->id]);
    $leg->update(['transfer_pair_id' => $mirror->id]);
    $plain = panelTx($this, 'ACME POWER', 10000, '2026-03-14');

    panelOpen($this)->assertViewHas('candidates', fn (array $candidates): bool => panelIds($candidates, 'ranked') === [$plain->id]);
});

it('offers the outflow leg of a transfer plan only', function () {
    $savings = Account::factory()->for($this->user)->create();
    $this->plan->update(['transfer_to_account_id' => $savings->id]);
    $leg = panelTx($this, 'ACME POWER', 10000, '2026-03-15');
    $mirror = Transaction::factory()->for($this->user)->for($savings)->create(['amount' => 10000, 'direction' => TransactionDirection::Credit, 'description' => 'ACME POWER', 'post_date' => '2026-03-15', 'transfer_pair_id' => $leg->id]);
    $leg->update(['transfer_pair_id' => $mirror->id]);
    $plain = panelTx($this, 'ACME POWER', 10000, '2026-03-14');

    panelOpen($this)->assertViewHas('candidates', fn (array $candidates): bool => panelIds($candidates, 'ranked') === [$leg->id] && ! in_array($plain->id, panelIds($candidates, 'fallback'), true));
});

it('audits attaching a payment', function () {
    $tx = panelTx($this, 'ACME POWER', 10000, '2026-03-15');

    panelOpen($this)->call('attach', $tx->id)->assertHasNoErrors();

    $event = AuditEvent::query()->where('action', 'livewire.occurrence-payment-panel.attach')->sole();

    expect($event->user_id)->toBe($this->user->id)
        ->and(PlannedPayment::query()->sole()->amount)->toBe(10000)
        ->and($this->occurrence->fresh()->status)->toBe(OccurrenceStatus::Paid);
});

it('attaches a custom amount and leaves the rest of the transaction to be applied again', function () {
    $tx = panelTx($this, 'ACME POWER', 10000, '2026-03-15');

    panelOpen($this)->set("customAmounts.{$tx->id}", '40.00')->call('attach', $tx->id)->assertHasNoErrors()
        ->assertSee('$60.00 left');

    expect(PlannedPayment::query()->sole()->amount)->toBe(4000)->and($this->occurrence->fresh()->status)->toBe(OccurrenceStatus::Scheduled);
});

it('refuses an amount above what the transaction has left and shows the reason', function () {
    $tx = panelTx($this, 'ACME POWER', 10000, '2026-03-15');

    panelOpen($this)->set("customAmounts.{$tx->id}", '150')->call('attach', $tx->id)->assertHasErrors('payment')->assertSeeHtml('data-testid="payment-error"');

    expect(PlannedPayment::query()->count())->toBe(0);
});

it('refuses a custom amount that is not a positive figure', function (string $amount) {
    $tx = panelTx($this, 'ACME POWER', 10000, '2026-03-15');

    panelOpen($this)->set("customAmounts.{$tx->id}", $amount)->call('attach', $tx->id)->assertHasErrors('payment');

    expect(PlannedPayment::query()->count())->toBe(0);
})->with(['zero' => '0', 'text' => 'abc', 'three decimals' => '1.234', 'negative' => '-5']);

it('records a payment without a transaction and marks the occurrence paid', function () {
    panelOpen($this)->set('recordAmount', '40.00')->call('recordPayment')->assertHasNoErrors()->assertSee('Recorded by hand');

    expect(PlannedPayment::query()->sole()->amount)->toBe(4000);

    panelOpen($this)->call('markPaid')->assertHasNoErrors();

    expect($this->occurrence->fresh()->status)->toBe(OccurrenceStatus::Paid)->and(PlannedPayment::query()->sum('amount'))->toBe(10000);
});

it('removes a payment and reopens the occurrence', function () {
    $tx = panelTx($this, 'ACME POWER', 10000, '2026-03-15');
    panelOpen($this)->call('attach', $tx->id);
    $payment = PlannedPayment::query()->sole();

    panelOpen($this)->call('removePayment', $payment->id)->assertHasNoErrors();

    expect(PlannedPayment::query()->count())->toBe(0)->and($this->occurrence->fresh()->status)->toBe(OccurrenceStatus::Scheduled);
});

it('shows a payment whose transaction was removed with its amount', function () {
    $tx = panelTx($this, 'ACME POWER', 10000, '2026-03-15');
    PlannedPayment::factory()->create(['planned_occurrence_id' => $this->occurrence->id, 'transaction_id' => $tx->id, 'amount' => 4000]);
    $tx->delete();
    PlannedPayment::query()->updateOrInsert(['planned_occurrence_id' => $this->occurrence->id, 'transaction_id' => $tx->id], ['amount' => 4000, 'paid_on' => '2026-03-15', 'created_at' => now(), 'updated_at' => now()]);

    panelOpen($this)->assertSeeHtmlInOrder(['data-testid="payment-source"', 'transaction removed'])->assertSeeHtmlInOrder(['data-testid="payment-', '$40.00', 'transaction removed']);
});

it('shows the learned aliases and the current tolerance', function () {
    $this->plan->update(['learned_aliases' => ['descriptions' => ['acme power pty'], 'merchant_keys' => []], 'amount_tolerance_bps' => 500]);

    panelOpen($this)->assertSee('within 5% of the amount')->assertSee('acme power pty');
});

it('refuses to open or change another users occurrence', function () {
    $other = User::factory()->create();

    Livewire::actingAs($other)->test(OccurrencePaymentPanel::class)->call('open', $this->occurrence->id)->assertNotFound();

    $tx = panelTx($this, 'ACME POWER', 10000, '2026-03-15');
    $component = Livewire::actingAs($other)->test(OccurrencePaymentPanel::class)->set('occurrenceId', $this->occurrence->id)->call('attach', $tx->id)->assertNotFound();

    expect(PlannedPayment::query()->count())->toBe(0);
});

it('offers no candidates for a closed occurrence', function () {
    $this->occurrence->update(['status' => OccurrenceStatus::Skipped, 'closed_at' => now(), 'closed_manually' => true]);
    panelTx($this, 'ACME POWER', 10000, '2026-03-15');

    panelOpen($this)->assertViewHas('candidates', fn ($candidates): bool => $candidates === null)->assertDontSeeHtml('data-testid="panel-candidates"');
});

it('opens from the occurrence list of the plan manager', function () {
    Livewire::actingAs($this->user)->test(PlannedTransactionManager::class)->set('occurrencesPlanId', $this->plan->id)
        ->assertSeeHtml('data-testid="occurrence-payments"')
        ->assertSeeHtml('open-occurrence-payment-panel');
});

it('offers the lines of a split and not its parent', function () {
    $parent = panelTx($this, 'ACME POWER', 10000, '2026-03-15');
    $line = panelTx($this, 'ACME POWER', 6000, '2026-03-15', ['split_parent_id' => $parent->id]);

    panelOpen($this)->assertViewHas('candidates', fn (array $candidates): bool => ! in_array($parent->id, [...panelIds($candidates, 'ranked'), ...panelIds($candidates, 'fallback')], true)
        && in_array($line->id, [...panelIds($candidates, 'ranked'), ...panelIds($candidates, 'fallback')], true));
});

it('leaves a fully applied transaction out and keeps room in the pool for the rest', function () {
    $spent = panelTx($this, 'ACME POWER', 10000, '2026-03-15');
    PlannedPayment::factory()->create(['planned_occurrence_id' => PlannedOccurrence::factory()->for($this->plan, 'plannedTransaction')->create(['original_date' => '2026-02-15', 'due_date' => '2026-02-15'])->id, 'transaction_id' => $spent->id, 'amount' => 10000]);
    $fresh = panelTx($this, 'ACME POWER', 10000, '2026-03-14');

    panelOpen($this)->assertViewHas('candidates', fn (array $candidates): bool => panelIds($candidates, 'ranked') === [$fresh->id] && panelIds($candidates, 'fallback') === []);
});

it('still ranks a match near the due date among hundreds of newer rows', function () {
    $this->travelTo(CarbonImmutable::parse('2026-04-20 12:00:00', 'UTC'));
    $match = panelTx($this, 'ACME POWER', 10000, '2026-03-15');
    $rows = collect(range(1, 320))->map(fn (int $i): array => ['user_id' => $this->user->id, 'account_id' => $this->account->id, 'description' => "FILLER {$i}", 'amount' => -(500 + $i), 'direction' => 'debit', 'post_date' => '2026-04-10', 'status' => 'posted', 'currency' => 'AUD', 'created_at' => now(), 'updated_at' => now()]);
    $rows->chunk(100)->each(fn ($chunk) => Transaction::query()->insert($chunk->all()));

    panelOpen($this)->assertViewHas('candidates', fn (array $candidates): bool => panelIds($candidates, 'ranked') === [$match->id]);
});

it('keeps what was typed when the recorder refuses it', function () {
    $tx = panelTx($this, 'ACME POWER', 10000, '2026-03-15');

    panelOpen($this)->set("customAmounts.{$tx->id}", '150')->call('attach', $tx->id)->assertSet("customAmounts.{$tx->id}", '150')
        ->set('recordAmount', '20')->set('recordDate', '2026-12-31')->call('recordPayment')->assertHasErrors('payment')->assertSet('recordAmount', '20')->assertSet('recordDate', '2026-12-31');
});

it('refreshes the occurrence rows of the plan manager after a payment', function () {
    $manager = Livewire::actingAs($this->user)->test(PlannedTransactionManager::class)->set('occurrencesPlanId', $this->plan->id);
    $tx = panelTx($this, 'ACME POWER', 10000, '2026-03-15');

    $manager->assertSeeHtml('data-testid="occurrence-skip"');

    app(App\Services\Recurring\OccurrencePaymentRecorder::class)->attach($this->user, $tx->id, $this->occurrence->id);

    $manager->dispatch('occurrence-payment-changed')->assertDontSeeHtml('data-testid="occurrence-skip"');
});

it('tells the host to refresh when the panel closes after a change and not before', function () {
    $tx = panelTx($this, 'ACME POWER', 10000, '2026-03-15');

    panelOpen($this)->call('attach', $tx->id)->assertNotDispatched('occurrence-payment-changed')->call('close')->assertDispatched('occurrence-payment-changed');
});

it('does not tell the host to refresh when nothing changed', function () {
    panelOpen($this)->set('showPanel', false)->assertNotDispatched('occurrence-payment-changed');
});

it('tells the host to refresh when the panel is dismissed after a change', function () {
    panelOpen($this)->set('recordAmount', '10')->call('recordPayment')->assertSet('changed', true)->set('showPanel', false)->assertDispatched('occurrence-payment-changed');
});
