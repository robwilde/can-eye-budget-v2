<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Livewire\ConnectBank;
use App\Models\User;
use Sentry\ClientBuilder;
use Sentry\Event;
use Sentry\Laravel\Features\LivewirePackageIntegration;
use Sentry\SentrySdk;
use Sentry\State\Scope;

use function Sentry\captureMessage;

const LIVEWIRE_SECRET = 'rbk_live_0123456789abcdef';

function sentryEventsAfterLivewireSecret(bool $livewireBreadcrumbs): string
{
    config(['sentry.breadcrumbs.livewire' => $livewireBreadcrumbs]);

    $captured = [];

    SentrySdk::getCurrentHub()->bindClient(ClientBuilder::create([
        'dsn' => 'https://abc123@o0.ingest.us.sentry.io/999',
        'integrations' => [new Sentry\Laravel\Integration],
        'before_send' => function (Event $event) use (&$captured): ?Event {
            $captured[] = $event;

            return null;
        },
    ])->getClient());
    SentrySdk::getCurrentHub()->configureScope(fn (Scope $scope) => $scope->clear());

    new LivewirePackageIntegration(app())->boot();

    Livewire\Livewire::actingAs(User::factory()->create())
        ->test(ConnectBank::class)
        ->set('api_key', LIVEWIRE_SECRET)
        ->call('$refresh');

    captureMessage('probe');

    return json_encode(array_map(fn (Event $event) => [
        $event->getMessage(),
        array_map(fn ($breadcrumb) => [$breadcrumb->getMessage(), $breadcrumb->getMetadata()], $event->getBreadcrumbs()),
        $event->getExtra(),
        $event->getContexts(),
        $event->getTags(),
    ], $captured), JSON_THROW_ON_ERROR);
}

test('hydrated livewire component state never reaches Sentry under the configured breadcrumb policy', function () {
    $recorded = sentryEventsAfterLivewireSecret(config()->boolean('sentry.breadcrumbs.livewire'));

    expect($recorded)->toContain('probe')
        ->not->toContain(LIVEWIRE_SECRET);
});

test('with livewire breadcrumbs enabled the same component state would carry the secret', function () {
    expect(sentryEventsAfterLivewireSecret(true))->toContain(LIVEWIRE_SECRET);
});
