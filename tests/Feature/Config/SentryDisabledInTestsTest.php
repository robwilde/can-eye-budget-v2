<?php

declare(strict_types=1);

use Sentry\SentrySdk;

/*
 * bootstrap/app.php routes every reported exception to Sentry (#433). The suite throws
 * exceptions deliberately — tests/Feature/HealthCheckTest.php mocks Redis and the database
 * into failing — so if the SDK holds a usable DSN while the suite runs, those fabricated
 * failures are transmitted to the real Sentry project and read as production incidents.
 * That happened: three `environment: testing` events landed there before #439.
 *
 * phpunit.xml pins SENTRY_DSN blank to prevent it. This asserts the outcome that matters —
 * the client has no destination — rather than the phpunit.xml line, so the guarantee still
 * holds if the DSN is neutralised some other way, and still fails if the line is dropped.
 */

test('the sentry client has no dsn while the suite runs', function (): void {
    expect(config('sentry.dsn'))->toBeEmpty();

    $client = SentrySdk::getCurrentHub()->getClient();

    if ($client !== null) {
        expect($client->getOptions()->getDsn())->toBeNull();
    }
});
