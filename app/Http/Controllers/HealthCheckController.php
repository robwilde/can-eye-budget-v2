<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Event;
use Throwable;

final class HealthCheckController extends Controller
{
    public function __invoke(Application $app): Response
    {
        $exception = null;

        try {
            Event::dispatch(new DiagnosingHealth);
        } catch (Throwable $e) {
            if ($app->hasDebugModeEnabled()) {
                throw $e;
            }

            report($e);

            $exception = $e->getMessage();
        }

        return response(
            view('health-up', ['exception' => $exception]),
            $exception === null ? 200 : 500,
        );
    }
}
