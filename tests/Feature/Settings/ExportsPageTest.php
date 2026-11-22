<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\DataExportStatus;
use App\Jobs\GenerateDataExportJob;
use App\Models\AuditEvent;
use App\Models\DataExport;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    Queue::fake();
    $this->user = User::factory()->create();
});

function completedExportFor(User $user): DataExport
{
    Storage::disk('local')->put('exports/'.$user->id.'/1.zip', 'zip bytes');

    return DataExport::factory()->for($user)->create(['status' => DataExportStatus::Completed, 'stored_path' => 'exports/'.$user->id.'/1.zip', 'byte_size' => 9]);
}

it('starts an export and records an audit event', function () {
    Livewire::actingAs($this->user)->test('pages::settings.exports')->call('startExport')->assertHasNoErrors();

    $export = DataExport::query()->sole();

    expect($export->status)->toBe(DataExportStatus::Pending)->and($export->user_id)->toBe($this->user->id)
        ->and(AuditEvent::query()->where('action', 'livewire.pages::settings.exports.startExport')->where('user_id', $this->user->id)->exists())->toBeTrue();
    Queue::assertPushed(GenerateDataExportJob::class, fn (GenerateDataExportJob $job): bool => $job->export->is($export));
});

it('refuses a second export while one is in progress', function () {
    DataExport::factory()->for($this->user)->create();

    Livewire::actingAs($this->user)->test('pages::settings.exports')->call('startExport')->assertHasErrors('export');

    expect(DataExport::query()->count())->toBe(1);
    Queue::assertNothingPushed();
});

it('downloads an export only for its owner', function () {
    $export = completedExportFor($this->user);

    $this->actingAs($this->user)->get(route('exports.download', $export->id))->assertOk()->assertDownload();
    $this->actingAs(User::factory()->create())->get(route('exports.download', $export->id))->assertNotFound();
    auth()->logout();
    $this->get(route('exports.download', $export->id))->assertRedirect(route('login'));
});

it('does not offer a download for an export that is not completed', function () {
    $export = DataExport::factory()->for($this->user)->create();

    $this->actingAs($this->user)->get(route('exports.download', $export->id))->assertNotFound();
});

it('marks a stuck export failed so a new one can start', function () {
    $stuck = DataExport::factory()->for($this->user)->processingSince(now()->subHours(2))->create();

    $component = Livewire::actingAs($this->user)->test('pages::settings.exports')->call('selectExport', $stuck->id)->call('markFailed')->assertHasNoErrors();

    expect($stuck->fresh()->status)->toBe(DataExportStatus::Failed);

    $component->call('startExport')->assertHasNoErrors();

    expect(DataExport::query()->where('status', DataExportStatus::Pending)->count())->toBe(1);
});

it('does not mark an export failed that has only been processing for a few minutes', function () {
    $recent = DataExport::factory()->for($this->user)->processingSince(now()->subMinutes(10))->create();

    Livewire::actingAs($this->user)->test('pages::settings.exports')->call('selectExport', $recent->id)->call('markFailed')->assertHasErrors('export');

    expect($recent->fresh()->status)->toBe(DataExportStatus::Processing);
});

it('deletes an export and its file', function () {
    $export = completedExportFor($this->user);

    Livewire::actingAs($this->user)->test('pages::settings.exports')->call('selectExport', $export->id)->call('deleteExport');

    expect(DataExport::query()->count())->toBe(0)->and(Storage::disk('local')->exists('exports/'.$this->user->id.'/1.zip'))->toBeFalse();
});

it('marks a pending export failed once it has waited over an hour', function () {
    $pending = DataExport::factory()->for($this->user)->create(['created_at' => now()->subHours(2)]);

    Livewire::actingAs($this->user)->test('pages::settings.exports')->call('selectExport', $pending->id)->call('markFailed')->assertHasNoErrors();

    expect($pending->fresh()->status)->toBe(DataExportStatus::Failed);
});

it('does not mark a freshly started pending export failed', function () {
    $pending = DataExport::factory()->for($this->user)->create();

    Livewire::actingAs($this->user)->test('pages::settings.exports')->call('selectExport', $pending->id)->call('markFailed')->assertHasErrors('export');

    expect($pending->fresh()->status)->toBe(DataExportStatus::Pending);
});

it('does not delete or fail an export that changed owner after it was selected', function () {
    $export = completedExportFor($this->user);
    $component = Livewire::actingAs($this->user)->test('pages::settings.exports')->call('selectExport', $export->id);
    $export->update(['user_id' => User::factory()->create()->id]);

    $component->call('deleteExport')->call('markFailed');

    expect(DataExport::query()->whereKey($export->id)->exists())->toBeTrue()->and(Storage::disk('local')->exists($export->stored_path))->toBeTrue();
});

it('cannot select, delete or fail another user\'s export', function () {
    $theirs = completedExportFor(User::factory()->create());

    $component = Livewire::actingAs($this->user)->test('pages::settings.exports')->call('selectExport', $theirs->id);
    $component->assertSet('selectedExportId', null)->call('deleteExport')->call('markFailed');

    expect(DataExport::query()->whereKey($theirs->id)->exists())->toBeTrue();
});

it('does not let the selected export id be set from the browser', function () {
    expect(fn () => Livewire::actingAs($this->user)->test('pages::settings.exports')->set('selectedExportId', 5))->toThrow('Cannot update locked property');
});

it('lists only the user\'s own exports', function () {
    completedExportFor($this->user);
    DataExport::factory()->create(['status' => DataExportStatus::Failed, 'failure_message' => 'someone else failure']);

    Livewire::actingAs($this->user)->test('pages::settings.exports')->assertSeeHtml('data-test="download-export-button"')->assertDontSee('someone else failure');
});
