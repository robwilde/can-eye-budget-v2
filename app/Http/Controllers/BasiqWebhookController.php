<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Basiq\CreateOrReuseRefreshLog;
use App\Enums\RefreshTrigger;
use App\Jobs\SyncTransactionsJob;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

final class BasiqWebhookController extends Controller
{
    private const array SUPPORTED_EVENTS = [
        'connection.created',
        'transactions.updated',
    ];

    public function __invoke(Request $request, CreateOrReuseRefreshLog $createOrReuseRefreshLog): Response
    {
        $eventType = $request->input('eventTypeId');
        $entityUrl = $request->input('links.eventEntity', '');

        if (! in_array($eventType, self::SUPPORTED_EVENTS, true)) {
            Log::debug('Basiq webhook received unknown event', ['eventTypeId' => $eventType]);

            return response()->noContent();
        }

        $basiqUserId = $this->extractBasiqUserId($entityUrl);

        if ($basiqUserId === null) {
            Log::warning('Basiq webhook missing user ID', ['entityUrl' => $entityUrl]);

            return response()->noContent();
        }

        $user = User::where('basiq_user_id', $basiqUserId)->first();

        if ($user === null) {
            Log::warning('Basiq webhook user not found', ['basiqUserId' => $basiqUserId]);

            return response()->noContent();
        }

        $log = $createOrReuseRefreshLog($user, RefreshTrigger::Webhook);

        SyncTransactionsJob::dispatch($user, null, $log);

        Log::info('Basiq webhook dispatched sync', [
            'eventTypeId' => $eventType,
            'userId' => $user->id,
            'refreshLogId' => $log->id,
        ]);

        return response()->noContent();
    }

    private function extractBasiqUserId(string $entityUrl): ?string
    {
        if (preg_match('#/users/([a-zA-Z0-9-]+)#', $entityUrl, $matches)) {
            return $matches[1];
        }

        return null;
    }
}
