<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\SyncTransactionsJob;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class BasiqCallbackController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $sessionState = session()->pull('basiq_consent_state');

        if (! $sessionState || $request->query('state') !== $sessionState) {
            return to_route('dashboard')->with('error', 'Invalid session. Please try connecting your bank again.');
        }

        if ($request->query('result') === 'cancelled') {
            return to_route('dashboard')->with('info', 'Bank connection was cancelled.');
        }

        $jobId = $request->query('jobId');

        if (! is_string($jobId) || $jobId === '') {
            return to_route('dashboard')->with('error', 'Bank connection failed. Please try again.');
        }

        SyncTransactionsJob::dispatch($request->user(), $jobId);

        return to_route('dashboard')->with('success', 'Bank connected successfully. Your transactions are syncing.');
    }
}
