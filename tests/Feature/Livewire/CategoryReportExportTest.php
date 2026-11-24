<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Casts\MoneyCast;
use App\Enums\AuditOutcome;
use App\Enums\PlannedTransactionStatus;
use App\Enums\RecurrenceFrequency;
use App\Enums\TransactionDirection;
use App\Enums\TransactionStatus;
use App\Livewire\CategoryReport;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\Category;
use App\Models\PlannedTransaction;
use App\Models\Tag;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-03-15'));
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create();
    $this->groceries = Category::factory()->for($this->user)->create(['name' => 'Groceries']);
    $this->salary = Category::factory()->for($this->user)->create(['name' => 'Salary']);
});

function exportSpend(object $test, int $cents, string $date, ?Category $category = null, ?User $user = null, array $overrides = []): Transaction
{
    $user ??= $test->user;

    return Transaction::factory()->for($user)->debit()->create([
        'account_id' => $user->is($test->user) ? $test->account->id : Account::factory()->for($user)->create()->id,
        'category_id' => ($category ?? $test->groceries)->id,
        'amount' => $cents,
        'post_date' => $date,
        ...$overrides,
    ]);
}

function exportEarn(object $test, int $cents, string $date, ?Category $category = null): Transaction
{
    return Transaction::factory()->for($test->user)->credit()->create([
        'account_id' => $test->account->id,
        'category_id' => ($category ?? $test->salary)->id,
        'amount' => $cents,
        'post_date' => $date,
    ]);
}

/**
 * @return array{0: list<list<string|null>>, 1: string}
 */
function exportedCsv(object $test, array $query): array
{
    $response = Livewire::actingAs($test->user)->withQueryParams($query)->test(CategoryReport::class)->instance()->exportCsv();

    ob_start();
    $response->sendContent();
    $csv = (string) ob_get_clean();

    return [array_map(static fn (string $line): array => str_getcsv($line, escape: '\\'), preg_split('/\r?\n/', mb_trim($csv)) ?: []), (string) $response->headers->get('content-disposition')];
}

function exportMarch(): array
{
    return ['period' => 'custom', 'from' => '2026-03-01', 'to' => '2026-03-31'];
}

it('exports a monthly category breakdown with totals', function () {
    exportSpend($this, 30000, '2026-03-10');
    exportEarn($this, 400000, '2026-03-02');

    [$rows, $disposition] = exportedCsv($this, exportMarch());

    expect($rows)->toBe([
        ['Category', '2026-03', 'Total'],
        ['Income'],
        ['Salary', '4000.00', '4000.00'],
        ['Total income', '4000.00', '4000.00'],
        ['Expenses'],
        ['Groceries', '300.00', '300.00'],
        ['Total expenses', '300.00', '300.00'],
        ['Net', '3700.00', '3700.00'],
    ])->and($disposition)->toContain('report-2026-03-to-2026-03.csv');
});

it('swaps backwards csv export dates silently', function () {
    exportSpend($this, 30000, '2026-03-10');
    exportSpend($this, 11100, '2026-03-31');
    exportSpend($this, 99900, '2026-04-02');

    [$rows, $disposition] = exportedCsv($this, ['period' => 'custom', 'from' => '2026-03-31', 'to' => '2026-03-01']);

    expect($rows[0])->toBe(['Category', '2026-03', 'Total'])
        ->and($rows)->toContain(['Groceries', '411.00', '411.00'])
        ->and($disposition)->toContain('report-2026-03-to-2026-03.csv');
});

it('audits report csv exports', function () {
    exportSpend($this, 30000, '2026-03-10');

    Livewire::actingAs($this->user)
        ->withQueryParams(exportMarch())
        ->test(CategoryReport::class)
        ->call('exportCsv')
        ->assertFileDownloaded();

    $event = AuditEvent::query()->where('action', 'livewire.category-report.exportCsv')->sole();

    expect($event->user_id)->toBe($this->user->id)
        ->and($event->outcome)->toBe(AuditOutcome::Success);
});

it('exports only the user\'s own report data', function () {
    $stranger = User::factory()->create();
    exportSpend($this, 30000, '2026-03-10');
    exportSpend($this, 777700, '2026-03-11', Category::factory()->for($stranger)->create(['name' => 'Stranger Spend']), $stranger);

    [$rows] = exportedCsv($this, exportMarch());

    expect(json_encode($rows))->not->toContain('Stranger')->not->toContain('7777.00')
        ->and($rows)->toContain(['Total expenses', '300.00', '300.00']);
});

