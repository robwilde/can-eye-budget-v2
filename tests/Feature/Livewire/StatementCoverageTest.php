<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\StatementCoverageStatus;
use App\Livewire\AccountShow;
use App\Livewire\StatementCoverage;
use App\Models\Account;
use App\Models\Statement;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo('2026-04-15 10:00:00');
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create();
});

it('refuses to show coverage for another user\'s account', function () {
    $foreign = Account::factory()->for(User::factory()->create())->create();

    Livewire::actingAs($this->user)->test(StatementCoverage::class, ['account' => $foreign])->assertStatus(404);
});

it('shows the months of the selected year with their status', function () {
    Statement::factory()->for($this->user)->create(['account_id' => $this->account->id, 'period_start' => '2026-03-01', 'period_end' => '2026-03-31']);

    $component = Livewire::actingAs($this->user)->test(StatementCoverage::class, ['account' => $this->account]);

    $component->assertSeeHtml('data-test="coverage-2026-03" data-status="covered"')
        ->assertSeeHtml('data-test="coverage-2026-04" data-status="not_expected"');

    expect($component->instance()->months)->toHaveCount(12)
        ->and($component->instance()->months[2]->status)->toBe(StatementCoverageStatus::Covered);
});

it('lists the account\'s linked statements only', function () {
    Statement::factory()->for($this->user)->create(['account_id' => $this->account->id, 'original_filename' => 'mine.pdf']);
    Statement::factory()->for($this->user)->create(['original_filename' => 'inbox.pdf']);
    Statement::factory()->for($this->user)->create(['account_id' => Account::factory()->for($this->user), 'original_filename' => 'elsewhere.pdf']);

    Livewire::actingAs($this->user)->test(StatementCoverage::class, ['account' => $this->account])
        ->assertSee('mine.pdf')->assertDontSee('inbox.pdf')->assertDontSee('elsewhere.pdf');
});

it('switches year and falls back for a year with nothing to show', function () {
    $component = Livewire::actingAs($this->user)->test(StatementCoverage::class, ['account' => $this->account])
        ->set('year', 2025)
        ->assertSet('year', 2025)
        ->set('year', 1900)
        ->assertSet('year', 2026);

    expect($component->instance()->years)->toContain(2025, 2026);
});

it('shows the statements tab on the account page', function () {
    Livewire::actingAs($this->user)->test(AccountShow::class, ['account' => $this->account])
        ->set('tab', 'statements')
        ->assertSeeLivewire(StatementCoverage::class);
});

it('falls back to the current year for a year in the url that has nothing to show', function (mixed $year) {
    Livewire::withQueryParams(['year' => $year])->actingAs($this->user)
        ->test(StatementCoverage::class, ['account' => $this->account])
        ->assertSet('year', 2026);
})->with([1900, 'abc', '']);
