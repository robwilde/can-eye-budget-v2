<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Livewire\UserRuleManager;
use App\Models\AuditEvent;
use App\Models\Category;
use App\Models\User;
use App\Models\UserRule;
use App\Models\UserRuleGroup;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->user = User::factory()->create();
});

function ruleUpload(string $fixture): UploadedFile
{
    return UploadedFile::fake()->createWithContent("{$fixture}.json", (string) file_get_contents(base_path("tests/Fixtures/Rules/{$fixture}.json")));
}

it('audits importing rule definitions', function () {
    Category::factory()->for($this->user)->create(['name' => 'Groceries', 'is_hidden' => false]);

    Livewire::actingAs($this->user)
        ->test(UserRuleManager::class)
        ->set('importFile', ruleUpload('groceries-new-condition'))
        ->call('importRules')
        ->assertSet('importErrors', []);

    $event = AuditEvent::query()->where('action', 'livewire.user-rule-manager.importRules')->sole();

    expect($event->user_id)->toBe($this->user->id)
        ->and(UserRule::where('user_id', $this->user->id)->count())->toBe(1);
});

it('audits exporting rule definitions', function () {
    Livewire::actingAs($this->user)
        ->test(UserRuleManager::class)
        ->call('exportRules')
        ->assertFileDownloaded();

    expect(AuditEvent::query()->where('action', 'livewire.user-rule-manager.exportRules')->count())->toBe(1);
});

it('downloads only the signed-in user rules as json', function () {
    UserRuleGroup::factory()->for($this->user)->create(['name' => 'Mine']);
    UserRuleGroup::factory()->for(User::factory()->create())->create(['name' => 'Theirs']);

    $component = Livewire::actingAs($this->user)->test(UserRuleManager::class)->call('exportRules');

    $component->assertFileDownloaded('rules-'.now()->format('Y-m-d').'.json', null, 'application/json');
    $content = (string) $component->effects['download']['content'] ?? '';

    expect(base64_decode($content, true))->toContain('"Mine"')->not->toContain('Theirs');
});

it('shows the reasons and creates nothing when an uploaded file fails validation', function () {
    Livewire::actingAs($this->user)
        ->test(UserRuleManager::class)
        ->set('importFile', ruleUpload('no-actions'))
        ->call('importRules')
        ->assertSee('Nothing was imported')
        ->assertSet('importSummary', null);

    expect(UserRule::count())->toBe(0)->and(UserRuleGroup::count())->toBe(0);
});

it('reports the rules it skipped after an upload', function () {
    Livewire::actingAs($this->user)
        ->test(UserRuleManager::class)
        ->set('importFile', ruleUpload('unresolved-category'))
        ->call('importRules')
        ->assertSee("category 'Nowhere / Nothing' was not found")
        ->assertSee('1 skipped');
});

it('refuses an upload that is not a small json file', function () {
    Livewire::actingAs($this->user)
        ->test(UserRuleManager::class)
        ->set('importFile', UploadedFile::fake()->create('rules.exe', 10))
        ->call('importRules')
        ->assertHasErrors('importFile');

    Livewire::actingAs($this->user)
        ->test(UserRuleManager::class)
        ->set('importFile', UploadedFile::fake()->create('rules.json', 2048))
        ->call('importRules')
        ->assertHasErrors('importFile');

    expect(UserRule::count())->toBe(0);
});

it('requires a file to import', function () {
    Livewire::actingAs($this->user)
        ->test(UserRuleManager::class)
        ->call('importRules')
        ->assertHasErrors('importFile');
});