it('renders the print view with the report totals', function () {
    exportSpend($this, 30000, '2026-03-10');
    exportEarn($this, 400000, '2026-03-02');
    exportSpend($this, 888800, '2026-02-20', Category::factory()->for($this->user)->create(['name' => 'February Spend']));
    exportSpend($this, 999900, '2026-04-20', Category::factory()->for($this->user)->create(['name' => 'April Spend']));

    $summary = Livewire::actingAs($this->user)->withQueryParams(exportMarch())->test(CategoryReport::class)->instance()->summary();

    $this->actingAs($this->user)
        ->get(route('reports.print', exportMarch()))
        ->assertOk()
        ->assertSee('data-testid="print-income">'.MoneyCast::format($summary['in']).'</td>', false)
        ->assertSee('data-testid="print-expenses">'.MoneyCast::format($summary['out']).'</td>', false)
        ->assertSee('data-testid="print-net">'.MoneyCast::format($summary['net']).'</td>', false)
        ->assertSee('$4,000.00')
        ->assertSee('$300.00')
        ->assertSee('$3,700.00')
        ->assertSee('Groceries')
        ->assertSee('Salary')
        ->assertDontSee('February Spend')
        ->assertDontSee('April Spend');
});

it('keeps the print view behind login and scoped to the user', function () {
    $stranger = User::factory()->create();
    exportSpend($this, 30000, '2026-03-10');
    exportSpend($this, 777700, '2026-03-11', Category::factory()->for($stranger)->create(['name' => 'Stranger Spend']), $stranger);

    $this->get(route('reports.print', exportMarch()))->assertRedirect(route('login'));

    $this->actingAs($this->user)
        ->get(route('reports.print', exportMarch()))
        ->assertOk()
        ->assertDontSee('Stranger Spend')
        ->assertDontSee('7,777.00');
});

it('honours the filters, refund netting and totals of the page in both outputs', function () {
    $tag = Tag::factory()->for($this->user)->create(['name' => 'Holiday']);
    exportSpend($this, 60000, '2026-03-05')->tags()->attach($tag);
    exportEarn($this, 10000, '2026-03-06', $this->groceries)->tags()->attach($tag);
    exportSpend($this, 99000, '2026-03-07', Category::factory()->for($this->user)->create(['name' => 'Untagged Spend']));

    $query = [...exportMarch(), 'tag' => (string) $tag->id];
    $summary = Livewire::actingAs($this->user)->withQueryParams($query)->test(CategoryReport::class)->instance()->summary();
    [$rows] = exportedCsv($this, $query);

    expect($summary['out'])->toBe(50000)
        ->and($rows)->toContain(['Groceries', '500.00', '500.00'])
        ->and($rows)->toContain(['Total expenses', '500.00', '500.00'])
        ->and(json_encode($rows))->not->toContain('Untagged');

    $this->actingAs($this->user)
        ->get(route('reports.print', $query))
        ->assertOk()
        ->assertSee('$500.00')
        ->assertSee('Tag Holiday')
        ->assertDontSee('Untagged Spend');
});

it('lays out months as columns and rolls subcategories up to their root', function () {
    $housing = Category::factory()->for($this->user)->create(['name' => 'Housing']);
    $rent = Category::factory()->withParent($housing)->create(['name' => 'Rent']);
    exportSpend($this, 100000, '2026-02-05', $rent);
    exportSpend($this, 20000, '2026-03-05', $housing);
    Transaction::factory()->for($this->user)->debit()->create(['account_id' => $this->account->id, 'category_id' => null, 'amount' => 1050, 'post_date' => '2026-03-09']);

    [$rows] = exportedCsv($this, ['period' => 'custom', 'from' => '2026-02-01', 'to' => '2026-03-31']);

    expect($rows[0])->toBe(['Category', '2026-02', '2026-03', 'Total'])
        ->and($rows)->toContain(['Housing', '1000.00', '200.00', '1200.00'])
        ->and($rows)->toContain(['Uncategorised', '0.00', '10.50', '10.50'])
        ->and($rows)->toContain(['Total expenses', '1000.00', '210.50', '1210.50'])
        ->and($rows)->toContain(['Net', '-1000.00', '-210.50', '-1210.50']);
});

it('escapes category names that a spreadsheet would read as a formula', function () {
    exportSpend($this, 1000, '2026-03-10', Category::factory()->for($this->user)->create(['name' => '=HYPERLINK("http://x")']));

    [$rows] = exportedCsv($this, exportMarch());

    expect(collect($rows)->pluck(0)->contains(fn (?string $cell): bool => $cell !== null && str_starts_with($cell, '\'=')))->toBeTrue()
        ->and(collect($rows)->pluck(0)->contains(fn (?string $cell): bool => $cell !== null && str_starts_with($cell, '=')))->toBeFalse();
});

