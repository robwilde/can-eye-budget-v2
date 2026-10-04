<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Monolog\Handler\TestHandler;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['logging.channels.audit' => [
            'driver' => 'monolog',
            'handler' => TestHandler::class,
        ]]);
    }
}
