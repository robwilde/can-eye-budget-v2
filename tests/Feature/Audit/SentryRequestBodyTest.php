<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use GuzzleHttp\Psr7\ServerRequest;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\ServerRequestInterface;
use Sentry\ClientBuilder;
use Sentry\Event;
use Sentry\Integration\RequestFetcherInterface;
use Sentry\Integration\RequestIntegration;

function sentryEventForPostedSecret(string $maxRequestBodySize): Event
{
    $payload = json_encode(['api_key' => 'rbk_live_0123456789abcdef', 'password' => 'hunter2-hunter2', 'code' => '123456']);
    $request = new ServerRequest('POST', 'https://app.test/livewire/update', [
        'Content-Type' => 'application/json',
        'Content-Length' => (string) mb_strlen($payload),
    ], Utils::streamFor($payload), '1.1', ['REQUEST_METHOD' => 'POST']);

    $fetcher = new class($request) implements RequestFetcherInterface
    {
        public function __construct(private readonly ServerRequestInterface $request) {}

        public function fetchRequest(): ServerRequestInterface
        {
            return $this->request;
        }
    };

    $integration = new RequestIntegration($fetcher);
    $client = ClientBuilder::create([
        'max_request_body_size' => $maxRequestBodySize,
        'default_integrations' => false,
        'integrations' => [$integration],
    ])->getClient();

    $event = Event::createEvent();
    new ReflectionMethod($integration, 'processEvent')->invoke($integration, $event, $client->getOptions());

    return $event;
}

test('the configured request body policy keeps posted secrets out of Sentry events', function () {
    $event = sentryEventForPostedSecret(config()->string('sentry.max_request_body_size'));

    expect($event->getRequest())->not->toBe([])
        ->and(json_encode($event->getRequest()))
        ->not->toContain('rbk_live_0123456789abcdef')
        ->not->toContain('hunter2-hunter2')
        ->not->toContain('123456');
});

test('without that policy the same request body would carry the secrets', function () {
    $event = sentryEventForPostedSecret('medium');

    expect(json_encode($event->getRequest()))->toContain('rbk_live_0123456789abcdef');
});