it('exports the plan mode report and prints it without the real-mode filters', function () {
    $tag = Tag::factory()->for($this->user)->create(['name' => 'Holiday']);
    PlannedTransaction::factory()->for($this->user)->create([
        'account_id' => $this->account->id,
        'category_id' => $this->groceries->id,
        'direction' => TransactionDirection::Debit,
        'amount' => 25000,
        'status' => PlannedTransactionStatus::Active,
        'start_date' => '2026-03-20',
        'frequency' => RecurrenceFrequency::DontRepeat,
    ]);

    [$rows] = exportedCsv($this, [...exportMarch(), 'mode' => 'plan', 'tag' => (string) $tag->id]);

    expect($rows[0])->toBe(['Category', '2026-03', 'Total'])
        ->and($rows)->toContain(['Groceries', '250.00', '250.00'])
        ->and($rows)->toContain(['Net', '-250.00', '-250.00']);

    $this->actingAs($this->user)
        ->get(route('reports.print', [...exportMarch(), 'mode' => 'plan', 'tag' => (string) $tag->id]))
        ->assertOk()
        ->assertSee('$250.00')
        ->assertDontSeeHtml('data-testid="print-filters"');
});

it('does not let the client switch the report into print mode', function () {
    expect(fn () => Livewire::actingAs($this->user)->test(CategoryReport::class)->set('print', true))->toThrow('Cannot update locked property');
});

it('links the page to the export and to the print view with its current state', function () {
    $tag = Tag::factory()->for($this->user)->create();

    Livewire::actingAs($this->user)
        ->withQueryParams([...exportMarch(), 'tag' => (string) $tag->id])
        ->test(CategoryReport::class)
        ->assertSeeHtml('data-testid="export-csv"')
        ->assertSeeHtml(e(route('reports.print', [...exportMarch(), 'mode' => 'real', 'tag' => (string) $tag->id, 'sort' => 'amount'])));
});

it('carries top-only and subcategories into the print link and shows them in the print view', function () {
    $housing = Category::factory()->for($this->user)->create(['name' => 'Housing']);
    $rent = Category::factory()->withParent($housing)->create(['name' => 'Rent']);
    exportSpend($this, 120000, '2026-03-05', $rent);

    $query = [...exportMarch(), 'topOnly' => '1', 'showSubcategories' => '1'];

    Livewire::actingAs($this->user)
        ->withQueryParams($query)
        ->test(CategoryReport::class)
        ->assertSeeHtml('wire:click="exportCsv"')
        ->assertSeeHtml(e(route('reports.print', [...exportMarch(), 'mode' => 'real', 'sort' => 'amount', 'topOnly' => 1, 'showSubcategories' => 1])));

    $this->actingAs($this->user)->get(route('reports.print', $query))->assertOk()->assertSee('Housing / Rent')->assertSeeHtml('data-testid="print-top-only"');
    $this->actingAs($this->user)->get(route('reports.print', exportMarch()))->assertOk()->assertDontSee('Housing / Rent')->assertDontSeeHtml('data-testid="print-top-only"');
});

it('ignores other users\' account and tag ids on the print route', function () {
    $stranger = User::factory()->create();
    $strangerAccount = Account::factory()->for($stranger)->create(['name' => 'Stranger Savings']);
    $strangerTag = Tag::factory()->for($stranger)->create(['name' => 'Stranger Holiday']);
    exportSpend($this, 30000, '2026-03-10');

    $this->actingAs($this->user)
        ->get(route('reports.print', [...exportMarch(), 'account' => (string) $strangerAccount->id, 'tag' => (string) $strangerTag->id]))
        ->assertOk()
        ->assertDontSee('Stranger Savings')
        ->assertDontSee('Stranger Holiday')
        ->assertDontSeeHtml('data-testid="print-filters"')
        ->assertSee('$300.00');
});

it('leaves pending rows and excluded accounts out of both outputs', function () {
    $excluded = Account::factory()->for($this->user)->excludedFromReports()->create();
    exportSpend($this, 30000, '2026-03-10');
    exportSpend($this, 55500, '2026-03-11', overrides: ['status' => TransactionStatus::Pending]);
    exportSpend($this, 77700, '2026-03-12', overrides: ['account_id' => $excluded->id]);

    [$rows] = exportedCsv($this, exportMarch());

    expect($rows)->toContain(['Total expenses', '300.00', '300.00']);

    $this->actingAs($this->user)->get(route('reports.print', exportMarch()))->assertOk()->assertDontSee('$555.00')->assertDontSee('$777.00');
});

it('keeps every csv row adding up when a refund lands in a later month', function () {
    exportSpend($this, 60000, '2026-02-10');
    exportEarn($this, 10000, '2026-03-05', $this->groceries);

    $query = ['period' => 'custom', 'from' => '2026-02-01', 'to' => '2026-03-31'];
    $summary = Livewire::actingAs($this->user)->withQueryParams($query)->test(CategoryReport::class)->instance()->summary();
    [$rows] = exportedCsv($this, $query);

    expect($summary['out'])->toBe(50000)
        ->and($rows)->toContain(['Groceries', '600.00', '-100.00', '500.00'])
        ->and($rows)->toContain(['Total income', '0.00', '0.00', '0.00'])
        ->and($rows)->toContain(['Total expenses', '600.00', '-100.00', '500.00'])
        ->and($rows)->toContain(['Net', '-600.00', '100.00', '-500.00']);
});
