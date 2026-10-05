<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Livewire\GmailConnection;
use App\Models\User;
use GuzzleHttp\Psr7\ServerRequest;
use GuzzleHttp\Psr7\Utils;
use Sentry\ClientBuilder;
use Sentry\Event;
use Sentry\Laravel\Features\LivewirePackageIntegration;
use Sentry\Laravel\Http\LaravelRequestFetcher;
use Sentry\SentrySdk;
use Sentry\State\Hub;
use Sentry\State\HubInterface;
use Sentry\State\Scope;

use function Sentry\captureMessage;

function sentryRequestForGmailConnect(?string $maxRequestBodySize = null): array
{
    $payload = json_encode([
        'components' => [[
            'snapshot' => json_encode(['data' => ['username' => 'me@gmail.com', 'app_password' => '']]),
            'updates' => ['app_password' => 'abcdefghijklmnop'],
            'calls' => [['method' => 'connect', 'params' => []]],
        ]],
    ]);

    $captured = [];

    config([
        'sentry.dsn' => 'https://abc123@o0.ingest.us.sentry.io/999',
        'sentry.before_send' => function (Event $event) use (&$captured): ?Event {
            $captured[] = $event;

            return null;
        },
    ]);

    if ($maxRequestBodySize !== null) {
        config(['sentry.max_request_body_size' => $maxRequestBodySize]);
    }

    app()->instance(LaravelRequestFetcher::CONTAINER_PSR7_INSTANCE_KEY, new ServerRequest('POST', 'https://app.test/livewire/update', [
        'Content-Type' => 'application/json',
        'Content-Length' => (string) mb_strlen($payload),
    ], Utils::streamFor($payload), '1.1', ['REQUEST_METHOD' => 'POST']));
    app()->forgetInstance(HubInterface::class);
    app(HubInterface::class);

    captureMessage('probe');

    SentrySdk::setCurrentHub(new Hub);

    return $captured[0]->getRequest();
}

test('a typed Gmail app password never reaches a Sentry event under the configured body policy', function () {
    $request = sentryRequestForGmailConnect();

    expect($request)->toHaveKey('url')
        ->and(json_encode($request))->not->toContain('abcdefghijklmnop');
});

test('without that policy the Gmail connect request body would carry the app password', function () {
    expect(json_encode(sentryRequestForGmailConnect('medium')))->toContain('abcdefghijklmnop');
});

function sentryEventsAfterGmailPassword(bool $livewireBreadcrumbs): string
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
        ->test(GmailConnection::class)
        ->set('app_password', 'abcdefghijklmnop')
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

test('a typed Gmail app password never reaches a Sentry breadcrumb under the configured livewire policy', function () {
    $recorded = sentryEventsAfterGmailPassword(config()->boolean('sentry.breadcrumbs.livewire'));

    expect($recorded)->toContain('probe')
        ->not->toContain('abcdefghijklmnop');
});

test('with livewire breadcrumbs enabled the Gmail component state would carry the app password', function () {
    expect(sentryEventsAfterGmailPassword(true))->toContain('abcdefghijklmnop');
});
